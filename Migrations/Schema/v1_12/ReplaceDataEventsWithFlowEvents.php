<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_12;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * The Events redesign: aaxis_ontology_data_events (per-write rows, status derived from error) is
 * REPLACED by aaxis_ontology_flow_events — one row per flow-execution EVENT (flow-start /
 * flow-finish / flow-exception / data-upsert / log-message / step), written asynchronously by the
 * flow-event queue processor. Existing data-event rows are dropped with the table, deliberately.
 */
class ReplaceDataEventsWithFlowEvents implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->createTable('aaxis_ontology_flow_events');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        // Plain copies, NO foreign key: the execution record survives flow rename/delete.
        $table->addColumn('flow_id', 'integer', ['notnull' => false]);
        $table->addColumn('flow_uuid', 'string', ['length' => 36, 'notnull' => false]);
        $table->addColumn('flow_name', 'string', ['length' => 128, 'notnull' => false]);
        $table->addColumn('event', 'string', ['length' => 32]);
        // Microsecond precision: several parallel consumers write these rows, so insertion ids
        // do NOT follow emission order — the emit-time stamp is the only truthful ordering.
        $table->addColumn('datetime', 'datetime', ['columnDefinition' => 'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL']);
        $table->addColumn('payload', 'json', ['notnull' => false, 'columnDefinition' => 'JSONB DEFAULT NULL']);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['flow_id'], 'aaxis_ontology_flow_events_flow_idx');
        $table->addIndex(['flow_uuid'], 'aaxis_ontology_flow_events_uuid_idx');
        $table->addIndex(['datetime'], 'aaxis_ontology_flow_events_datetime_idx');

        if ($schema->hasTable('aaxis_ontology_data_events')) {
            $schema->dropTable('aaxis_ontology_data_events');
        }
    }
}
