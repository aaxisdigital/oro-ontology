<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Ontology "Connector": a named integration (SFTP / REST API / File System) belonging
 * to a {@see OntologySystem}. The per-type configuration is stored as JSON (detailed later).
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_connector')]
#[Config(
    routeName: 'aaxis_ontology_connectors',
    routeView: 'aaxis_ontology_connector_view',
    routeUpdate: 'aaxis_ontology_connector_update',
    defaultValues: [
        'entity' => ['icon' => 'fa-plug'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyConnector
{
    public const string TYPE_SFTP = 'sftp';
    public const string TYPE_REST_API = 'rest_api';
    public const string TYPE_FILE_SYSTEM = 'file_system';

    public const array TYPES = [self::TYPE_SFTP, self::TYPE_REST_API, self::TYPE_FILE_SYSTEM];

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OntologySystem::class)]
    #[ORM\JoinColumn(name: 'system_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?OntologySystem $system = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 128)]
    private ?string $name = null;

    #[ORM\Column(name: 'type', type: Types::STRING, length: 32)]
    private ?string $type = self::TYPE_SFTP;

    #[ORM\Column(name: 'config', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $config = null;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function setConfig(?array $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
