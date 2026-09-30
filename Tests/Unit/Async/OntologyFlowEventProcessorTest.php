<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async;

use Aaxis\Bundle\OntologyBundle\Async\OntologyFlowEventProcessor;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowEventTopic;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OntologyFlowEventProcessorTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private BucketFlowEventStore&MockObject $bucket;
    private OntologyFlowEventProcessor $processor;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($this->connection);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->bucket = $this->createMock(BucketFlowEventStore::class);
        $this->bucket->method('isEnabled')->willReturn(false);
        $this->processor = new OntologyFlowEventProcessor($doctrine, $this->logger, $this->bucket);
    }

    private function process(mixed $body): string
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn($body);

        return $this->processor->process($message, $this->createMock(SessionInterface::class));
    }

    public function testItSubscribesToTheFlowEventTopic(): void
    {
        self::assertSame([OntologyFlowEventTopic::getName()], OntologyFlowEventProcessor::getSubscribedTopics());
    }

    public function testAMessageWhoseBodyIsNotAnArrayIsRejected(): void
    {
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_flow_event: message body is not an array.');
        $this->connection->expects(self::never())->method('executeStatement');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process('garbage'));
    }

    /**
     * @dataProvider unknownEventProvider
     */
    public function testAnUnknownEventKindIsRejected(array $body, mixed $loggedEvent): void
    {
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_flow_event: unknown event kind.', ['event' => $loggedEvent]);
        $this->connection->expects(self::never())->method('executeStatement');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process($body));
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed}>
     */
    public static function unknownEventProvider(): array
    {
        return [
            'missing' => [[], null],
            'not a string' => [['event' => 7], 7],
            'not in the catalogue' => [['event' => 'flow-explode'], 'flow-explode'],
        ];
    }

    public function testItInsertsTheEventRow(): void
    {
        $call = new \stdClass();
        $this->connection->expects(self::once())->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use ($call): int {
            self::assertStringContainsString('INSERT INTO aaxis_ontology_flow_events', $sql);
            self::assertStringContainsString('CAST(? AS jsonb)', $sql);
            $call->args = $params;

            return 1;
        });

        $result = $this->process([
            'flow_id' => '5',
            'flow_uuid' => 'uuid-1',
            'flow_name' => 'Nightly',
            'event' => 'flow-start',
            'datetime' => '2026-03-04 05:06:07.123456',
            'payload' => ['trigger' => 'cron', 'ü' => 1],
        ]);

        self::assertSame(MessageProcessorInterface::ACK, $result);
        $expectedRow = [5, 'uuid-1', 'Nightly', 'flow-start', '2026-03-04 05:06:07.123456', '{"trigger":"cron","\u00fc":1}'];
        self::assertSame($expectedRow, $call->args);
    }

    public function testMissingOrInvalidFieldsBecomeNullsAndDefaults(): void
    {
        $args = null;
        $this->connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$args): int {
            $args = $params;

            return 1;
        });

        $this->process(['event' => 'step', 'flow_id' => 'abc', 'flow_uuid' => 7, 'flow_name' => ['x'], 'datetime' => 20260304, 'payload' => 'not an array']);

        self::assertNull($args[0], 'a non-numeric flow id');
        self::assertNull($args[1], 'a non-string uuid');
        self::assertNull($args[2], 'a non-string flow name');
        self::assertSame('step', $args[3]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $args[4], 'the timestamp is minted when absent or unusable');
        self::assertSame('[]', $args[5]);
    }

    public function testAnAbsentFlowIdStaysNull(): void
    {
        $args = null;
        $this->connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$args): int {
            $args = $params;

            return 1;
        });

        $this->process(['event' => 'log-message']);

        self::assertNull($args[0]);
    }

    public function testTheFlowNameIsCutTo128Characters(): void
    {
        $args = null;
        $this->connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$args): int {
            $args = $params;

            return 1;
        });

        $this->process(['event' => 'flow-start', 'flow_name' => str_repeat('é', 200)]);

        self::assertSame(str_repeat('é', 128), $args[2]);
    }

    public function testAnInsertFailureIsLoggedAndRejectedNeverRetried(): void
    {
        $failure = new \RuntimeException('relation "aaxis_ontology_flow_events" does not exist');
        $this->connection->method('executeStatement')->willThrowException($failure);
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_flow_event: failed to store the event.', ['exception' => $failure]);

        self::assertSame(MessageProcessorInterface::REJECT, $this->process(['event' => 'flow-start']));
    }

    public function testWithTheBucketToggleOnTheEventGoesToTheBucketInstead(): void
    {
        $bucket = $this->createMock(BucketFlowEventStore::class);
        $bucket->method('isEnabled')->willReturn(true);
        $bucket->expects(self::once())->method('append')->with(
            5,
            'Nightly',
            'uuid-1',
            'flow-finish',
            '2026-03-04 05:06:07.000001',
            ['ok' => true]
        );
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getConnection');
        $processor = new OntologyFlowEventProcessor($doctrine, $this->logger, $bucket);
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn([
            'flow_id' => 5, 'flow_name' => 'Nightly', 'flow_uuid' => 'uuid-1', 'event' => 'flow-finish',
            'datetime' => '2026-03-04 05:06:07.000001', 'payload' => ['ok' => true],
        ]);

        self::assertSame(MessageProcessorInterface::ACK, $processor->process($message, $this->createMock(SessionInterface::class)));
    }

    public function testTheBucketArmNormalisesMissingFieldsToo(): void
    {
        $captured = null;
        $bucket = $this->createMock(BucketFlowEventStore::class);
        $bucket->method('isEnabled')->willReturn(true);
        $bucket->method('append')->willReturnCallback(function (?int $flowId, ?string $flowName, ?string $uuid, string $event, string $datetime, array $payload) use (&$captured): void {
            $captured = [$flowId, $flowName, $uuid, $event, $datetime, $payload];
        });
        $processor = new OntologyFlowEventProcessor($this->createMock(ManagerRegistry::class), $this->logger, $bucket);
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn(['event' => 'step', 'flow_id' => 'x', 'payload' => 5]);

        $processor->process($message, $this->createMock(SessionInterface::class));

        self::assertSame([null, null, null, 'step'], \array_slice($captured, 0, 4));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $captured[4]);
        self::assertSame([], $captured[5]);
    }

    public function testABucketFailureIsRejectedToo(): void
    {
        $bucket = $this->createMock(BucketFlowEventStore::class);
        $bucket->method('isEnabled')->willReturn(true);
        $bucket->method('append')->willThrowException(new \RuntimeException('bucket down'));
        $this->logger->expects(self::once())->method('error');
        $processor = new OntologyFlowEventProcessor($this->createMock(ManagerRegistry::class), $this->logger, $bucket);
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn(['event' => 'step']);

        self::assertSame(MessageProcessorInterface::REJECT, $processor->process($message, $this->createMock(SessionInterface::class)));
    }
}
