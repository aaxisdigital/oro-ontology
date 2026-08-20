<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Ontology "Data": a single, versioned data record that flowed through the model,
 * identified by a {@see OntologySystem}, a {@see OntologyEntity}, a business unique id and its raw JSON
 * payload. The triplet (entity, unique_id, version) is unique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_data')]
#[ORM\Index(columns: ['entity_id'], name: 'aaxis_ontology_data_entity_idx')]
#[ORM\Index(columns: ['unique_id'], name: 'aaxis_ontology_data_unique_id_idx')]
#[ORM\Index(columns: ['updated_at'], name: 'aaxis_ontology_data_updated_at_idx')]
#[ORM\UniqueConstraint(name: 'aaxis_ontology_data_entity_uid_uidx', columns: ['entity_id', 'unique_id'])]
#[Config(
    defaultValues: [
        'entity' => ['icon' => 'fa-table'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyData
{
    use OntologyDataFieldsTrait;

    public function __toString(): string
    {
        return (string) $this->uniqueId;
    }
}
