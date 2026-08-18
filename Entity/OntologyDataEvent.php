<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Ontology "Data Event": records a single flow execution that touched Ontology data,
 * tracking which unique ids were seen and which ones actually changed, together with the
 * window in which the flow ran.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_data_events')]
#[ORM\Index(columns: ['flow_id'], name: 'aaxis_ontology_data_events_flow_idx')]
#[ORM\Index(columns: ['entity_id'], name: 'aaxis_ontology_data_events_entity_idx')]
#[ORM\Index(columns: ['started_at'], name: 'aaxis_ontology_data_events_started_at_idx')]
#[Config(
    defaultValues: [
        'entity' => ['icon' => 'fa-bolt'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyDataEvent
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'flow_id', type: Types::INTEGER)]
    private ?int $flowId = null;

    #[ORM\Column(name: 'uuid', type: Types::STRING, length: 36)]
    private ?string $uuid = null;

    #[ORM\Column(name: 'entity_id', type: Types::INTEGER, nullable: true)]
    private ?int $entityId = null;

    /**
     * @var string[]|null
     */
    #[ORM\Column(name: 'unique_ids', type: Types::SIMPLE_ARRAY, nullable: true)]
    private ?array $uniqueIds = null;

    /**
     * @var string[]|null
     */
    #[ORM\Column(name: 'changed_ids', type: Types::SIMPLE_ARRAY, nullable: true)]
    private ?array $changedIds = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $finishedAt = null;

    /**
     * How the run FAILED, when it did — null on success. The run's status is derived, never
     * stored: finished_at null = running, finished with error null = success, else failure.
     */
    #[ORM\Column(name: 'error', type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFlowId(): ?int
    {
        return $this->flowId;
    }

    public function setFlowId(?int $flowId): self
    {
        $this->flowId = $flowId;

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

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): self
    {
        $this->entityId = $entityId;

        return $this;
    }

    /**
     * @return string[]|null
     */
    public function getUniqueIds(): ?array
    {
        return $this->uniqueIds;
    }

    /**
     * @param string[]|null $uniqueIds
     */
    public function setUniqueIds(?array $uniqueIds): self
    {
        $this->uniqueIds = $uniqueIds;

        return $this;
    }

    /**
     * @return string[]|null
     */
    public function getChangedIds(): ?array
    {
        return $this->changedIds;
    }

    /**
     * @param string[]|null $changedIds
     */
    public function setChangedIds(?array $changedIds): self
    {
        $this->changedIds = $changedIds;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->uuid;
    }
}
