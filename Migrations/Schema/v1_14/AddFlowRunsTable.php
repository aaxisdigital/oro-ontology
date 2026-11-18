<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_14;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\FlowRunsTable;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * The run index (aaxis_ontology_flow_runs, see {@see FlowRunsTable}): the Events page and the
 * stale-debug sweep stop aggregating the whole event history on every request. The table-stored
 * runs are backfilled here from aaxis_ontology_flow_events; bucket-stored ones are indexed by
 * `aaxis:ontology:flow-runs:rebuild` (the migration cannot reach the bucket).
 */
class AddFlowRunsTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if ($schema->hasTable(FlowRunsTable::NAME)) {
            return;
        }
        FlowRunsTable::create($schema);
        $queries->addPostQuery(FlowRunsTable::BACKFILL_SQL);
    }
}
