<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\ConfigField;

/**
 * Ontology "System": a named system, with an optional logo, that owns entities and
 * connectors.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_system')]
#[Config(
    routeName: 'aaxis_ontology_systems',
    defaultValues: [
        'entity' => ['icon' => 'fa-server'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologySystem
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 64)]
    private ?string $name = null;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\OneToOne(targetEntity: File::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\JoinColumn(name: 'logo_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ConfigField(defaultValues: ['attachment' => ['acl_protected' => false, 'width' => 128, 'height' => 128, 'maxsize' => 5]])]
    private ?File $logo = null;

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

    public function getLogo(): ?File
    {
        return $this->logo;
    }

    public function setLogo(?File $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
