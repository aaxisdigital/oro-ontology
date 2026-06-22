<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Ontology "Flow": a named, toggleable pipeline whose ordered steps are stored as
 * JSON.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_flow')]
#[ORM\UniqueConstraint(name: 'aaxis_ontology_flow_name_uidx', columns: ['name'])]
#[Config(
    defaultValues: [
        'entity' => ['icon' => 'fa-sitemap'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyFlow
{
    /** Flow used by the back-office "Add Data" button in the Data View. */
    public const string NAME_MANUAL = 'Manual';

    /** Flow used by the Ontology REST API endpoints. */
    public const string NAME_REST_API = 'Ontology REST API';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 128)]
    private ?string $name = null;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'steps', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $steps = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function getSteps(): ?array
    {
        return $this->steps;
    }

    /**
     * @param array<int|string, mixed>|null $steps
     */
    public function setSteps(?array $steps): self
    {
        $this->steps = $steps;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
