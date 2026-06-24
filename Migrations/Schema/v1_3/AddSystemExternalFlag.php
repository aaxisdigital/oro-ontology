<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_3;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds `aaxis_ontology_system.external` (boolean, default true).
 *
 * Existing rows become external = true (the column default). The built-in internal "OroCommerce"
 * system (external = false) is seeded separately by the {@see \Aaxis\Bundle\OntologyBundle\Migrations\Data\ORM\LoadOntologySystems}
 * data fixture.
 */
class AddSystemExternalFlag implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_system');
        if (!$table->hasColumn('external')) {
            $table->addColumn('external', 'boolean', ['default' => true]);
        }
    }
}
