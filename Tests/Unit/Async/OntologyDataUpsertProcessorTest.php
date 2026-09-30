<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async;

use Aaxis\Bundle\OntologyBundle\Async\OntologyDataUpsertProcessor;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InMemoryBucketClient;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OntologyDataUpsertProcessorTest extends TestCase
{
    private const string UUID = '123e4567-e89b-42d3-a456-426614174000';

    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private OntologyFlowEventEmitter&MockObject $events;
    private InMemoryBucketClient $bucketClient;
    private OntologyDataUpsertProcessor $processor;
    private ?OntologyEntity $entity = null;
    private ?OntologyFlow $flow = null;

    /** @var array<int, array{?int, ?string, ?string, string, array<string, mixed>}> */
    private array $emitted = [];

    protected function setUp(): void
    {
        $this->emitted = [];
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->events = $this->createMock(OntologyFlowEventEmitter::class);
        $this->events->method('emitRaw')->willReturnCallback(function (?int $flowId, ?string $flowName, ?string $uuid, string $event, array $payload = []): void {
            $this->emitted[] = [$flowId, $flowName, $uuid, $event, $payload];
        });
        $this->bucketClient = new InMemoryBucketClient();
        $this->bucketClient->enabled = false;

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($this->connection);
        $doctrine->method('getRepository')->willReturnCallback(function (string $class): ObjectRepository {
            $repository = $this->createMock(ObjectRepository::class);
            $repository->method('find')->willReturnCallback(fn (mixed $id): ?object => match ($class) {
                OntologyEntity::class => $this->entity,
                OntologyFlow::class => $this->flow,
                default => null,
            });

            return $repository;
        });

        $this->processor = new OntologyDataUpsertProcessor($doctrine, $this->logger, $this->events, new BucketEntityDataStore($this->bucketClient));
    }

    private function process(mixed $body): string
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn($body);

        return $this->processor->process($message, $this->createMock(SessionInterface::class));
    }

    private function entity(bool $external = true, bool $forceDb = false): OntologyEntity
    {
        $entity = (new OntologyEntity())
            ->setSystem((new OntologySystem())->setName('ERP')->setExternal($external))
            ->setName('Order')
            ->setUniqueAttribute('id')
            ->setForceDbStorage($forceDb);
        (new \ReflectionProperty(OntologyEntity::class, 'id'))->setValue($entity, 12);

        return $this->entity = $entity;
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function body(array $override = []): array
    {
        return $override + [
            'flow_id' => 3,
            'uuid' => self::UUID,
            'entity_id' => 12,
            'unique_id' => ['A', 'B'],
            'updated_at' => '2026-03-04T05:06:07+00:00',
            'payload' => [['id' => 'A'], ['id' => 'B']],
        ];
    }

    public function testItSubscribesToTheDataUpsertTopic(): void
    {
        self::assertSame([OntologyDataUpsertTopic::getName()], OntologyDataUpsertProcessor::getSubscribedTopics());
    }

    public function testAMessageWhoseBodyIsNotAnArrayIsRejected(): void
    {
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_data_upsert: message body is not an array.');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process('nope'));
        self::assertSame([], $this->emitted);
    }

    /**
     * @dataProvider malformedMessageProvider
     *
     * @param array<string, mixed> $override
     */
    public function testAMalformedMessageIsRejectedAsAFlowException(array $override, string $reason, ?int $flowId): void
    {
        $this->entity();
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process($this->body($override)));

        self::assertCount(1, $this->emitted);
        self::assertSame([$flowId, null, null, 'flow-exception', ['message' => $reason]], $this->emitted[0]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, int|null}> the flow id that was already read when it failed
     */
    public static function malformedMessageProvider(): array
    {
        return [
            'flow id not numeric' => [['flow_id' => 'abc'], 'flow_id must be numeric, got string', null],
            'entity id not numeric' => [['entity_id' => ['x']], 'entity_id must be numeric, got array', 3],
            'uuid not a string' => [['uuid' => 55], 'uuid must be a string, got int', 3],
        ];
    }

    public function testADatabaseSuccessRecordsADataUpsertEventWithTheChangedIds(): void
    {
        $this->entity();
        $this->flow = (new OntologyFlow())->setName('Ontology REST API');
        $call = new \stdClass();
        $this->connection->expects(self::once())->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use ($call): string {
            self::assertSame('SELECT aaxis_ontology_data_upsert(CAST(? AS jsonb))', $sql);
            $call->sent = json_decode($params[0], true);

            return json_encode(['payload' => ['A' => ['x' => 1], 'B' => null]]);
        });

        self::assertSame(MessageProcessorInterface::ACK, $this->process($this->body()));

        $expectedEvent = [[3, 'Ontology REST API', self::UUID, 'data-upsert', ['entity' => 'Order', 'uniqueIds' => ['A', 'B'], 'changedIds' => ['A']]]];
        $expectedCall = ['flow_id' => 3, 'uuid' => self::UUID, 'entity_id' => 12, 'unique_id' => ['A', 'B'], 'updated_at' => '2026-03-04T05:06:07+00:00', 'payload' => [['id' => 'A'], ['id' => 'B']]];
        self::assertSame($expectedEvent, $this->emitted);
        self::assertSame($expectedCall, $call->sent);
    }

    public function testThePostgresFunctionReceivesDefaultsForMissingFields(): void
    {
        $call = new \stdClass();
        $this->connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use ($call): string {
            $call->sent = json_decode($params[0], true);

            return 'not json';
        });

        self::assertSame(MessageProcessorInterface::ACK, $this->process([]));

        $expectedCall = ['flow_id' => null, 'uuid' => null, 'entity_id' => null, 'unique_id' => [], 'updated_at' => null, 'payload' => []];
        $expectedEvent = [[null, null, null, 'data-upsert', ['entity' => '', 'uniqueIds' => [], 'changedIds' => []]]];
        self::assertSame($expectedCall, $call->sent);
        self::assertSame($expectedEvent, $this->emitted);
    }

    public function testARejectedBatchIsLoggedAndSurfacesAsAFlowException(): void
    {
        $this->entity();
        $this->connection->method('fetchOne')->willReturn(json_encode(['payload' => ['errors' => ['record 1 is invalid', 'record 2 is invalid']]]));
        $this->logger->expects(self::once())->method('error')->with('{"errors":["record 1 is invalid","record 2 is invalid"]}');

        // Acknowledged: retrying a rejected batch would only reject it again.
        self::assertSame(MessageProcessorInterface::ACK, $this->process($this->body()));

        self::assertSame([[3, null, self::UUID, 'flow-exception', ['message' => 'record 1 is invalid; record 2 is invalid']]], $this->emitted);
    }

    public function testACrashIsALoggedFlowExceptionAndTheMessageIsRejected(): void
    {
        $this->entity();
        $failure = new \RuntimeException('connection lost');
        $this->connection->method('fetchOne')->willThrowException($failure);
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_data_upsert: failed to process message.', ['exception' => $failure]);

        self::assertSame(MessageProcessorInterface::REJECT, $this->process($this->body()));

        self::assertSame([[3, null, self::UUID, 'flow-exception', ['message' => 'connection lost']]], $this->emitted);
    }

    // --- bucket arm ---------------------------------------------------------------------------

    private function bucketOn(bool $external = true, bool $forceDb = false): void
    {
        $this->entity($external, $forceDb);
        $this->bucketClient->enabled = true;
    }

    public function testABucketBackedEntityIsWrittenToTheBucket(): void
    {
        $this->bucketOn();
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertSame(MessageProcessorInterface::ACK, $this->process($this->body()));

        self::assertSame(['A', 'B'], $this->emitted[0][4]['changedIds']);
        $stored = json_decode($this->bucketClient->objects['aaxis-ontology/entity-data/12/A.json'], true);
        self::assertSame(1, $stored['version']);
        self::assertSame(self::UUID, $stored['uuid']);
        self::assertSame('2026-03-04T05:06:07+00:00', $stored['updatedAt']);
    }

    public function testAnUnparseableTimestampFallsBackToNow(): void
    {
        $this->bucketOn();

        $this->process($this->body(['updated_at' => 'not a date at all', 'unique_id' => ['A'], 'payload' => [['id' => 'A']]]));

        $stored = json_decode($this->bucketClient->objects['aaxis-ontology/entity-data/12/A.json'], true);
        self::assertGreaterThanOrEqual(time() - 5, strtotime($stored['updatedAt']));
    }

    /**
     * @dataProvider databaseOnlyEntityProvider
     */
    public function testInternalAndForcedDatabaseEntitiesNeverUseTheBucket(bool $external, bool $forceDb): void
    {
        $this->bucketOn($external, $forceDb);
        $this->connection->expects(self::once())->method('fetchOne')->willReturn('{}');

        self::assertSame(MessageProcessorInterface::ACK, $this->process($this->body()));

        self::assertSame([], $this->bucketClient->objects);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function databaseOnlyEntityProvider(): array
    {
        return ['internal system' => [false, false], 'forced database storage' => [true, true]];
    }

    /**
     * @dataProvider invalidBucketBatchProvider
     *
     * @param array<string, mixed> $override
     */
    public function testTheBucketArmRejectsABrokenBatchLikeThePostgresFunctionWould(array $override, string $error): void
    {
        $this->bucketOn();
        $this->logger->expects(self::once())->method('error')->with((string) json_encode(['errors' => [$error]]));

        self::assertSame(MessageProcessorInterface::ACK, $this->process($this->body($override)));

        self::assertSame('flow-exception', $this->emitted[0][3]);
        self::assertSame(['message' => $error], $this->emitted[0][4]);
        self::assertSame([], $this->bucketClient->objects, 'nothing may be written for a rejected batch');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBucketBatchProvider(): array
    {
        return [
            'malformed uuid' => [['uuid' => 'nope'], 'invalid uuid'],
            'sizes differ' => [['unique_id' => ['A'], 'payload' => [['id' => 'A'], ['id' => 'B']]], 'mismatch between unique_id and payload sizes'],
            'unique ids not a list' => [['unique_id' => 'A'], 'mismatch between unique_id and payload sizes'],
            'payloads not a list' => [['payload' => 'x'], 'mismatch between unique_id and payload sizes'],
            'empty id' => [['unique_id' => ['A', ' '], 'payload' => [['id' => 'A'], ['id' => 'B']]], 'unique_id value cannot be empty/null'],
            'duplicate ids' => [['unique_id' => ['A', ' A'], 'payload' => [['id' => 'A'], ['id' => 'A']]], 'cannot received duplicated unique_ids in a single operation'],
            'a payload that is not an object' => [['payload' => [['id' => 'A'], 'text']], 'invalid payload format'],
        ];
    }
}
