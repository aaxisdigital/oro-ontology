<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunIndex;
use Aaxis\Bundle\OntologyBundle\Migrations\Schema\FlowRunsTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The SQL itself (folding, backfill) was validated against PostgreSQL on real event data —
 * replaying every event in random order through the fold equals the backfill, which equals the
 * old per-request aggregation. These tests pin the PHP side: what is bound and when.
 */
class FlowRunIndexTest extends TestCase
{
    private Connection&MockObject $connection;
    private ManagerRegistry&MockObject $doctrine;
    private FlowRunIndex $index;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('transactional')->willReturnCallback(fn (\Closure $work) => $work($this->connection));
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->doctrine->method('getConnection')->willReturn($this->connection);
        $this->index = new FlowRunIndex($this->doctrine);
    }

    /**
     * @return array<string, mixed> the bound parameters of the single record() statement
     */
    private function recorded(string $event, array $payload = [], ?string $uuid = 'run-1'): array
    {
        $params = [];
        $this->connection->expects(self::once())->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $bound, array $types) use (&$params): int {
                self::assertStringContainsString('INSERT INTO aaxis_ontology_flow_runs AS r', $sql);
                self::assertStringContainsString('ON CONFLICT (flow_uuid) DO UPDATE', $sql);
                self::assertSame(ParameterType::BOOLEAN, $types['is_start']);
                $params = $bound;

                return 1;
            });
        $this->index->record($uuid, 4, 'Nightly', $event, '2026-03-04 05:06:07.123456', $payload, FlowRunIndex::STORAGE_DB);

        return $params;
    }

    public function testAFlowStartNamesTheRunAndCarriesItsTrigger(): void
    {
        $params = $this->recorded('flow-start', ['trigger' => 'debug', 'user' => ['name' => 'x']]);

        self::assertSame([
            'uuid' => 'run-1', 'flow_id' => 4, 'flow_name' => 'Nightly', 'is_start' => true, 'trigger' => 'debug',
            'storage' => 'db', 'start_at' => '2026-03-04 05:06:07.123456', 'at' => '2026-03-04 05:06:07.123456',
            'finished_at' => null, 'finish_event' => null, 'elapsed' => null,
        ], $params);
    }

    /**
     * @dataProvider finishEvents
     */
    public function testAFinishOrExceptionClosesTheRun(string $event): void
    {
        $params = $this->recorded($event, ['trigger' => 'ignored']);

        self::assertSame(
            [false, null, null, '2026-03-04 05:06:07.123456', $event, 0],
            [$params['is_start'], $params['trigger'], $params['start_at'], $params['finished_at'], $params['finish_event'], $params['elapsed']]
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function finishEvents(): array
    {
        return ['finish' => ['flow-finish'], 'exception' => ['flow-exception']];
    }

    public function testAnyOtherEventOnlyCountsAndStretchesTheRun(): void
    {
        $params = $this->recorded('step');

        self::assertSame([false, null, null, null], [$params['is_start'], $params['start_at'], $params['finished_at'], $params['finish_event']]);
    }

    public function testATriggerThatIsNotTextIsIgnoredAndALongOneCut(): void
    {
        self::assertNull($this->recorded('flow-start', ['trigger' => ['x']])['trigger']);
        $this->setUp();
        self::assertSame(str_repeat('t', 32), $this->recorded('flow-start', ['trigger' => str_repeat('t', 40)])['trigger']);
    }

    /**
     * @dataProvider anonymousRuns
     */
    public function testEventsWithoutARunIdentityAreNotIndexed(?string $uuid): void
    {
        $this->connection->expects(self::never())->method('executeStatement');

        $this->index->record($uuid, 1, 'F', 'step', '2026-03-04 05:06:07', [], FlowRunIndex::STORAGE_DB);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function anonymousRuns(): array
    {
        return ['null' => [null], 'empty' => ['']];
    }

    public function testRunsReadsOneStorageWithinTheWindowNewestFirst(): void
    {
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('FROM aaxis_ontology_flow_runs WHERE storage = :storage AND started_at >= :since'),
                    self::stringEndsWith('ORDER BY started_at DESC'),
                    self::logicalNot(self::stringContains('updated_at'))
                ),
                ['storage' => 'bucket', 'since' => '2026-02-01 00:00:00']
            )
            ->willReturn([['flow_uuid' => 'a']]);

        self::assertSame([['flow_uuid' => 'a']], $this->index->runs(FlowRunIndex::STORAGE_BUCKET, '2026-02-01 00:00:00'));
    }

    public function testRunsSinceACursorReturnsOnlyTheChangedOnes(): void
    {
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('AND updated_at > :changed'), ['storage' => 'db', 'since' => 's', 'changed' => 'c'])
            ->willReturn([]);

        self::assertSame([], $this->index->runs(FlowRunIndex::STORAGE_DB, 's', 'c'));
    }

    public function testTheCursorLagsTheDatabaseClockToCoverInFlightWrites(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with(self::logicalAnd(self::stringContains('clock_timestamp()'), self::stringContains("interval '5 seconds'")))
            ->willReturn('2026-03-04 05:06:02.000000');

        self::assertSame('2026-03-04 05:06:02.000000', $this->index->cursor());
    }

    public function testStaleDebugRunsAreOpenDebugSessionsQuietSinceTheCutoffInUtc(): void
    {
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains("WHERE finished_at IS NULL AND trigger = 'debug' AND last_event_at < :cutoff"),
                ['cutoff' => '2026-03-04 04:00:00.000000']
            )
            ->willReturn([['flow_uuid' => 'u', 'flow_id' => 1, 'flow_name' => 'F']]);

        $cutoff = new \DateTimeImmutable('2026-03-04 05:00:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame([['flow_uuid' => 'u', 'flow_id' => 1, 'flow_name' => 'F']], $this->index->staleDebugRuns($cutoff));
    }

    public function testRebuildingFromTheTableReplacesItsRowsWithTheBackfill(): void
    {
        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
            $statements[] = [$sql, $params];

            return \count($statements) === 2 ? 42 : 7;
        });

        self::assertSame(42, $this->index->rebuildFromTable());
        self::assertSame([
            ['DELETE FROM aaxis_ontology_flow_runs WHERE storage = :storage', ['storage' => 'db']],
            [FlowRunsTable::BACKFILL_SQL, []],
        ], $statements);
    }

    public function testRebuildingFromTheBucketIndexesItsListedRunsWithFlowNames(): void
    {
        $store = $this->createMock(BucketFlowEventStore::class);
        $store->expects(self::once())->method('listRuns')->with(14)->willReturn([
            ['flowUuid' => 'done', 'flowId' => 3, 'startedAtRaw' => '2026-03-04 05:00:00.000000', 'finishedAtRaw' => '2026-03-04 05:00:02.500000', 'finishEvent' => 'flow-finish', 'events' => 4],
            ['flowUuid' => 'open', 'flowId' => null, 'startedAtRaw' => '2026-03-04 06:00:00.000000', 'finishedAtRaw' => null, 'finishEvent' => null, 'events' => 1],
        ]);
        $flows = $this->createMock(ObjectRepository::class);
        $flow = (new OntologyFlow())->setName('Nightly');
        (new \ReflectionProperty(OntologyFlow::class, 'id'))->setValue($flow, 3);
        $flows->expects(self::once())->method('findBy')->with(['id' => [3]])->willReturn([$flow]);
        $this->doctrine->method('getRepository')->with(OntologyFlow::class)->willReturn($flows);
        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
            $statements[] = [$sql, $params];

            return 1;
        });

        self::assertSame(2, $this->index->rebuildFromBucket($store, 14));

        self::assertSame(['DELETE FROM aaxis_ontology_flow_runs WHERE storage = :storage', ['storage' => 'bucket']], $statements[0]);
        self::assertStringContainsString('ON CONFLICT (flow_uuid) DO NOTHING', $statements[1][0]);
        self::assertSame([
            'uuid' => 'done', 'flow_id' => 3, 'flow_name' => 'Nightly', 'storage' => 'bucket',
            'started' => '2026-03-04 05:00:00.000000', 'last' => '2026-03-04 05:00:02.500000',
            'finished' => '2026-03-04 05:00:02.500000', 'finish_event' => 'flow-finish', 'elapsed' => 2500, 'events' => 4,
        ], $statements[1][1]);
        self::assertSame(
            [null, null, '2026-03-04 06:00:00.000000', null, null],
            [$statements[2][1]['flow_id'], $statements[2][1]['flow_name'], $statements[2][1]['last'], $statements[2][1]['finished'], $statements[2][1]['elapsed']]
        );
    }

    public function testRebuildingAnEmptyBucketLooksUpNoFlows(): void
    {
        $store = $this->createMock(BucketFlowEventStore::class);
        $store->method('listRuns')->willReturn([]);
        $this->doctrine->expects(self::never())->method('getRepository');
        $this->connection->expects(self::once())->method('executeStatement');

        self::assertSame(0, $this->index->rebuildFromBucket($store, 30));
    }

    public function testPurgeDropsTheRunsWhoseLastEventAgedOutInBatches(): void
    {
        $sizes = [FlowRunIndex::PURGE_BATCH, 3];
        $this->connection->expects(self::exactly(2))->method('executeStatement')
            ->with(self::stringContains('WHERE last_event_at < :cutoff LIMIT 10000'), ['cutoff' => '2026-01-01 00:00:00.000000'])
            ->willReturnCallback(static function () use (&$sizes): int {
                return array_shift($sizes);
            });

        self::assertSame(FlowRunIndex::PURGE_BATCH + 3, $this->index->purgeBefore('2026-01-01 00:00:00.000000'));
    }
}
