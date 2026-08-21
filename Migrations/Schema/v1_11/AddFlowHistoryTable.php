<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_11;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Flow version history: whenever a flow's definition is CHANGED, the version being replaced is
 * archived here FIRST — but only when that version actually ran (a revision that was never
 * executed since the previous archive is just overwritten; see Manager/FlowHistoryArchiver and
 * its last_executed equality rule). History rows follow their flow on delete.
 */
class AddFlowHistoryTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->createTable('aaxis_ontology_flow_history');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('flow_id', 'integer');
        $table->addColumn('version', 'integer');
        $table->addColumn('name', 'string', ['length' => 128]);
        $table->addColumn('steps', 'json', ['notnull' => false, 'columnDefinition' => 'JSONB DEFAULT NULL']);
        $table->addColumn('design', 'json', ['notnull' => false, 'columnDefinition' => 'JSONB DEFAULT NULL']);
        // The flow's last_executed AT ARCHIVE TIME — the "was this version ever run" marker the
        // archiver compares against (raw column value, string-exact).
        $table->addColumn('last_executed', 'datetime', ['notnull' => false]);
        $table->addColumn('archived_at', 'datetime', []);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['flow_id', 'version'], 'aaxis_ontology_flow_hist_uidx');
        $table->addForeignKeyConstraint(
            $schema->getTable('aaxis_ontology_flow'),
            ['flow_id'],
            ['id'],
            ['onDelete' => 'CASCADE', 'onUpdate' => null]
        );
    }
}
