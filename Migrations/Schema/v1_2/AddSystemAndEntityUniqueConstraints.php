<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_2;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Enforces the uniqueness needed to reference an entity by "system name + entity name":
 *  - `aaxis_ontology_system.name` is unique;
 *  - `aaxis_ontology_entity (system_id, name)` is unique.
 *
 * The composite unique index also serves system_id-prefix lookups, so the old single-column
 * `aaxis_ontology_entity_system_idx` is dropped as redundant.
 */
class AddSystemAndEntityUniqueConstraints implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $system = $schema->getTable('aaxis_ontology_system');
        if (!$system->hasIndex('aaxis_ontology_system_name_uidx')) {
            $system->addUniqueIndex(['name'], 'aaxis_ontology_system_name_uidx');
        }

        $entity = $schema->getTable('aaxis_ontology_entity');
        if ($entity->hasIndex('aaxis_ontology_entity_system_idx')) {
            $entity->dropIndex('aaxis_ontology_entity_system_idx');
        }
        if (!$entity->hasIndex('aaxis_ontology_entity_system_name_uidx')) {
            $entity->addUniqueIndex(['system_id', 'name'], 'aaxis_ontology_entity_system_name_uidx');
        }
    }
}
