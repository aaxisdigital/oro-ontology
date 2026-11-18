<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * aaxis_ontology_flow_runs — the RUN INDEX: one row per flow run (flowUuid), kept current by the
 * flow-event consumer as each event is stored ({@see \Aaxis\Bundle\OntologyBundle\Manager\FlowRunIndex}).
 * The Events page and the stale-debug sweep read it instead of aggregating every event row on
 * every request (which grew with the whole history). It indexes runs of BOTH event backends —
 * `storage` says where the run's events live (db = aaxis_ontology_flow_events, bucket = the
 * object store).
 *
 * Shared by the installer and the v1_14 migration so the two cannot drift.
 */
final class FlowRunsTable
{
    public const string NAME = 'aaxis_ontology_flow_runs';

    private const string MICRO = 'TIMESTAMP(6) WITHOUT TIME ZONE';

    /**
     * Rebuilds the index rows of the TABLE-stored runs from aaxis_ontology_flow_events, with the
     * very rules the consumer applies event by event (the flow-start names the run; the latest
     * finish/exception closes it). Existing rows are kept (ON CONFLICT DO NOTHING) — callers that
     * want a fresh rebuild delete the `db` rows first.
     */
    public const string BACKFILL_SQL = <<<'SQL'
        INSERT INTO aaxis_ontology_flow_runs (
            flow_uuid, flow_id, flow_name, has_start, trigger, storage,
            start_at, first_event_at, started_at, last_event_at,
            finished_at, finish_event, elapsed_ms, events, updated_at
        )
        SELECT flow_uuid,
               COALESCE(start_flow_id, any_flow_id),
               COALESCE(start_name, any_name),
               has_start,
               LEFT(trigger, 32),
               'db',
               start_at, first_event_at, COALESCE(start_at, first_event_at), last_event_at,
               finished_at, finish_event,
               CASE WHEN finished_at IS NULL THEN NULL
                    ELSE GREATEST(0, ROUND(EXTRACT(EPOCH FROM finished_at - COALESCE(start_at, first_event_at)) * 1000))::bigint
               END,
               events,
               timezone('UTC', clock_timestamp())
        FROM (
            SELECT flow_uuid,
                   (array_agg(flow_id ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS start_flow_id,
                   (array_agg(flow_id ORDER BY id) FILTER (WHERE flow_id IS NOT NULL))[1] AS any_flow_id,
                   (array_agg(flow_name ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS start_name,
                   (array_agg(flow_name ORDER BY id) FILTER (WHERE flow_name IS NOT NULL))[1] AS any_name,
                   bool_or(event = 'flow-start') AS has_start,
                   (array_agg(payload->>'trigger' ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS trigger,
                   min(datetime) FILTER (WHERE event = 'flow-start') AS start_at,
                   min(datetime) AS first_event_at,
                   max(datetime) AS last_event_at,
                   max(datetime) FILTER (WHERE event IN ('flow-finish', 'flow-exception')) AS finished_at,
                   (array_agg(event ORDER BY datetime DESC, id DESC)
                       FILTER (WHERE event IN ('flow-finish', 'flow-exception')))[1] AS finish_event,
                   count(*) AS events
            FROM aaxis_ontology_flow_events
            WHERE flow_uuid IS NOT NULL
            GROUP BY flow_uuid
        ) agg
        ON CONFLICT (flow_uuid) DO NOTHING
        SQL;

    public static function create(Schema $schema): void
    {
        $table = $schema->createTable(self::NAME);
        $table->addColumn('flow_uuid', 'string', ['length' => 36]);
        // The run's flow: its flow-start's (a nested subflow's events carry the SUBFLOW's id, so
        // until the flow-start arrives the first id seen is only a stand-in — see has_start).
        $table->addColumn('flow_id', 'integer', ['notnull' => false]);
        $table->addColumn('flow_name', 'string', ['length' => 128, 'notnull' => false]);
        $table->addColumn('has_start', 'boolean', ['default' => false]);
        // How the run was started (the flow-start payload's trigger: debug, schedule, endpoint…).
        $table->addColumn('trigger', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('storage', 'string', ['length' => 8, 'default' => 'db']);
        // start_at = the flow-start's time; first_event_at = the earliest event (a start-less run,
        // e.g. a bare consumer upsert, starts there); started_at = the one the page shows/sorts.
        $table->addColumn('start_at', 'datetime', ['columnDefinition' => self::MICRO . ' DEFAULT NULL']);
        $table->addColumn('first_event_at', 'datetime', ['columnDefinition' => self::MICRO . ' NOT NULL']);
        $table->addColumn('started_at', 'datetime', ['columnDefinition' => self::MICRO . ' NOT NULL']);
        $table->addColumn('last_event_at', 'datetime', ['columnDefinition' => self::MICRO . ' NOT NULL']);
        // The latest flow-finish/flow-exception (NULL = still running) and which one it was.
        $table->addColumn('finished_at', 'datetime', ['columnDefinition' => self::MICRO . ' DEFAULT NULL']);
        $table->addColumn('finish_event', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('elapsed_ms', 'bigint', ['notnull' => false]);
        $table->addColumn('events', 'integer', ['default' => 0]);
        // Database clock of the last change — the Events page's incremental refresh cursor.
        $table->addColumn('updated_at', 'datetime', ['columnDefinition' => self::MICRO . ' NOT NULL']);
        $table->setPrimaryKey(['flow_uuid']);
        $table->addIndex(['storage', 'started_at'], 'aaxis_ontology_flow_runs_started_idx');
        $table->addIndex(['updated_at'], 'aaxis_ontology_flow_runs_updated_idx');
        // The minutely stale-debug sweep only ever looks at OPEN debug sessions.
        $table->addIndex(
            ['last_event_at'],
            'aaxis_ontology_flow_runs_open_debug_idx',
            [],
            ['where' => "finished_at IS NULL AND trigger = 'debug'"]
        );
    }
}
