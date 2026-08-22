<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_13;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds aaxis_ontology_entity.force_db_storage: the per-entity opt-out from the bucket entity-data
 * backend — a flagged entity keeps reading/writing aaxis_ontology_data even while the global
 * "Use Bucket for Entity Data" toggle is on (hot entities where bucket reads are too slow).
 */
class AddEntityForceDbStorageColumn implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_entity');
        if (!$table->hasColumn('force_db_storage')) {
            $table->addColumn('force_db_storage', 'boolean', ['default' => false]);
        }
    }
}
