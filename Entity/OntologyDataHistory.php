<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Versioned history of {@see OntologyData} records. Mirrors the OntologyData structure, but the uniqueness is
 * the triplet (entity, unique_id, version) so every version of a record is retained.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_data_history')]
#[ORM\Index(columns: ['entity_id'], name: 'aaxis_ontology_data_hist_entity_idx')]
#[ORM\Index(columns: ['unique_id'], name: 'aaxis_ontology_data_hist_unique_id_idx')]
#[ORM\Index(columns: ['updated_at'], name: 'aaxis_ontology_data_hist_updated_at_idx')]
#[ORM\UniqueConstraint(name: 'aaxis_ontology_data_hist_e_u_v_uidx', columns: ['entity_id', 'unique_id', 'version'])]
#[Config(
    defaultValues: [
        'entity' => ['icon' => 'fa-clone'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyDataHistory
{
    use OntologyDataFieldsTrait;

    public function __toString(): string
    {
        return (string) $this->uniqueId . ' v' . (string) $this->version;
    }
}
