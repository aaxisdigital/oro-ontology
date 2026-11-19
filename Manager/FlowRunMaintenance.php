<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\FlowRunsTable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Log\LoggerInterface;

/**
 * Upkeep of the flow-execution history: the run index's REBUILD (System Configuration button,
 * `aaxis:ontology:flow-runs:rebuild`), its OUT-OF-SYNC flag, and the RETENTION purge (daily
 * `aaxis:ontology:flow-history:purge`, or the Settings page's "Clean up now") — one place, so
 * the buttons, the commands and the consumer cannot disagree on which backend, which window or
 * what the flag means.
 *
 * The flag, the last rebuild and the last purge live in internal config values shown read-only
 * on the Settings page ({@see \Aaxis\Bundle\OntologyBundle\Form\Type\FlowRunIndexStatusType},
 * {@see \Aaxis\Bundle\OntologyBundle\Form\Type\FlowHistoryPurgeType}).
 */
class FlowRunMaintenance
{
    /** UTC time of the first event the run index failed to take in; '' = in sync. */
    public const string STALE_SINCE = 'aaxis_ontology.flow_runs_index_stale_since';

    /** JSON {at, storage, runs, days} of the last completed rebuild; '' = never rebuilt. */
    public const string LAST_REBUILD = 'aaxis_ontology.flow_runs_index_last_rebuild';

    /**
     * JSON {at, days, cutoff, events, runs, bucketConfigured, bucketObjects} of the last purge —
     * daily job or the Settings page's "Clean up now" alike; '' = never purged.
     */
    public const string LAST_PURGE = 'aaxis_ontology.flow_history_last_purge';

    /** JSON {at, tables: [{table, before, after, error?}]} of the last compaction; '' = never. */
    public const string LAST_COMPACT = 'aaxis_ontology.flow_history_last_compact';

    /**
     * The tables the retention purge deletes from — the ones vacuumed after it and compacted on
     * request (the bucket has no such upkeep). Table names are constants: never user input.
     */
    public const array PURGED_TABLES = [
        'events' => 'aaxis_ontology_flow_events',
        'runs' => FlowRunsTable::NAME,
    ];

    /** How long a compaction waits for its exclusive lock before giving up on a table. */
    private const string COMPACT_LOCK_TIMEOUT = '5s';

    private const string HISTORY_DAYS = 'aaxis_ontology.flow_execution_history_days';
    private const int DEFAULT_HISTORY_DAYS = 30;
    private const string DB_MICRO = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ConfigManager $config,
        private readonly FlowRunIndex $runs,
        private readonly BucketFlowEventStore $bucketEvents,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** "Flow execution history days" — 30 when unset, zero or negative. */
    public function historyDays(): int
    {
        $days = (int) $this->config->get(self::HISTORY_DAYS);

        return $days > 0 ? $days : self::DEFAULT_HISTORY_DAYS;
    }

    /**
     * Re-derives the run index of the ACTIVE event backend (table, or the bucket's last $days —
     * history days by default) and records the outcome. The out-of-sync flag is cleared when it
     * was raised before this rebuild began; one raised meanwhile stays.
     *
     * @return array{storage: string, runs: int, days: int|null}
     */
    public function rebuild(?int $days = null): array
    {
        $startedAt = self::now();
        if ($this->bucketEvents->isEnabled()) {
            $days = $days !== null && $days > 0 ? $days : $this->historyDays();
            $result = ['storage' => FlowRunIndex::STORAGE_BUCKET, 'runs' => $this->runs->rebuildFromBucket($this->bucketEvents, $days), 'days' => $days];
        } else {
            $result = ['storage' => FlowRunIndex::STORAGE_DB, 'runs' => $this->runs->rebuildFromTable(), 'days' => null];
        }

        $this->config->set(self::LAST_REBUILD, json_encode(['at' => self::now()] + $result));
        $staleSince = $this->staleSince();
        if ($staleSince !== null && $staleSince <= $startedAt) {
            $this->config->set(self::STALE_SINCE, '');
        }
        $this->config->flush();

        return $result;
    }

    /**
     * An event was stored but could not be folded into the run index. Only the FIRST failure
     * raises the flag (one config write, one log line); later ones find it raised and do nothing
     * until a rebuild clears it.
     */
    public function markStale(\Throwable $failure, ?string $flowUuid): void
    {
        if ($this->staleSince() !== null) {
            return;
        }
        $this->config->set(self::STALE_SINCE, self::now());
        $this->config->flush();
        $this->logger->error(
            'aaxis_ontology_flow_event: the run index (Events page) is out of sync — rebuild it from System Configuration → Ontology → Settings.',
            ['exception' => $failure, 'flow_uuid' => $flowUuid]
        );
    }

    public function staleSince(): ?string
    {
        $value = (string) ($this->config->get(self::STALE_SINCE) ?? '');

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{at: string, storage: string, runs: int, days: int|null}|null
     */
    public function lastRebuild(): ?array
    {
        $decoded = json_decode((string) ($this->config->get(self::LAST_REBUILD) ?? ''), true);

        return \is_array($decoded) && isset($decoded['at'], $decoded['storage'], $decoded['runs']) ? $decoded : null;
    }

    /**
     * @return array{at: string, days: int, cutoff: string, events: int|null, runs: int|null, bucketConfigured: bool, bucketObjects: int|null}|null
     */
    public function lastPurge(): ?array
    {
        $decoded = json_decode((string) ($this->config->get(self::LAST_PURGE) ?? ''), true);

        return \is_array($decoded) && isset($decoded['at'], $decoded['cutoff']) ? $decoded : null;
    }

    /**
     * RETENTION: drops the flow-execution history older than the history window from BOTH
     * stores — table events (by event time), run index rows (whose last event is older) and, when
     * a bucket connection is configured, the bucket's event day folders before the cutoff day.
     * Each store is purged independently: one failing is logged and reported, the others still run.
     * Every table that lost rows is then VACUUMed (plain + ANALYZE: no blocking lock), so the freed
     * space is reusable right away instead of after autovacuum — the files keep their size; only
     * {@see compact()} gives space back. The outcome is recorded ({@see lastPurge()}).
     *
     * @return array{cutoff: string, events: int|null, runs: int|null, bucketConfigured: bool, bucketObjects: int|null, vacuumed: list<string>}
     *               a null count = that store failed (see the log)
     */
    public function purge(?\DateTimeImmutable $now = null): array
    {
        $days = $this->historyDays();
        $cutoff = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('-%d days', $days));
        $stamp = $cutoff->format(self::DB_MICRO);
        $bucket = $this->bucketEvents->isConfigured();

        $result = [
            'cutoff' => $stamp,
            'events' => $this->attempt('flow events table', fn (): int => $this->purgeEventTable($stamp)),
            'runs' => $this->attempt('run index', fn (): int => $this->runs->purgeBefore($stamp)),
            'bucketConfigured' => $bucket,
            'bucketObjects' => $bucket
                ? $this->attempt('bucket flow events', fn (): int => $this->bucketEvents->purgeBefore($cutoff))
                : null,
        ];
        $result['vacuumed'] = $this->vacuumImpacted($result);
        // Recording is a courtesy for the Settings page: the purge itself already happened.
        $this->attempt('last purge record', function () use ($result, $days): int {
            $this->config->set(self::LAST_PURGE, json_encode(['at' => self::now(), 'days' => $days] + $result));
            $this->config->flush();

            return 0;
        });

        return $result;
    }

    /**
     * Plain VACUUM (ANALYZE) of every purged table that actually lost rows. It cannot run inside a
     * transaction block, so an open transaction skips it (autovacuum will get there).
     *
     * @param array<string, mixed> $result the purge report so far
     *
     * @return list<string> the tables vacuumed
     */
    private function vacuumImpacted(array $result): array
    {
        $connection = $this->connection();
        if ($connection->isTransactionActive()) {
            return [];
        }
        $vacuumed = [];
        foreach (self::PURGED_TABLES as $key => $table) {
            if (($result[$key] ?? 0) > 0
                && $this->attempt('vacuum of ' . $table, static fn (): int => (int) $connection->executeStatement('VACUUM (ANALYZE) ' . $table)) !== null) {
                $vacuumed[] = $table;
            }
        }

        return $vacuumed;
    }

    /**
     * COMPACTION (the Settings page's "Compact tables"): VACUUM FULL of the purged tables — the
     * only thing that hands the space of deleted rows back to the disk. It REWRITES each table
     * under an exclusive lock, so meanwhile event writes (consumers) and the Events page wait; it
     * waits at most {@see COMPACT_LOCK_TIMEOUT} for that lock and gives the table up otherwise
     * (recorded as its error), instead of queueing everything behind it. Sizes before/after are
     * recorded ({@see lastCompact()}).
     *
     * @return array{at: string, tables: list<array{table: string, before: int|null, after: int|null, error?: string}>}
     */
    public function compact(): array
    {
        $connection = $this->connection();
        $tables = [];
        foreach (self::PURGED_TABLES as $table) {
            $entry = ['table' => $table, 'before' => $this->tableBytes($table), 'after' => null];
            try {
                $connection->executeStatement(sprintf("SET lock_timeout = '%s'", self::COMPACT_LOCK_TIMEOUT));
                try {
                    $connection->executeStatement('VACUUM (FULL, ANALYZE) ' . $table);
                } finally {
                    $connection->executeStatement('RESET lock_timeout');
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Flow execution history compaction failed for %s.', $table), ['exception' => $e]);
                $entry['error'] = mb_substr($e->getMessage(), 0, 300);
            }
            $entry['after'] = $this->tableBytes($table);
            $tables[] = $entry;
        }
        $result = ['at' => self::now(), 'tables' => $tables];
        $this->attempt('last compaction record', function () use ($result): int {
            $this->config->set(self::LAST_COMPACT, json_encode($result));
            $this->config->flush();

            return 0;
        });

        return $result;
    }

    /**
     * @return array{at: string, tables: list<array{table: string, before: int|null, after: int|null, error?: string}>}|null
     */
    public function lastCompact(): ?array
    {
        $decoded = json_decode((string) ($this->config->get(self::LAST_COMPACT) ?? ''), true);

        return \is_array($decoded) && isset($decoded['at']) && \is_array($decoded['tables'] ?? null) ? $decoded : null;
    }

    /** Total size (table + indexes + toast) in bytes; null when it cannot be told. */
    private function tableBytes(string $table): ?int
    {
        try {
            $bytes = $this->connection()->fetchOne('SELECT pg_total_relation_size(to_regclass(?))', [$table]);
        } catch (\Throwable) {
            return null;
        }

        return $bytes === null || $bytes === false ? null : (int) $bytes;
    }

    private function connection(): Connection
    {
        return $this->doctrine->getConnection();
    }

    private function purgeEventTable(string $cutoff): int
    {
        $connection = $this->connection();
        $total = 0;
        do {
            $deleted = (int) $connection->executeStatement(
                'DELETE FROM aaxis_ontology_flow_events WHERE id IN '
                . '(SELECT id FROM aaxis_ontology_flow_events WHERE datetime < :cutoff LIMIT ' . FlowRunIndex::PURGE_BATCH . ')',
                ['cutoff' => $cutoff]
            );
            $total += $deleted;
        } while ($deleted >= FlowRunIndex::PURGE_BATCH);

        return $total;
    }

    /**
     * @param \Closure(): int $purge
     */
    private function attempt(string $store, \Closure $purge): ?int
    {
        try {
            return $purge();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Flow execution history purge failed for the %s.', $store), ['exception' => $e]);

            return null;
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DB_MICRO);
    }
}
