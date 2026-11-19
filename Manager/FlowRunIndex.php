<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Migrations\Schema\FlowRunsTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The RUN INDEX (aaxis_ontology_flow_runs, {@see FlowRunsTable}): one row per flow run, folded
 * event by event as the flow-event consumer stores them — so the Events page and the stale-debug
 * sweep read one row per run instead of aggregating the whole event history on every request.
 *
 * Folding is ORDER-INDEPENDENT (parallel consumers store a run's events in any order): times are
 * min/max'ed, the count incremented, and the flow-start — whenever it lands — names the run (its
 * flow id/name win; a start without them, e.g. an unsaved flow's debug, keeps the first ones seen).
 * The result equals what {@see FlowRunsTable::BACKFILL_SQL} computes from the stored events, except
 * that the "first seen" stand-in of a start-less run follows arrival rather than insertion order.
 */
class FlowRunIndex
{
    public const string STORAGE_DB = 'db';
    public const string STORAGE_BUCKET = 'bucket';

    private const array FINISH_EVENTS = ['flow-finish', 'flow-exception'];

    /**
     * A refresh re-reads runs changed since this long before the previous answer: a transaction
     * still in flight then (its row stamped earlier, committed later) is picked up next time.
     */
    private const int CURSOR_OVERLAP_SECONDS = 5;

    /** Rows deleted per statement by the retention purge. */
    public const int PURGE_BATCH = 10000;

    private const string RECORD_SQL = <<<'SQL'
        INSERT INTO aaxis_ontology_flow_runs AS r (
            flow_uuid, flow_id, flow_name, has_start, trigger, storage,
            start_at, first_event_at, started_at, last_event_at,
            finished_at, finish_event, elapsed_ms, events, updated_at
        ) VALUES (
            :uuid, :flow_id, :flow_name, :is_start, :trigger, :storage,
            :start_at, :at, :at, :at,
            :finished_at, :finish_event, :elapsed, 1, timezone('UTC', clock_timestamp())
        )
        ON CONFLICT (flow_uuid) DO UPDATE SET
            flow_id = CASE WHEN EXCLUDED.has_start AND NOT r.has_start THEN COALESCE(EXCLUDED.flow_id, r.flow_id)
                           ELSE COALESCE(r.flow_id, EXCLUDED.flow_id) END,
            flow_name = CASE WHEN EXCLUDED.has_start AND NOT r.has_start THEN COALESCE(EXCLUDED.flow_name, r.flow_name)
                             ELSE COALESCE(r.flow_name, EXCLUDED.flow_name) END,
            trigger = COALESCE(r.trigger, EXCLUDED.trigger),
            has_start = r.has_start OR EXCLUDED.has_start,
            storage = EXCLUDED.storage,
            start_at = LEAST(r.start_at, EXCLUDED.start_at),
            first_event_at = LEAST(r.first_event_at, EXCLUDED.first_event_at),
            started_at = COALESCE(LEAST(r.start_at, EXCLUDED.start_at), LEAST(r.first_event_at, EXCLUDED.first_event_at)),
            last_event_at = GREATEST(r.last_event_at, EXCLUDED.last_event_at),
            finished_at = GREATEST(r.finished_at, EXCLUDED.finished_at),
            finish_event = CASE WHEN EXCLUDED.finished_at IS NOT NULL
                                     AND (r.finished_at IS NULL OR EXCLUDED.finished_at >= r.finished_at)
                                THEN EXCLUDED.finish_event ELSE r.finish_event END,
            elapsed_ms = CASE WHEN GREATEST(r.finished_at, EXCLUDED.finished_at) IS NULL THEN NULL
                              ELSE GREATEST(0, ROUND(EXTRACT(EPOCH FROM
                                  GREATEST(r.finished_at, EXCLUDED.finished_at)
                                  - COALESCE(LEAST(r.start_at, EXCLUDED.start_at), LEAST(r.first_event_at, EXCLUDED.first_event_at))
                              ) * 1000))::bigint END,
            events = r.events + 1,
            updated_at = EXCLUDED.updated_at
        SQL;

    private const string DELETE_RUNS = 'DELETE FROM ' . FlowRunsTable::NAME;

    private const string COLUMNS = 'flow_uuid, flow_id, flow_name, storage, started_at, finished_at, finish_event, elapsed_ms, events';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Folds one stored event into its run's row. Events without a run identity (no flowUuid) are
     * not indexed — the Events page cannot open them either.
     *
     * @param array<string, mixed> $payload
     * @param string               $datetime UTC 'Y-m-d H:i:s.u'
     */
    public function record(
        ?string $flowUuid,
        ?int $flowId,
        ?string $flowName,
        string $event,
        string $datetime,
        array $payload,
        string $storage,
    ): void {
        if ($flowUuid === null || $flowUuid === '') {
            return;
        }
        $isStart = $event === 'flow-start';
        $isFinish = \in_array($event, self::FINISH_EVENTS, true);
        $trigger = $isStart && \is_string($payload['trigger'] ?? null) ? mb_substr($payload['trigger'], 0, 32) : null;

        $this->connection()->executeStatement(self::RECORD_SQL, [
            'uuid' => mb_substr($flowUuid, 0, 36),
            'flow_id' => $flowId,
            'flow_name' => $flowName,
            'is_start' => $isStart,
            'trigger' => $trigger,
            'storage' => $storage,
            'start_at' => $isStart ? $datetime : null,
            'at' => $datetime,
            'finished_at' => $isFinish ? $datetime : null,
            'finish_event' => $isFinish ? $event : null,
            // A single event's run: started and finished at the same instant.
            'elapsed' => $isFinish ? 0 : null,
        ], [
            'flow_id' => ParameterType::INTEGER,
            'is_start' => ParameterType::BOOLEAN,
            'elapsed' => ParameterType::INTEGER,
        ]);
    }

    /**
     * The runs of one storage started since $since, newest first — or, with $changedSince, only
     * those whose row changed after that cursor (the page's incremental refresh).
     *
     * @return list<array<string, mixed>> rows: flow_uuid, flow_id, flow_name, storage, started_at,
     *                                    finished_at, finish_event, elapsed_ms, events
     */
    public function runs(string $storage, string $startedSince, ?string $changedSince = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . FlowRunsTable::NAME
            . ' WHERE storage = :storage AND started_at >= :since';
        $params = ['storage' => $storage, 'since' => $startedSince];
        if ($changedSince !== null) {
            $sql .= ' AND updated_at > :changed';
            $params['changed'] = $changedSince;
        }

        return $this->connection()->fetchAllAssociative($sql . ' ORDER BY started_at DESC', $params);
    }

    /** The cursor a client sends back to fetch only the runs changed after this answer. */
    public function cursor(): string
    {
        return (string) $this->connection()->fetchOne(sprintf(
            "SELECT to_char(timezone('UTC', clock_timestamp()) - interval '%d seconds', 'YYYY-MM-DD HH24:MI:SS.US')",
            self::CURSOR_OVERLAP_SECONDS
        ));
    }

    /**
     * Debug sessions still open (no finish/exception) whose last event is older than the cutoff —
     * abandoned in the editor. Both storages: the index knows every run.
     *
     * @return list<array{flow_uuid: string, flow_id: int|null, flow_name: string|null}>
     */
    public function staleDebugRuns(\DateTimeImmutable $cutoff): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT flow_uuid, flow_id, flow_name FROM ' . FlowRunsTable::NAME
            . " WHERE finished_at IS NULL AND trigger = 'debug' AND last_event_at < :cutoff",
            ['cutoff' => $cutoff->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]
        );
    }

    /**
     * Retention: drops the runs whose LAST event is older than the cutoff (their whole history
     * has aged out), in batches so the table is never locked for long. Returns the row count.
     */
    public function purgeBefore(string $cutoff): int
    {
        $connection = $this->connection();
        $total = 0;
        do {
            $deleted = (int) $connection->executeStatement(
                self::DELETE_RUNS . ' WHERE flow_uuid IN (SELECT flow_uuid FROM '
                . FlowRunsTable::NAME . ' WHERE last_event_at < :cutoff LIMIT ' . self::PURGE_BATCH . ')',
                ['cutoff' => $cutoff]
            );
            $total += $deleted;
        } while ($deleted >= self::PURGE_BATCH);

        return $total;
    }

    /** Re-derives every TABLE-stored run from aaxis_ontology_flow_events; returns the row count. */
    public function rebuildFromTable(): int
    {
        $connection = $this->connection();

        return (int) $connection->transactional(static function (Connection $connection): int {
            $connection->executeStatement(self::DELETE_RUNS . ' WHERE storage = :storage', ['storage' => self::STORAGE_DB]);

            return (int) $connection->executeStatement(FlowRunsTable::BACKFILL_SQL);
        });
    }

    /**
     * Indexes the BUCKET-stored runs of the last $days days from the store's key listings (the
     * runs written before the index existed). The keys carry no flow name or trigger: names come
     * from the flows table by id, and the trigger stays unknown — irrelevant for past runs.
     * Returns the row count.
     */
    public function rebuildFromBucket(BucketFlowEventStore $store, int $days): int
    {
        $runs = $store->listRuns($days);
        $names = $this->flowNames(array_values(array_unique(array_filter(array_column($runs, 'flowId')))));
        $connection = $this->connection();

        return (int) $connection->transactional(function (Connection $connection) use ($runs, $names): int {
            $connection->executeStatement(self::DELETE_RUNS . ' WHERE storage = :storage', ['storage' => self::STORAGE_BUCKET]);
            foreach ($runs as $run) {
                $this->insertBucketRun($connection, $run, $names);
            }

            return \count($runs);
        });
    }

    /**
     * @param array<string, mixed> $run   one {@see BucketFlowEventStore::listRuns()} entry
     * @param array<int, string>   $names flow names by id
     */
    private function insertBucketRun(Connection $connection, array $run, array $names): void
    {
        $started = (string) $run['startedAtRaw'];
        $finished = $run['finishedAtRaw'] !== null ? (string) $run['finishedAtRaw'] : null;
        $elapsed = null;
        if ($finished !== null) {
            $elapsed = max(0, (int) round(((float) date_create($finished, new \DateTimeZone('UTC'))->format('U.u')
                - (float) date_create($started, new \DateTimeZone('UTC'))->format('U.u')) * 1000));
        }
        $connection->executeStatement(
            'INSERT INTO ' . FlowRunsTable::NAME . ' (flow_uuid, flow_id, flow_name, has_start, storage,
                 start_at, first_event_at, started_at, last_event_at, finished_at, finish_event, elapsed_ms, events, updated_at)
             VALUES (:uuid, :flow_id, :flow_name, true, :storage, :started, :started, :started, :last,
                 :finished, :finish_event, :elapsed, :events, timezone(\'UTC\', clock_timestamp()))
             ON CONFLICT (flow_uuid) DO NOTHING',
            [
                'uuid' => (string) $run['flowUuid'],
                'flow_id' => $run['flowId'],
                'flow_name' => $run['flowId'] !== null ? ($names[$run['flowId']] ?? null) : null,
                'storage' => self::STORAGE_BUCKET,
                'started' => $started,
                'last' => $finished ?? $started,
                'finished' => $finished,
                'finish_event' => $run['finishEvent'],
                'elapsed' => $elapsed,
                'events' => (int) $run['events'],
            ],
            ['flow_id' => ParameterType::INTEGER, 'elapsed' => ParameterType::INTEGER, 'events' => ParameterType::INTEGER]
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function flowNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->doctrine->getRepository(OntologyFlow::class)->findBy(['id' => $ids]) as $flow) {
            $names[(int) $flow->getId()] = (string) $flow->getName();
        }

        return $names;
    }

    private function connection(): Connection
    {
        return $this->doctrine->getConnection();
    }
}
