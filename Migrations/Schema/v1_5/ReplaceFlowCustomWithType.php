<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_5;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Replaces `aaxis_ontology_flow.custom` (boolean, v1_4) with `type` (string):
 *  - custom = false  → 'native'   (the two built-in fixture-seeded flows)
 *  - custom = true   → 'subflow'  (no user flow had persisted steps before this version,
 *                                  so none can contain a trigger; saves recompute the type)
 *
 * The data copy and the column drop run as post queries, in order, AFTER the schema diff adds
 * the new column — dropping `custom` through the Schema API instead would emit the DROP before
 * the UPDATE still reading it.
 */
class ReplaceFlowCustomWithType implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_flow');
        if ($table->hasColumn('type')) {
            return;
        }

        $table->addColumn('type', 'string', ['length' => 16, 'default' => 'subflow']);

        if ($table->hasColumn('custom')) {
            $queries->addPostQuery(
                "UPDATE aaxis_ontology_flow SET type = CASE WHEN custom THEN 'subflow' ELSE 'native' END"
            );
            $queries->addPostQuery('ALTER TABLE aaxis_ontology_flow DROP COLUMN custom');
        }
    }
}
