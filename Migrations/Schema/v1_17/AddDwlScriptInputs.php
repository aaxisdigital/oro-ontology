<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_17;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\DwlScriptTable;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Data Playground scripts run against SEVERAL named inputs: adds the jsonb `inputs` list and moves
 * the first shape's single input into it (`entity_id` → `[{entityId, name: payload}]`). v1_18 then
 * drops the old columns. A table created by v1_16's current definition already has `inputs` and no
 * `entity_id` — nothing to do then.
 */
class AddDwlScriptInputs implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable(DwlScriptTable::NAME);
        if (!$table->hasColumn('inputs')) {
            $table->addColumn('inputs', 'json', ['columnDefinition' => DwlScriptTable::INPUTS_DEFINITION]);
        }
        if ($table->hasColumn('entity_id')) {
            $queries->addPostQuery(
                'UPDATE ' . DwlScriptTable::NAME . " SET inputs = jsonb_build_array(jsonb_build_object("
                . "'entityId', entity_id, 'name', 'payload')) WHERE entity_id IS NOT NULL"
            );
        }
    }
}
