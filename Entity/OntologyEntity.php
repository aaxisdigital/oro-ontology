<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Ontology "Entity": a named data entity belonging to a {@see OntologySystem}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_entity')]
#[Config(
    routeName: 'aaxis_ontology_entities',
    defaultValues: [
        'entity' => ['icon' => 'fa-cubes'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OntologySystem::class)]
    #[ORM\JoinColumn(name: 'system_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?OntologySystem $system = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 128)]
    private ?string $name = null;

    /**
     * Name of the attribute (from {@see $attributes}) whose value identifies a record of this
     * entity, i.e. the attribute used as the unique_id in data operations.
     */
    #[ORM\Column(name: 'unique_attribute', type: Types::STRING, length: 100)]
    private ?string $uniqueAttribute = null;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    /**
     * Keep this entity's records in the DATABASE even while the global "Use Bucket for Entity
     * Data" toggle is on — the per-entity escape hatch for hot entities where the bucket's
     * LIST+GET-per-record reads are too slow.
     */
    #[ORM\Column(name: 'force_db_storage', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $forceDbStorage = false;

    /**
     * @var Collection<int, OntologyEntityAttribute>
     */
    #[ORM\OneToMany(
        mappedBy: 'entity',
        targetEntity: OntologyEntityAttribute::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $attributes;

    public function __construct()
    {
        $this->attributes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSystem(): ?OntologySystem
    {
        return $this->system;
    }

    public function setSystem(?OntologySystem $system): self
    {
        $this->system = $system;

        return $this;
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

    public function getUniqueAttribute(): ?string
    {
        return $this->uniqueAttribute;
    }

    public function setUniqueAttribute(?string $uniqueAttribute): self
    {
        $this->uniqueAttribute = $uniqueAttribute;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isForceDbStorage(): bool
    {
        return $this->forceDbStorage;
    }

    public function setForceDbStorage(bool $forceDbStorage): self
    {
        $this->forceDbStorage = $forceDbStorage;

        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * @return Collection<int, OntologyEntityAttribute>
     */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    public function addAttribute(OntologyEntityAttribute $attribute): self
    {
        if (!$this->attributes->contains($attribute)) {
            $this->attributes->add($attribute);
            $attribute->setEntity($this);
        }

        return $this;
    }

    public function removeAttribute(OntologyEntityAttribute $attribute): self
    {
        if ($this->attributes->removeElement($attribute)) {
            if ($attribute->getEntity() === $this) {
                $attribute->setEntity(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
