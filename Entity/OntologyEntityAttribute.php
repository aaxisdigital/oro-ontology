<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ontology "Entity Attribute": a named, typed attribute belonging to a {@see OntologyEntity}
 * (1:N — an entity owns many attributes).
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_entity_attribute')]
class OntologyEntityAttribute
{
    public const string TYPE_BOOLEAN = 'boolean';
    public const string TYPE_TEXT = 'text';
    public const string TYPE_NUMBER = 'number';
    public const string TYPE_DATE = 'date';
    public const string TYPE_TIME = 'time';
    public const string TYPE_DATETIME = 'datetime';
    public const string TYPE_OBJECT = 'object';
    public const string TYPE_UNDEFINED = 'undefined';

    public const array TYPES = [
        self::TYPE_BOOLEAN,
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_TIME,
        self::TYPE_DATETIME,
        self::TYPE_OBJECT,
        self::TYPE_UNDEFINED,
    ];

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OntologyEntity::class, inversedBy: 'attributes')]
    #[ORM\JoinColumn(name: 'entity_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?OntologyEntity $entity = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100)]
    private ?string $name = null;

    #[ORM\Column(name: 'datatype', type: Types::STRING, length: 32, options: ['default' => self::TYPE_UNDEFINED])]
    private string $datatype = self::TYPE_UNDEFINED;

    #[ORM\Column(name: 'required', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $required = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntity(): ?OntologyEntity
    {
        return $this->entity;
    }

    public function setEntity(?OntologyEntity $entity): self
    {
        $this->entity = $entity;

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

    public function getDatatype(): string
    {
        return $this->datatype;
    }

    public function setDatatype(string $datatype): self
    {
        $this->datatype = $datatype;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
