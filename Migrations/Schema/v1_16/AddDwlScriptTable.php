<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_16;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\DwlScriptTable;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * The Data Playground's saved scripts ({@see DwlScriptTable}).
 */
class AddDwlScriptTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if (!$schema->hasTable(DwlScriptTable::NAME)) {
            DwlScriptTable::create($schema);
        }
    }
}
