<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_18;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\DwlScriptTable;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Drops the first shape's single input (`entity_id` with its foreign key and index — v1_17 moved it
 * into `inputs`) and its `render` column (the script's own `output` header decides the format).
 */
class DropDwlScriptSingleInput implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable(DwlScriptTable::NAME);
        if ($table->hasColumn('entity_id')) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                if ($foreignKey->getLocalColumns() === ['entity_id']) {
                    $table->removeForeignKey($foreignKey->getName());
                }
            }
            if ($table->hasIndex('aaxis_ontology_dwl_script_entity_idx')) {
                $table->dropIndex('aaxis_ontology_dwl_script_entity_idx');
            }
            $table->dropColumn('entity_id');
        }
        if ($table->hasColumn('render')) {
            $table->dropColumn('render');
        }
    }
}
