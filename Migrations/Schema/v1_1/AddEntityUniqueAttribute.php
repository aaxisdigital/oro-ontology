<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_1;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds the required `unique_attribute` column to `aaxis_ontology_entity`: the name of the attribute
 * (from the entity's attributes) whose value is used as the unique_id in data operations.
 *
 * Fresh installs already get this column from {@see AaxisOntologyBundleInstaller} (version >= v1_1),
 * so this migration only runs against databases installed at an earlier version.
 */
class AddEntityUniqueAttribute implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_entity');
        if (!$table->hasColumn('unique_attribute')) {
            $table->addColumn('unique_attribute', 'string', ['length' => 100, 'default' => '']);
        }
    }
}
