<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The shape of one versioned ontology data record: which entity it belongs to, its business unique
 * id, uuid, version, JSON payload and last-update time.
 *
 * {@see OntologyData} (current state) and {@see OntologyDataHistory} (every past version) are the
 * same record in two tables, so the columns and accessors live here once. Each entity keeps its own
 * table/index attributes and its own __toString(); the mapping is unchanged by this move, since both
 * classes already declared these columns identically.
 */
trait OntologyDataFieldsTrait
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OntologyEntity::class)]
    #[ORM\JoinColumn(name: 'entity_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?OntologyEntity $entity = null;

    #[ORM\Column(name: 'unique_id', type: Types::STRING, length: 255)]
    private ?string $uniqueId = null;

    #[ORM\Column(name: 'uuid', type: Types::STRING, length: 36)]
    private ?string $uuid = null;

    #[ORM\Column(name: 'version', type: Types::INTEGER)]
    private ?int $version = null;

    #[ORM\Column(name: 'payload', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $payload = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

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

    /**
     * The owning system is inferred from the entity.
     */
    public function getSystem(): ?OntologySystem
    {
        return $this->entity?->getSystem();
    }

    public function getUniqueId(): ?string
    {
        return $this->uniqueId;
    }

    public function setUniqueId(?string $uniqueId): self
    {
        $this->uniqueId = $uniqueId;

        return $this;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(?string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function setVersion(?int $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
