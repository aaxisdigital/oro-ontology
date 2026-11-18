<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunIndex;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FlowRunMaintenanceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ConfigManager&MockObject $config;
    private FlowRunIndex&MockObject $runs;
    private BucketFlowEventStore&MockObject $bucket;
    private LoggerInterface&MockObject $logger;
    private FlowRunMaintenance $maintenance;

    /** @var array<string, mixed> the stored config values */
    private array $values = [];

    /** @var list<array{string, mixed}> config writes, in order */
    private array $writes = [];

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($this->connection);
        $this->config = $this->createMock(ConfigManager::class);
        $this->config->method('get')->willReturnCallback(fn (string $key) => $this->values[$key] ?? null);
        $this->config->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->values[$key] = $value;
            $this->writes[] = [$key, $value];
        });
        $this->config->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });
        $this->runs = $this->createMock(FlowRunIndex::class);
        $this->bucket = $this->createMock(BucketFlowEventStore::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->maintenance = new FlowRunMaintenance($doctrine, $this->config, $this->runs, $this->bucket, $this->logger);
    }

    /**
     * @dataProvider historyDays
     */
    public function testTheHistoryWindowDefaultsTo30Days(mixed $configured, int $expected): void
    {
        $this->values['aaxis_ontology.flow_execution_history_days'] = $configured;

        self::assertSame($expected, $this->maintenance->historyDays());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function historyDays(): array
    {
        return ['set' => [7, 7], 'numeric string' => ['45', 45], 'unset' => [null, 30], 'zero' => [0, 30], 'negative' => [-3, 30]];
    }

    // --- rebuild --------------------------------------------------------------------------------

    public function testRebuildingTheTableBackendRecordsTheOutcome(): void
    {
        $this->bucket->method('isEnabled')->willReturn(false);
        $this->runs->expects(self::once())->method('rebuildFromTable')->willReturn(42);
        $this->runs->expects(self::never())->method('rebuildFromBucket');

        self::assertSame(['storage' => 'db', 'runs' => 42, 'days' => null], $this->maintenance->rebuild());

        $last = $this->maintenance->lastRebuild();
        self::assertSame(['storage' => 'db', 'runs' => 42, 'days' => null], array_diff_key($last, ['at' => 0]));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $last['at']);
        self::assertSame(1, $this->flushes);
    }

    /**
     * @dataProvider bucketDays
     */
    public function testRebuildingTheBucketBackendCoversTheRequestedOrConfiguredDays(?int $requested, int $expected): void
    {
        $this->values['aaxis_ontology.flow_execution_history_days'] = 14;
        $this->bucket->method('isEnabled')->willReturn(true);
        $this->runs->expects(self::once())->method('rebuildFromBucket')->with($this->bucket, $expected)->willReturn(5);

        self::assertSame(['storage' => 'bucket', 'runs' => 5, 'days' => $expected], $this->maintenance->rebuild($requested));
    }

    /**
     * @return array<string, array{int|null, int}>
     */
    public static function bucketDays(): array
    {
        return ['configured' => [null, 14], 'requested' => [90, 90], 'invalid request' => [0, 14]];
    }

    public function testARebuildClearsAnOutOfSyncFlagRaisedBeforeItBegan(): void
    {
        $this->values[FlowRunMaintenance::STALE_SINCE] = '2020-01-01 00:00:00.000000';
        $this->bucket->method('isEnabled')->willReturn(false);

        $this->maintenance->rebuild();

        self::assertNull($this->maintenance->staleSince());
    }

    public function testARebuildKeepsAFlagRaisedWhileItRan(): void
    {
        $this->values[FlowRunMaintenance::STALE_SINCE] = '2999-01-01 00:00:00.000000';
        $this->bucket->method('isEnabled')->willReturn(false);

        $this->maintenance->rebuild();

        self::assertSame('2999-01-01 00:00:00.000000', $this->maintenance->staleSince());
    }

    public function testAFailedRebuildRecordsNothing(): void
    {
        $this->bucket->method('isEnabled')->willReturn(false);
        $this->runs->method('rebuildFromTable')->willThrowException(new \RuntimeException('down'));

        try {
            $this->maintenance->rebuild();
            self::fail('The failure must surface.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->writes);
    }

    // --- out-of-sync flag -----------------------------------------------------------------------

    public function testOnlyTheFirstIndexFailureRaisesTheFlagAndLogs(): void
    {
        $failure = new \RuntimeException('deadlock');
        $this->logger->expects(self::once())->method('error')
            ->with(self::stringContains('out of sync'), ['exception' => $failure, 'flow_uuid' => 'u1']);

        $this->maintenance->markStale($failure, 'u1');
        $this->maintenance->markStale(new \RuntimeException('again'), 'u2');

        self::assertCount(1, $this->writes);
        self::assertSame(FlowRunMaintenance::STALE_SINCE, $this->writes[0][0]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) $this->maintenance->staleSince());
        self::assertSame(1, $this->flushes);
    }

    /**
     * @dataProvider unreadableRebuilds
     */
    public function testAnUnreadableLastRebuildReadsAsNever(mixed $stored): void
    {
        $this->values[FlowRunMaintenance::LAST_REBUILD] = $stored;

        self::assertNull($this->maintenance->lastRebuild());
        self::assertNull($this->maintenance->staleSince());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableRebuilds(): array
    {
        return ['unset' => [null], 'empty' => [''], 'not json' => ['{'], 'incomplete' => ['{"at":"x"}']];
    }

    // --- retention ------------------------------------------------------------------------------

    public function testThePurgeCleansEveryStoreOfWhatIsOlderThanTheWindow(): void
    {
        $this->values['aaxis_ontology.flow_execution_history_days'] = 10;
        $now = new \DateTimeImmutable('2026-03-20 12:00:00.250000', new \DateTimeZone('Europe/Paris'));
        $sizes = [FlowRunIndex::PURGE_BATCH, 7];
        $this->connection->expects(self::exactly(2))->method('executeStatement')
            ->with(self::stringContains('DELETE FROM aaxis_ontology_flow_events WHERE id IN (SELECT id FROM aaxis_ontology_flow_events WHERE datetime < :cutoff LIMIT 10000)'), ['cutoff' => '2026-03-10 11:00:00.250000'])
            ->willReturnCallback(static function () use (&$sizes): int {
                return array_shift($sizes);
            });
        $this->runs->expects(self::once())->method('purgeBefore')->with('2026-03-10 11:00:00.250000')->willReturn(3);
        $this->bucket->method('isConfigured')->willReturn(true);
        $this->bucket->expects(self::once())->method('purgeBefore')
            ->with(self::callback(static fn (\DateTimeImmutable $cutoff): bool => $cutoff->format('Y-m-d H:i:s e') === '2026-03-10 11:00:00 UTC'))
            ->willReturn(9);

        self::assertSame(
            ['cutoff' => '2026-03-10 11:00:00.250000', 'events' => FlowRunIndex::PURGE_BATCH + 7, 'runs' => 3, 'bucketConfigured' => true, 'bucketObjects' => 9],
            $this->maintenance->purge($now)
        );

        // The outcome is recorded for the Settings page's "last cleanup" line.
        $last = $this->maintenance->lastPurge();
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $last['at']);
        unset($last['at']);
        self::assertSame(
            ['days' => 10, 'cutoff' => '2026-03-10 11:00:00.250000', 'events' => FlowRunIndex::PURGE_BATCH + 7, 'runs' => 3, 'bucketConfigured' => true, 'bucketObjects' => 9],
            $last
        );
        self::assertSame(1, $this->flushes);
    }

    public function testAPurgeIsRecordedEvenWhenAStoreFailed(): void
    {
        $this->connection->method('executeStatement')->willThrowException(new \RuntimeException('locked'));
        $this->bucket->method('isConfigured')->willReturn(false);

        $this->maintenance->purge();

        self::assertSame([null, 0, false, null], [
            $this->maintenance->lastPurge()['events'], $this->maintenance->lastPurge()['runs'],
            $this->maintenance->lastPurge()['bucketConfigured'], $this->maintenance->lastPurge()['bucketObjects'],
        ]);
    }

    public function testFailingToRecordThePurgeIsLoggedAndTheReportStillReturned(): void
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willThrowException(new \RuntimeException('config down'));
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($this->connection);
        $this->connection->method('executeStatement')->willReturn(0);
        $this->bucket->method('isConfigured')->willReturn(false);
        $this->logger->expects(self::once())->method('error')
            ->with('Flow execution history purge failed for the last purge record.');

        $report = (new FlowRunMaintenance($doctrine, $config, $this->runs, $this->bucket, $this->logger))->purge();

        self::assertSame(0, $report['events']);
    }

    /**
     * @dataProvider unreadablePurges
     */
    public function testAnUnreadableLastPurgeReadsAsNever(mixed $stored): void
    {
        $this->values[FlowRunMaintenance::LAST_PURGE] = $stored;

        self::assertNull($this->maintenance->lastPurge());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadablePurges(): array
    {
        return ['unset' => [null], 'empty' => [''], 'not json' => ['{'], 'incomplete' => ['{"at":"x"}']];
    }

    public function testWithoutABucketOnlyTheDatabaseIsPurged(): void
    {
        $this->connection->method('executeStatement')->willReturn(0);
        $this->bucket->method('isConfigured')->willReturn(false);
        $this->bucket->expects(self::never())->method('purgeBefore');

        $report = $this->maintenance->purge();

        self::assertSame([0, 0, false, null], [$report['events'], $report['runs'], $report['bucketConfigured'], $report['bucketObjects']]);
    }

    public function testOneFailingStoreIsLoggedAndTheOthersStillPurge(): void
    {
        $this->connection->method('executeStatement')->willThrowException(new \RuntimeException('locked'));
        $this->runs->method('purgeBefore')->willReturn(2);
        $this->bucket->method('isConfigured')->willReturn(true);
        $this->bucket->method('purgeBefore')->willThrowException(new \RuntimeException('bucket down'));
        $this->logger->expects(self::exactly(2))->method('error')
            ->with(self::logicalOr(
                'Flow execution history purge failed for the flow events table.',
                'Flow execution history purge failed for the bucket flow events.'
            ));

        $report = $this->maintenance->purge();

        self::assertSame([null, 2, true, null], [$report['events'], $report['runs'], $report['bucketConfigured'], $report['bucketObjects']]);
    }
}
