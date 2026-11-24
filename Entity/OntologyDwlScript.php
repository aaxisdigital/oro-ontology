<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * A script saved in the Data Playground page: its DWL source plus the playground METADATA that is
 * not part of the script itself — whether it is public, the INPUTS it runs against by default (each
 * an entity bound to the script under a variable name; the page's run-time picks start from them)
 * and the default max rows read per input. How the result is rendered is the script's own business
 * (its `output` header).
 *
 * Scripts are PRIVATE to their owner; a PUBLIC one is listed (and runnable) for everyone, but only
 * its owner changes or deletes it. Names are unique per owner. Schema: {@see \Aaxis\Bundle\OntologyBundle\Migrations\Schema\DwlScriptTable}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_dwl_script')]
#[Config(
    defaultValues: [
        'entity' => ['icon' => 'fa-code'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyDwlScript
{
    public const int NAME_MAX_LENGTH = 128;

    /** The max rows a new script starts with (null = every record). */
    public const int DEFAULT_LIMIT = 100;

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 128)]
    private string $name = '';

    #[ORM\Column(name: 'script', type: Types::TEXT)]
    private string $script = '';

    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $public = false;

    /**
     * The default inputs — `[{entityId, name}]` ({@see \Aaxis\Bundle\OntologyBundle\Manager\DwlInputs}).
     * Entity ids are not foreign keys: an input whose entity is gone is dropped when the page loads.
     *
     * @var list<array{entityId: int, name: string}>
     */
    #[ORM\Column(name: 'inputs', type: Types::JSON, options: ['jsonb' => true])]
    private array $inputs = [];

    /** Default max rows read per input (null = every record). */
    #[ORM\Column(name: 'row_limit', type: Types::INTEGER, nullable: true)]
    private ?int $rowLimit = self::DEFAULT_LIMIT;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = clone $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getScript(): string
    {
        return $this->script;
    }

    public function setScript(string $script): self
    {
        $this->script = $script;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): self
    {
        $this->public = $public;

        return $this;
    }

    /**
     * @return list<array{entityId: int, name: string}>
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    /**
     * @param list<array{entityId: int, name: string}> $inputs
     */
    public function setInputs(array $inputs): self
    {
        $this->inputs = $inputs;

        return $this;
    }

    public function getRowLimit(): ?int
    {
        return $this->rowLimit;
    }

    public function setRowLimit(?int $rowLimit): self
    {
        $this->rowLimit = $rowLimit;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    /** Stamps the last change (every save). */
    public function touch(): self
    {
        $this->updatedAt = new \DateTime('now', new \DateTimeZone('UTC'));

        return $this;
    }

    /** Whether `$user` owns this script (only the owner changes or deletes it). */
    public function isOwnedBy(?User $user): bool
    {
        if ($user === null || $this->owner === null) {
            return false;
        }

        return $this->owner === $user || ($user->getId() !== null && $this->owner->getId() === $user->getId());
    }
}
