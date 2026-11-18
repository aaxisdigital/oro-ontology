<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyAttributeReconciler;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityReader;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityWriter;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InMemoryBucketClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OntologyDataApiManagerTest extends TestCase
{
    private const string UUID = '123e4567-e89b-42d3-a456-426614174000';

    /** @var array<string, mixed> */
    private array $config = [
        'aaxis_ontology.api_auto_create' => false,
        'aaxis_ontology.api_auto_create_unique_attribute' => 'id',
        'aaxis_ontology.api_query_max_page_size' => 200,
    ];

    /** @var array<class-string, callable(array<string, mixed>): ?object> */
    private array $repositories = [];

    /** @var array<int, object> */
    private array $persisted = [];

    private int $flushes = 0;

    private ManagerRegistry&MockObject $doctrine;
    private MessageProducerInterface&MockObject $producer;
    private OntologyAttributeReconciler&MockObject $reconciler;
    private OntologyFlowEventEmitter&MockObject $events;
    private OroEntityReader&MockObject $oroReader;
    private OroEntityWriter&MockObject $oroWriter;
    private Connection&MockObject $connection;
    private InMemoryBucketClient $bucket;
    private OntologyDataApiManager $manager;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->flushes = 0;

        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->doctrine->method('getRepository')->willReturnCallback(function (string $class): ObjectRepository {
            $repository = $this->createMock(ObjectRepository::class);
            $repository->method('findOneBy')->willReturnCallback(
                fn (array $criteria): ?object => isset($this->repositories[$class]) ? ($this->repositories[$class])($criteria) : null
            );

            return $repository;
        });
        $objectManager = $this->createMock(ObjectManager::class);
        $objectManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });
        $objectManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $this->doctrine->method('getManagerForClass')->willReturn($objectManager);
        $this->connection = $this->createMock(Connection::class);
        $this->doctrine->method('getConnection')->willReturn($this->connection);

        $this->producer = $this->createMock(MessageProducerInterface::class);
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);
        $this->reconciler = $this->createMock(OntologyAttributeReconciler::class);
        $this->events = $this->createMock(OntologyFlowEventEmitter::class);
        $this->oroReader = $this->createMock(OroEntityReader::class);
        $this->oroWriter = $this->createMock(OroEntityWriter::class);
        $this->bucket = new InMemoryBucketClient();
        $this->bucket->enabled = false;

        $this->manager = new OntologyDataApiManager(
            $this->doctrine,
            $this->producer,
            $configManager,
            $this->reconciler,
            $this->events,
            $this->oroReader,
            $this->oroWriter,
            new BucketEntityDataStore($this->bucket)
        );
    }

    // --- fixtures -----------------------------------------------------------------------------

    private function system(string $name = 'ERP', bool $external = true, bool $enabled = true): OntologySystem
    {
        return (new OntologySystem())->setName($name)->setExternal($external)->setEnabled($enabled);
    }

    private static function withId(object $object, int $id): object
    {
        (new \ReflectionProperty($object::class, 'id'))->setValue($object, $id);

        return $object;
    }

    /**
     * Wires system + entity lookups so resolveEntity() finds them.
     */
    private function world(bool $external = true, bool $forceDb = false, string $unique = 'id'): OntologyEntity
    {
        $system = $this->system('ERP', $external);
        $entity = (new OntologyEntity())->setSystem($system)->setName('Order')->setUniqueAttribute($unique)->setForceDbStorage($forceDb);
        self::withId($entity, 12);
        $this->repositories[OntologySystem::class] = static fn (array $criteria): ?object => $criteria['name'] === 'ERP' ? $system : null;
        $this->repositories[OntologyEntity::class] = static fn (array $criteria): ?object => $criteria['name'] === 'Order' && $criteria['system'] === $system ? $entity : null;

        return $entity;
    }

    private function flow(bool $enabled = true): OntologyFlow
    {
        $flow = (new OntologyFlow())->setName(OntologyFlow::NAME_REST_API)->setEnabled($enabled);

        return self::withId($flow, 3);
    }

    private function assertApiError(callable $call, string $code, int $status, ?string $message = null): void
    {
        try {
            $call();
            self::fail('an OntologyApiException was expected');
        } catch (OntologyApiException $e) {
            self::assertSame($code, $e->getErrorCode(), $e->getMessage());
            self::assertSame($status, $e->getStatusCode());
            if ($message !== null) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    // --- entity resolution --------------------------------------------------------------------

    public function testAnUnknownSystemIsA404(): void
    {
        $this->assertApiError(fn () => $this->manager->read('Nope', 'Order', '1'), 'unknown_system', 404, 'Unknown system "Nope".');
    }

    public function testADisabledSystemIsA409(): void
    {
        $this->repositories[OntologySystem::class] = fn (): object => $this->system('ERP', true, false);

        $this->assertApiError(fn () => $this->manager->read('ERP', 'Order', '1'), 'disabled_system', 409, 'System "ERP" is disabled.');
    }

    public function testAnUnknownEntityIsA404(): void
    {
        $this->world();

        $this->assertApiError(
            fn () => $this->manager->read('ERP', 'Nope', '1'),
            'unknown_entity',
            404,
            'Unknown entity "Nope" for system "ERP".'
        );
    }

    public function testADisabledEntityIsA409(): void
    {
        $this->world()->setEnabled(false);

        $this->assertApiError(
            fn () => $this->manager->read('ERP', 'Order', '1'),
            'disabled_entity',
            409,
            'Entity "Order" of system "ERP" is disabled.'
        );
    }

    public function testNamesAreTrimmedBeforeLookup(): void
    {
        $this->world();
        $this->repositories[OntologyData::class] = static fn (): object => (new OntologyData())->setPayload(['id' => '1']);

        self::assertSame(['id' => '1'], $this->manager->read(' ERP ', ' Order ', '1'));
    }

    // --- read ---------------------------------------------------------------------------------

    public function testReadingFromTheDatabaseReturnsThePayload(): void
    {
        $entity = $this->world();
        $lookup = new \stdClass();
        $this->repositories[OntologyData::class] = function (array $criteria) use ($lookup): object {
            $lookup->criteria = $criteria;

            return (new OntologyData())->setPayload(['id' => 'SO-1', 'qty' => 2]);
        };

        $expectedCriteria = ['entity' => $entity, 'uniqueId' => 'SO-1'];
        self::assertSame(['id' => 'SO-1', 'qty' => 2], $this->manager->read('ERP', 'Order', 'SO-1'));
        self::assertSame($expectedCriteria, $lookup->criteria);
    }

    public function testReadingAMissingDatabaseRecordIsA404(): void
    {
        $this->world();

        $this->assertApiError(fn () => $this->manager->read('ERP', 'Order', 'X'), 'record_not_found', 404, 'No record found for unique id "X".');
    }

    public function testReadingAnEmptyPayloadReturnsNull(): void
    {
        $this->world();
        $this->repositories[OntologyData::class] = static fn (): object => new OntologyData();

        self::assertNull($this->manager->read('ERP', 'Order', 'X'));
    }

    public function testInternalSystemsReadFromTheOroEntity(): void
    {
        $entity = $this->world(false);
        $this->oroReader->expects(self::once())->method('readById')->with($entity, 'A-1')->willReturn(['sku' => 'A-1']);

        self::assertSame(['sku' => 'A-1'], $this->manager->read('ERP', 'Order', 'A-1'));
    }

    public function testAMissingInternalRecordIsA404(): void
    {
        $this->world(false);
        $this->oroReader->method('readById')->willReturn(null);

        $this->assertApiError(fn () => $this->manager->read('ERP', 'Order', 'A-1'), 'record_not_found', 404);
    }

    public function testBucketBackedEntitiesReadTheLatestEnvelope(): void
    {
        $this->world();
        $this->bucket->enabled = true;
        $this->bucket->objects['aaxis-ontology/entity-data/12/SO-1.json'] = json_encode(['uniqueId' => 'SO-1', 'payload' => ['qty' => 5]]);

        self::assertSame(['qty' => 5], $this->manager->read('ERP', 'Order', 'SO-1'));
        $this->assertApiError(fn () => $this->manager->read('ERP', 'Order', 'nope'), 'record_not_found', 404);
    }

    public function testForcedDatabaseStorageBypassesTheBucket(): void
    {
        $this->world(true, true);
        $this->bucket->enabled = true;
        $this->repositories[OntologyData::class] = static fn (): object => (new OntologyData())->setPayload(['from' => 'db']);

        self::assertSame(['from' => 'db'], $this->manager->read('ERP', 'Order', '1'));
        self::assertSame([], $this->bucket->calls);
    }

    // --- flow gating --------------------------------------------------------------------------

    public function testRequireEnabledFlow(): void
    {
        $flow = $this->flow();
        $this->repositories[OntologyFlow::class] = static fn (array $criteria): ?object => $criteria === ['name' => 'Ontology REST API'] ? $flow : null;

        self::assertSame($flow, $this->manager->requireEnabledFlow('Ontology REST API'));
        $this->assertApiError(
            fn () => $this->manager->requireEnabledFlow('Ghost'),
            'flow_misconfigured',
            500,
            'The "Ghost" flow is not configured.'
        );

        $flow->setEnabled(false);
        $this->assertApiError(
            fn () => $this->manager->requireEnabledFlow('Ontology REST API'),
            'flow_disabled',
            409,
            'The "Ontology REST API" flow is disabled.'
        );
    }

    // --- upsert (queued) ----------------------------------------------------------------------

    public function testUpsertQueuesOneMessageForTheWholeBatch(): void
    {
        $entity = $this->world();
        $sent = [];
        $this->producer->expects(self::once())->method('send')->willReturnCallback(function (string $topic, array $message) use (&$sent): void {
            $sent = [$topic, $message];
        });
        $this->reconciler->expects(self::exactly(2))->method('assertValid');
        $this->reconciler->expects(self::once())->method('syncFromRecords')->with($entity, [['id' => 'A', 'q' => 1], ['id' => 7]]);

        $uuid = $this->manager->upsert('ERP', 'Order', [['id' => 'A', 'q' => 1], ['id' => 7]], $this->flow());

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        [$topic, $message] = $sent;
        self::assertSame(OntologyDataUpsertTopic::getName(), $topic);
        self::assertSame(3, $message['flow_id']);
        self::assertSame($uuid, $message['uuid']);
        self::assertSame(12, $message['entity_id']);
        self::assertSame(['A', '7'], $message['unique_id'], 'ids are strings, parallel to the payloads');
        self::assertSame([['id' => 'A', 'q' => 1], ['id' => 7]], $message['payload']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $message['updated_at']);
    }

    public function testUpsertRecordsUsesTheGivenUuid(): void
    {
        $entity = $this->world();
        $this->producer->expects(self::once())->method('send');

        self::assertSame(strtoupper(self::UUID), $this->manager->upsertRecords($entity, [['id' => 'A']], $this->flow(), strtoupper(self::UUID)));
    }

    public function testAMalformedBatchUuidIsRejectedBeforeAnythingIsQueued(): void
    {
        $entity = $this->world();
        $this->producer->expects(self::never())->method('send');

        $this->assertApiError(
            fn () => $this->manager->upsertRecords($entity, [['id' => 'A']], $this->flow(), 'not-a-uuid'),
            'invalid_payload',
            400,
            '"not-a-uuid" is not a valid batch uuid.'
        );
    }

    public function testTheQueuedPathRefusesInternalSystems(): void
    {
        $entity = $this->world(false);

        $this->assertApiError(
            fn () => $this->manager->upsertRecords($entity, [['id' => 'A']], $this->flow()),
            'invalid_payload',
            400
        );
    }

    /**
     * @dataProvider invalidBatchProvider
     *
     * @param array<int, mixed> $records
     */
    public function testBatchValidation(array $records, string $unique, string $message): void
    {
        $entity = $this->world(true, false, $unique);
        $this->producer->expects(self::never())->method('send');
        $this->reconciler->expects(self::never())->method('syncFromRecords');

        $this->assertApiError(fn () => $this->manager->upsertRecords($entity, $records, $this->flow()), 'invalid_payload', 400, $message);
    }

    /**
     * @return array<string, array{array<int, mixed>, string, string}>
     */
    public static function invalidBatchProvider(): array
    {
        return [
            'no records' => [[], 'id', 'At least one record is required.'],
            'no unique attribute configured' => [[['id' => 1]], '', 'Entity "Order" has no unique attribute configured.'],
            'a record that is not an object' => [[['id' => 1], 'text'], 'id', 'Record #2 must be a JSON object.'],
            'unique id missing' => [[['x' => 1]], 'id', 'Record #1 is missing a non-empty value for the unique attribute "id".'],
            'unique id null' => [[['id' => null]], 'id', 'Record #1 is missing a non-empty value for the unique attribute "id".'],
            'unique id empty' => [[['id' => 'ok'], ['id' => '']], 'id', 'Record #2 is missing a non-empty value for the unique attribute "id".'],
            'unique id not scalar' => [[['id' => ['a']]], 'id', 'Record #1 is missing a non-empty value for the unique attribute "id".'],
            'duplicate unique id' => [
                [['id' => 'A'], ['id' => 'B'], ['id' => 'A']],
                'id',
                'Record #3 duplicates the unique attribute value "A" of record #1 — unique ids cannot repeat in one operation.',
            ],
            'numeric and string ids collide' => [
                [['id' => 5], ['id' => '5']],
                'id',
                'Record #2 duplicates the unique attribute value "5" of record #1 — unique ids cannot repeat in one operation.',
            ],
        ];
    }

    public function testTheAttributeContractIsEnforcedPerRecordBeforeQueueing(): void
    {
        $entity = $this->world();
        $this->reconciler->method('assertValid')->willThrowException(OntologyApiException::invalidPayload('Missing required attribute "qty".'));
        $this->producer->expects(self::never())->method('send');

        $this->assertApiError(
            fn () => $this->manager->upsertRecords($entity, [['id' => 'A']], $this->flow()),
            'invalid_payload',
            400,
            'Missing required attribute "qty".'
        );
    }

    // --- auto-create --------------------------------------------------------------------------

    public function testAutoCreateBuildsTheMissingSystemAndEntityFromConfiguration(): void
    {
        $this->config['aaxis_ontology.api_auto_create'] = true;
        $this->config['aaxis_ontology.api_auto_create_unique_attribute'] = 'sku';
        $this->producer->expects(self::once())->method('send');

        $this->manager->upsert(' NewSys ', ' NewEntity ', [['sku' => 'A']], $this->flow());

        self::assertCount(2, $this->persisted);
        [$system, $entity] = $this->persisted;
        self::assertInstanceOf(OntologySystem::class, $system);
        self::assertSame('NewSys', $system->getName());
        self::assertTrue($system->isEnabled());
        self::assertInstanceOf(OntologyEntity::class, $entity);
        self::assertSame('NewEntity', $entity->getName());
        self::assertSame('sku', $entity->getUniqueAttribute());
        self::assertSame($system, $entity->getSystem());
        self::assertSame(2, $this->flushes);
    }

    public function testAutoCreateFallsBackToIdAndReusesAnExistingSystem(): void
    {
        $this->config['aaxis_ontology.api_auto_create'] = true;
        $this->config['aaxis_ontology.api_auto_create_unique_attribute'] = '';
        $system = $this->system();
        $this->repositories[OntologySystem::class] = static fn (): object => $system;
        $this->producer->expects(self::once())->method('send');

        $this->manager->upsert('ERP', 'Fresh', [['id' => 'A']], $this->flow());

        self::assertCount(1, $this->persisted, 'only the entity is created');
        self::assertSame('id', $this->persisted[0]->getUniqueAttribute());
    }

    public function testAutoCreateStillRefusesDisabledSystems(): void
    {
        $this->config['aaxis_ontology.api_auto_create'] = true;
        $this->repositories[OntologySystem::class] = fn (): object => $this->system('ERP', true, false);

        $this->assertApiError(fn () => $this->manager->upsert('ERP', 'X', [['id' => 1]], $this->flow()), 'disabled_system', 409);
    }

    // --- upsertRecordsSync --------------------------------------------------------------------

    public function testSyncUpsertOfAnInternalEntityDelegatesToTheWriterWithoutSyncingAttributes(): void
    {
        $entity = $this->world(false, false, 'sku');
        $this->oroWriter->expects(self::once())->method('update')
            ->with($entity, ['A', 'B'], [['sku' => 'A', 'n' => 1], ['sku' => 'B']])
            ->willReturn(['A']);
        $this->reconciler->expects(self::exactly(2))->method('assertValid');
        $this->reconciler->expects(self::never())->method('syncFromRecords');
        $flow = $this->flow();
        $this->events->expects(self::once())->method('emit')->with(
            $flow,
            self::UUID,
            'data-upsert',
            ['entity' => 'Order', 'uniqueIds' => ['A', 'B'], 'changedIds' => ['A']]
        );

        $result = $this->manager->upsertRecordsSync($entity, [['sku' => 'A', 'n' => 1], ['sku' => 'B']], $flow, self::UUID);

        self::assertSame(['uuid' => self::UUID, 'seen' => ['A', 'B'], 'changed' => ['A']], $result);
    }

    public function testSyncUpsertWithoutEventRecordingEmitsNothing(): void
    {
        $entity = $this->world(false, false, 'sku');
        $this->oroWriter->method('update')->willReturn(['A']);
        $this->events->expects(self::never())->method('emit');

        $result = $this->manager->upsertRecordsSync($entity, [['sku' => 'A']], $this->flow(), self::UUID, false);

        self::assertSame(['A'], $result['changed']);
    }

    public function testSyncUpsertOfABucketBackedEntityWritesTheBucket(): void
    {
        $entity = $this->world();
        $this->bucket->enabled = true;
        $flow = $this->flow();
        $this->reconciler->expects(self::once())->method('syncFromRecords');
        $this->events->expects(self::once())->method('emit')->with(
            $flow,
            $this->anything(),
            'data-upsert',
            ['entity' => 'Order', 'uniqueIds' => ['A'], 'changedIds' => ['A']]
        );

        $result = $this->manager->upsertRecordsSync($entity, [['id' => 'A', 'q' => 1]], $flow);

        self::assertSame(['A'], $result['changed']);
        self::assertArrayHasKey('aaxis-ontology/entity-data/12/A.json', $this->bucket->objects);
        self::assertSame(1, json_decode($this->bucket->objects['aaxis-ontology/entity-data/12/A.json'], true)['version']);
    }

    public function testSyncUpsertOfADatabaseEntityCallsThePostgresFunction(): void
    {
        $entity = $this->world();
        $flow = $this->flow();
        $input = null;
        $this->connection->expects(self::once())->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$input): string {
            self::assertSame('SELECT aaxis_ontology_data_upsert(CAST(? AS jsonb))', $sql);
            $input = json_decode($params[0], true);

            return json_encode(['payload' => ['A' => ['q' => [1, 2]], 'B' => null]]);
        });
        $this->events->expects(self::once())->method('emit')->with(
            $flow,
            self::UUID,
            'data-upsert',
            ['entity' => 'Order', 'uniqueIds' => ['A', 'B'], 'changedIds' => ['A']]
        );

        $result = $this->manager->upsertRecordsSync($entity, [['id' => 'A', 'q' => 2], ['id' => 'B']], $flow, self::UUID);

        self::assertSame(['uuid' => self::UUID, 'seen' => ['A', 'B'], 'changed' => ['A']], $result);
        self::assertSame(3, $input['flow_id']);
        self::assertSame(self::UUID, $input['uuid']);
        self::assertSame(12, $input['entity_id']);
        self::assertSame(['A', 'B'], $input['unique_id']);
        self::assertSame([['id' => 'A', 'q' => 2], ['id' => 'B']], $input['payload']);
    }

    public function testARejectedBatchThrowsAndRecordsNoEvent(): void
    {
        $entity = $this->world();
        $this->connection->method('fetchOne')->willReturn(json_encode(['payload' => ['errors' => ['first problem', 'second problem']]]));
        $this->events->expects(self::never())->method('emit');

        $this->assertApiError(
            fn () => $this->manager->upsertRecordsSync($entity, [['id' => 'A']], $this->flow()),
            'invalid_payload',
            400,
            'first problem; second problem'
        );
    }

    public function testAnUnreadableFunctionResponseCountsAsNothingChanged(): void
    {
        $entity = $this->world();
        $this->connection->method('fetchOne')->willReturn(false);

        $result = $this->manager->upsertRecordsSync($entity, [['id' => 'A']], $this->flow());

        self::assertSame([], $result['changed']);
    }

    public function testAnUnencodableBatchFailsBeforeAnyDatabaseCall(): void
    {
        $entity = $this->world();
        $this->connection->expects(self::never())->method('fetchOne');
        $this->events->expects(self::never())->method('emit');

        $this->expectException(\JsonException::class);
        $this->manager->upsertRecordsSync($entity, [['id' => 'A', 'bad' => NAN]], $this->flow());
    }

    // --- parseUpsertResponse ------------------------------------------------------------------

    /**
     * @dataProvider responseProvider
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $expected
     */
    public function testParseUpsertResponse(array $response, array $expected): void
    {
        self::assertSame($expected, OntologyDataApiManager::parseUpsertResponse($response));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function responseProvider(): array
    {
        return [
            'empty' => [[], ['changed' => [], 'errors' => null]],
            'successes keyed by unique id' => [
                ['payload' => ['A' => ['q' => 1], 'B' => null, 'C' => []]],
                ['changed' => ['A', 'C'], 'errors' => null],
            ],
            'numeric-looking ids are strings' => [['payload' => [101 => ['x' => 1], 102 => null]], ['changed' => ['101'], 'errors' => null]],
            'a rejected batch' => [['payload' => ['errors' => ['a', 'b']]], ['changed' => [], 'errors' => ['a', 'b']]],
            'a record whose id is "errors" is a success' => [['payload' => ['errors' => ['q' => 1]]], ['changed' => ['errors'], 'errors' => null]],
            'errors next to other keys are ids' => [['payload' => ['errors' => ['a'], 'B' => ['x' => 1]]], ['changed' => ['errors', 'B'], 'errors' => null]],
            'an empty errors list is not a rejection' => [['payload' => ['errors' => []]], ['changed' => ['errors'], 'errors' => null]],
            'a payload that is not an array' => [['payload' => 'oops'], ['changed' => [], 'errors' => null]],
        ];
    }

    // --- query: database path -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $expectedParams
     */
    private function expectDatabaseQuery(string $sql, array $expectedParams, array $rows): void
    {
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with($sql, $expectedParams, ['entity_id' => ParameterType::INTEGER])
            ->willReturn($rows);
    }

    public function testQueryDefaultsToIdOrderAndDecodesPayloads(): void
    {
        $this->world();
        $this->expectDatabaseQuery(
            'SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id ORDER BY id ASC LIMIT 50 OFFSET 0',
            ['entity_id' => 12],
            [['payload' => '{"a":1}'], ['payload' => null], ['payload' => ''], ['payload' => '"scalar"'], ['payload' => '{broken']]
        );

        $items = $this->manager->query('ERP', 'Order', [], null, 1, 50);

        self::assertSame([['a' => 1], null, null, null, null], $items);
    }

    /**
     * @dataProvider pagingProvider
     */
    public function testQueryPaging(int $page, int $pageSize, int $configuredMax, string $limitClause): void
    {
        $this->world();
        $this->config['aaxis_ontology.api_query_max_page_size'] = $configuredMax;
        $this->expectDatabaseQuery(
            'SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id ORDER BY id ASC ' . $limitClause,
            ['entity_id' => 12],
            []
        );

        $this->manager->query('ERP', 'Order', [], null, $page, $pageSize);
    }

    /**
     * @return array<string, array{int, int, int, string}>
     */
    public static function pagingProvider(): array
    {
        return [
            'second page' => [2, 25, 200, 'LIMIT 25 OFFSET 25'],
            'page size is capped by configuration' => [3, 1000, 200, 'LIMIT 200 OFFSET 400'],
            'a zero max still allows one row' => [1, 50, 0, 'LIMIT 1 OFFSET 0'],
            'page and size are floored at one' => [-4, -9, 200, 'LIMIT 1 OFFSET 0'],
        ];
    }

    public function testQueryFiltersAreParameterised(): void
    {
        $this->world();
        $this->expectDatabaseQuery(
            'SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id'
            . ' AND payload->>:attr0 = :val0'
            . ' AND payload->>:attr1 LIKE :val1'
            . " AND (payload->>:attr2 ~ '^-?[0-9]+(\\.[0-9]+)?$' AND (payload->>:attr2)::numeric > CAST(:val2 AS numeric))"
            . ' AND payload->>:attr3 < :val3'
            . ' AND payload->>:attr4 = :val4'
            . ' ORDER BY payload->>:orderAttr DESC LIMIT 10 OFFSET 0',
            [
                'entity_id' => 12,
                'attr0' => 'status', 'val0' => 'open',
                'attr1' => 'name', 'val1' => 'A%',
                'attr2' => 'qty', 'val2' => '5',
                'attr3' => 'code', 'val3' => 'abc',
                'attr4' => 'flag', 'val4' => '',
                'orderAttr' => 'created',
            ],
            []
        );

        $this->manager->query('ERP', 'Order', [
            ['attribute' => ' status ', 'value' => 'open'],
            ['attribute' => 'name', 'compare' => 'LIKE', 'value' => 'A%'],
            ['attribute' => 'qty', 'compare' => '>', 'value' => 5],
            ['attribute' => 'code', 'compare' => '<', 'value' => 'abc'],
            ['attribute' => 'flag', 'compare' => 'EQ'],
        ], 'created  desc', 1, 10);
    }

    /**
     * @dataProvider invalidQueryProvider
     *
     * @param array<int, mixed> $filters
     */
    public function testInvalidQueriesAreRejectedOnEveryBackend(array $filters, ?string $orderBy, string $message, bool $bucket): void
    {
        $this->world();
        $this->bucket->enabled = $bucket;
        $this->connection->expects(self::never())->method('fetchAllAssociative');

        $this->assertApiError(fn () => $this->manager->query('ERP', 'Order', $filters, $orderBy, 1, 10), 'invalid_query', 400, $message);
        self::assertSame([], $this->bucket->calls, 'the query is validated before the bucket is listed');
    }

    /**
     * @return array<string, array{array<int, mixed>, string|null, string, bool}>
     */
    public static function invalidQueryProvider(): array
    {
        $cases = [
            'filter without attribute' => [[['compare' => 'EQ']], null, 'Each filter requires a non-empty "attribute".'],
            'filter with a blank attribute' => [[['attribute' => '  ']], null, 'Each filter requires a non-empty "attribute".'],
            'filter that is not an object' => [['nope'], null, 'Each filter requires a non-empty "attribute".'],
            'unknown comparator' => [[['attribute' => 'a', 'compare' => '!=']], null, 'Unsupported filter compare "!=".'],
            'non-scalar value' => [[['attribute' => 'a', 'value' => ['x']]], null, 'Filter "value" must be a scalar.'],
            'bad direction' => [[], 'name SIDEWAYS', 'Unsupported orderBy direction "SIDEWAYS".'],
        ];
        $out = [];
        foreach ($cases as $name => [$filters, $orderBy, $message]) {
            $out['database: ' . $name] = [$filters, $orderBy, $message, false];
            $out['bucket: ' . $name] = [$filters, $orderBy, $message, true];
        }

        return $out;
    }

    public function testAnOrderByWithoutAnAttributeNameIsRejectedOnTheDatabasePath(): void
    {
        $this->world();

        // Only reachable with a leading separator that trims away — an all-blank string is "no order".
        $this->connection->method('fetchAllAssociative')->willReturn([]);
        self::assertSame([], $this->manager->query('ERP', 'Order', [], '   ', 1, 10));
    }

    // --- query: bucket path -------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function seedBucketRecords(array $records): OntologyEntity
    {
        $entity = $this->world();
        $this->bucket->enabled = true;
        foreach ($records as $record) {
            $this->bucket->objects['aaxis-ontology/entity-data/12/' . rawurlencode((string) $record['id']) . '.json'] = json_encode([
                'uniqueId' => (string) $record['id'], 'version' => 1, 'payload' => $record,
            ]);
        }

        return $entity;
    }

    /**
     * @param array<int, mixed> $filters
     *
     * @return array<int, string>
     */
    private function bucketIds(array $filters, ?string $orderBy = null, int $page = 1, int $size = 50): array
    {
        return array_map(
            static fn (?array $payload): string => (string) $payload['id'],
            $this->manager->query('ERP', 'Order', $filters, $orderBy, $page, $size)
        );
    }

    public function testBucketQueryFiltersByTheTextFormOfTheValue(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'status' => 'open', 'qty' => 5, 'flag' => true, 'tags' => ['x'], 'note' => null],
            ['id' => 'b', 'status' => 'closed', 'qty' => 12, 'flag' => false, 'tags' => ['y', 'z']],
            ['id' => 'c', 'status' => 'Open', 'qty' => '7.5', 'flag' => true],
        ]);

        self::assertSame(['a'], $this->bucketIds([['attribute' => 'status', 'value' => 'open']]));
        self::assertSame(['a', 'c'], $this->bucketIds([['attribute' => 'flag', 'value' => 'true']]));
        self::assertSame(['b'], $this->bucketIds([['attribute' => 'tags', 'value' => '["y","z"]']]));
        self::assertSame(['a'], $this->bucketIds([['attribute' => 'qty', 'compare' => 'EQ', 'value' => 5]]));
        self::assertSame([], $this->bucketIds([['attribute' => 'note', 'value' => '']]), 'a null never matches');
        self::assertSame([], $this->bucketIds([['attribute' => 'missing', 'value' => 'x']]), 'an absent attribute never matches');
        self::assertSame(['a'], $this->bucketIds([['attribute' => 'status', 'value' => 'open'], ['attribute' => 'qty', 'value' => '5']]), 'filters AND together');
    }

    public function testBucketQueryLikeSupportsWildcardsAndEscapesTheRest(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'name' => 'Alpha'],
            ['id' => 'b', 'name' => 'Alpine'],
            ['id' => 'c', 'name' => 'beta.x'],
            ['id' => 'd', 'name' => "multi\nline"],
            ['id' => 'e', 'name' => '100% (net)'],
        ]);
        $like = fn (string $pattern): array => $this->bucketIds([['attribute' => 'name', 'compare' => 'LIKE', 'value' => $pattern]]);

        self::assertSame(['a', 'b'], $like('Al%'));
        self::assertSame(['a'], $like('Alph_'));
        self::assertSame(['c'], $like('beta.x'), 'a dot is a literal dot');
        self::assertSame(['c'], $like('beta_x'), '_ matches exactly one character (here the dot)');
        self::assertSame([], $like('bet_x'), '_ is exactly one character, not zero and not two');
        self::assertSame(['c'], $like('bet%'), '% spans any run');
        self::assertSame(['d'], $like('multi%line'), '% also spans a line break');
        self::assertSame(['e'], $like('100% (net)'), 'regex metacharacters such as parentheses are literal');
        self::assertSame([], $like('lph'), 'the pattern is anchored at both ends');
    }

    public function testBucketQueryComparesNumericallyWhenBothSidesAreNumericAndAsTextOtherwise(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'qty' => 5],
            ['id' => 'b', 'qty' => 12],
            ['id' => 'c', 'qty' => '7.5'],
            ['id' => 'e', 'qty' => -3],
        ]);

        self::assertSame(['b', 'c'], $this->bucketIds([['attribute' => 'qty', 'compare' => '>', 'value' => 5]]));
        self::assertSame(['a', 'e'], $this->bucketIds([['attribute' => 'qty', 'compare' => '<', 'value' => 7]]));
        self::assertSame(['e'], $this->bucketIds([['attribute' => 'qty', 'compare' => '<', 'value' => '0']]));
        // A non-numeric bound compares as text: "12" < "5" < "7.5" < "9x", and "-3" sorts before digits.
        self::assertSame(['a', 'b', 'c', 'e'], $this->bucketIds([['attribute' => 'qty', 'compare' => '<', 'value' => '9x']]));
        self::assertSame([], $this->bucketIds([['attribute' => 'qty', 'compare' => '>', 'value' => '9x']]));
    }

    public function testBucketQueryComparesTextValuesLexically(): void
    {
        $this->seedBucketRecords([['id' => 'a', 'code' => 'apple'], ['id' => 'b', 'code' => 'mango'], ['id' => 'c', 'code' => 'zebra']]);

        self::assertSame(['a'], $this->bucketIds([['attribute' => 'code', 'compare' => '<', 'value' => 'm']]));
        self::assertSame(['b', 'c'], $this->bucketIds([['attribute' => 'code', 'compare' => '>', 'value' => 'm']]));
    }

    public function testBucketQueryOrdersByTheTextOfTheAttributeWithNullsLastAscending(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'name' => 'pear'],
            ['id' => 'b', 'name' => 'apple'],
            ['id' => 'c'],
            ['id' => 'd', 'name' => 'fig'],
        ]);

        self::assertSame(['b', 'd', 'a', 'c'], $this->bucketIds([], 'name'));
        self::assertSame(['b', 'd', 'a', 'c'], $this->bucketIds([], ' name ASC '));
        self::assertSame(['c', 'a', 'd', 'b'], $this->bucketIds([], 'name desc'));
    }

    public function testBucketQueryOrdersNumbersAsText(): void
    {
        $this->seedBucketRecords([['id' => 'a', 'n' => 10], ['id' => 'b', 'n' => 9], ['id' => 'c', 'n' => 100]]);

        // Same as payload->>attr on the SQL side: "10" < "100" < "9".
        self::assertSame(['a', 'c', 'b'], $this->bucketIds([], 'n'));
    }

    public function testBucketQueryPagesTheFilteredResult(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'k' => 1], ['id' => 'b', 'k' => 1], ['id' => 'c', 'k' => 1], ['id' => 'd', 'k' => 2],
        ]);

        self::assertSame(['a', 'b'], $this->bucketIds([['attribute' => 'k', 'value' => '1']], 'id', 1, 2));
        self::assertSame(['c'], $this->bucketIds([['attribute' => 'k', 'value' => '1']], 'id', 2, 2));
        self::assertSame([], $this->bucketIds([['attribute' => 'k', 'value' => '1']], 'id', 3, 2));
    }

    // --- queryForFlow -------------------------------------------------------------------------

    public function testQueryForFlowOnAnInternalEntityReadsFromOro(): void
    {
        $entity = $this->world(false);
        $this->oroReader->expects(self::once())->method('readAll')->with($entity, 'sku', 'DESC', 5)->willReturn([['sku' => 'x']]);

        self::assertSame([['sku' => 'x']], $this->manager->queryForFlow('ERP', 'Order', 'sku', 'DESC', 5));
    }

    public function testQueryForFlowOnTheDatabaseIsNotPagedAndOrdersByJsonb(): void
    {
        $this->world();
        $calls = [];
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params, array $types) use (&$calls): array {
            $calls[] = [$sql, $params, $types];

            return [['payload' => '{"a":1}']];
        });

        self::assertSame([['a' => 1]], $this->manager->queryForFlow('ERP', 'Order'));
        self::assertSame([['a' => 1]], $this->manager->queryForFlow('ERP', 'Order', ' qty ', 'desc', 3));
        self::assertSame([['a' => 1]], $this->manager->queryForFlow('ERP', 'Order', 'qty', 'weird', 0));

        self::assertSame(['SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id ORDER BY id ASC', ['entity_id' => 12], ['entity_id' => ParameterType::INTEGER]], $calls[0]);
        self::assertSame(
            ['SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id ORDER BY payload -> :orderAttr DESC, id ASC LIMIT 3', ['entity_id' => 12, 'orderAttr' => 'qty'], ['entity_id' => ParameterType::INTEGER]],
            $calls[1]
        );
        self::assertSame(
            'SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id ORDER BY payload -> :orderAttr ASC, id ASC',
            $calls[2][0],
            'an unknown direction is ASC and a zero limit is no limit'
        );
    }

    public function testQueryForFlowOnTheBucketReturnsEveryRecordOrderedByJsonComparison(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'n' => 10, 'w' => 'pear', 'f' => 2.5],
            ['id' => 'b', 'n' => 9, 'w' => 'apple', 'f' => 10],
            ['id' => 'c', 'n' => '100', 'w' => 'fig', 'f' => '3'],
        ]);
        $ids = fn (?string $orderBy = null, string $direction = 'ASC', ?int $limit = null): array => array_column(
            $this->manager->queryForFlow('ERP', 'Order', $orderBy, $direction, $limit),
            'id'
        );

        self::assertSame(['a', 'b', 'c'], $ids(), 'no order = storage (key) order');
        // Numbers compare numerically (10 > 9, not "10" < "9"), a numeric string against a number by value.
        self::assertSame(['b', 'a', 'c'], $ids('n'));
        self::assertSame(['c', 'a', 'b'], $ids('n', 'DESC'));
        self::assertSame(['b', 'c', 'a'], $ids('w'), 'strings compare lexically');
        self::assertSame(['a', 'c', 'b'], $ids(' f '), 'floats, ints and numeric strings mix by value');
        self::assertSame(['c', 'a'], $ids('n', 'desc', 2));
        self::assertSame(['a', 'b'], $ids(null, 'ASC', 2));
        self::assertSame(['a', 'b', 'c'], $ids(null, 'ASC', 0));
    }

    public function testQueryForFlowOnTheBucketToleratesRecordsMissingTheOrderAttribute(): void
    {
        $this->seedBucketRecords([['id' => 'a', 'n' => 1], ['id' => 'b'], ['id' => 'c', 'n' => 2]]);

        $rows = $this->manager->queryForFlow('ERP', 'Order', 'n');

        self::assertEqualsCanonicalizing(['a', 'b', 'c'], array_column($rows, 'id'));
    }

    // --- queryForFlowByAttribute --------------------------------------------------------------

    public function testQueryForFlowByAttributeOnAnInternalEntityReadsFromOro(): void
    {
        $entity = $this->world(false);
        $this->oroReader->expects(self::once())->method('readByAttribute')->with($entity, 'sku', 'A')->willReturn([['sku' => 'A']]);

        self::assertSame([['sku' => 'A']], $this->manager->queryForFlowByAttribute('ERP', 'Order', 'sku', 'A'));
    }

    public function testQueryForFlowByAttributeOnTheDatabase(): void
    {
        $this->world();
        $this->connection->expects(self::once())->method('fetchAllAssociative')->with(
            'SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id AND payload ->> :attr = :value ORDER BY id ASC',
            ['entity_id' => 12, 'attr' => 'status', 'value' => 'open'],
            ['entity_id' => ParameterType::INTEGER]
        )->willReturn([['payload' => '{"status":"open"}'], ['payload' => null]]);

        self::assertSame([['status' => 'open'], null], $this->manager->queryForFlowByAttribute('ERP', 'Order', 'status', 'open'));
    }

    public function testQueryForFlowByAttributeOnTheBucketComparesTheTextForm(): void
    {
        $this->seedBucketRecords([
            ['id' => 'a', 'flag' => true], ['id' => 'b', 'flag' => false], ['id' => 'c', 'flag' => 'true'], ['id' => 'd'],
        ]);

        $rows = $this->manager->queryForFlowByAttribute('ERP', 'Order', 'flag', 'true');

        self::assertSame(['a', 'c'], array_column($rows, 'id'));
    }

    // --- uuid ---------------------------------------------------------------------------------

    public function testGenerateUuidMintsDistinctVersionFourUuids(): void
    {
        $a = $this->manager->generateUuid();
        $b = $this->manager->generateUuid();

        self::assertNotSame($a, $b);
        foreach ([$a, $b] as $uuid) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        }
    }
}
