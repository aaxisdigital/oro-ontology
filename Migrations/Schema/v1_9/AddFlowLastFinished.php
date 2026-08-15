<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_9;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds aaxis_ontology_flow.last_finished — when the flow's last run ENDED (success or failure).
 * Paired with last_executed (stamped at the START of a run) it tells whether an instance is still
 * in flight, which the scheduler uses to avoid starting a flow that is already running.
 *
 * Existing rows are backfilled from last_executed: everything recorded before this column existed
 * has certainly finished, and leaving them NULL would make every previously-run flow look
 * permanently "running" and block its next scheduled execution.
 */
class AddFlowLastFinished implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_flow');
        if ($table->hasColumn('last_finished')) {
            return;
        }

        $table->addColumn('last_finished', 'datetime', ['notnull' => false]);

        $queries->addPostQuery(
            'UPDATE aaxis_ontology_flow SET last_finished = last_executed WHERE last_executed IS NOT NULL'
        );
    }
}
