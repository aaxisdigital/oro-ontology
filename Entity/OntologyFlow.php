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

    /** Built-in flow seeded by the data fixture; read-only in the UI. */
    public const string TYPE_NATIVE = 'native';

    /** User-created flow that contains a trigger step. */
    public const string TYPE_FLOW = 'flow';

    /**
     * User-created flow whose trigger is the "Subflow" element (invoked from other flows via
     * "Call Subflow") — or, legacy, one without any trigger step.
     */
    public const string TYPE_SUBFLOW = 'subflow';

    /**
     * Toolbox step types that count as triggers (drive {@see computeType} and the per-trigger
     * `enabled` flag). `subflow` is the entry-point of a callable subflow — a step TYPE, not to be
     * confused with the flow TYPE {@see TYPE_SUBFLOW} or the "Call Subflow" ACTION `sub_flow`.
     */
    public const array TRIGGER_STEP_TYPES = ['cron', 'endpoint', 'entity_change', 'subflow'];

    /**
     * Canvas format of the `design` column. MUST track DESIGN_VERSION in
     * Resources/js-src/app/components/flow-editor-component.ts: the editor treats a design of any
     * other version as corrupted and opens an EMPTY canvas, so an imported flow carrying one would
     * still be scheduled and executed (the runner reads design.steps) while being uneditable.
     */
    public const int DESIGN_VERSION = 2;

    /** Every step type the editor toolbox offers (triggers + actions + operations). */
    public const array STEP_TYPES = [
        'cron', 'endpoint', 'entity_change', 'subflow', // triggers (subflow = callable entry point)
        // Flow Control ("choice" acts as an if; `foreach` is a PLACEHOLDER, not implemented yet).
        'choice', 'sub_flow', 'foreach',
        // Operations. entity_read/entity_write are the typed successors of the REMOVED generic
        // reader/writer (v1_10's ConvertLegacyReaderWriterSteps rewrote stored flows); `invoke`
        // is the "HTTP Request" step (rest_api connectors only); `sql_query` runs SQL against
        // database connectors.
        'dwl_transform', 'invoke', 'entity_read', 'entity_write', 'sql_query',
        // File Operations (file_system/sftp/bucket connectors, DWL-capable paths).
        'file_read', 'file_write', 'file_list', 'file_delete', 'file_rename',
    ];

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 128)]
    private ?string $name = null;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    /**
     * `native` for the two built-in flows seeded by the data fixture ({@see NAME_MANUAL},
     * {@see NAME_REST_API}) — those are read-only in the UI. User-created flows are `flow` when
     * their steps contain a trigger and `subflow` otherwise (recomputed from the steps on every
     * save, see {@see computeType}); the save endpoints never take the type from the payload.
     */
    #[ORM\Column(name: 'type', type: Types::STRING, length: 16, options: ['default' => self::TYPE_SUBFLOW])]
    private string $type = self::TYPE_SUBFLOW;

    #[ORM\Column(name: 'steps', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $steps = null;

    /**
     * The editor's canvas representation (versioned: step tiles + toolbox state), owned by the
     * flow editor UI. Kept separate from `steps` (the logical step list): the editor restores
     * from `design` and treats an unreadable/outdated value as corrupted, starting empty.
     */
    #[ORM\Column(name: 'design', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $design = null;

    /**
     * The flow's trigger step type (`cron` | `queue` | `entity_change`), or null when it has none
     * (subflows, the native flows). Denormalized from the steps on every save so the scheduler
     * can select candidate flows with a plain indexed-column WHERE instead of scanning the JSON.
     */
    #[ORM\Column(name: 'trigger_type', type: Types::STRING, length: 16, nullable: true)]
    private ?string $triggerType = null;

    /** When the flow last RAN (debug / Run Now / the scheduler); null = never. */
    #[ORM\Column(name: 'last_executed', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastExecuted = null;

    /**
     * When the last run ENDED — stamped on success AND on failure, so it always eventually catches
     * up with {@see $lastExecuted}. Together the pair is the running-state signal used to keep a
     * flow from being started while one of its instances is still in flight
     * ({@see isRunning()}); null = never finished a run.
     */
    #[ORM\Column(name: 'last_finished', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastFinished = null;

    /** Never null: the creation date at first, bumped by every save that rewrites the flow. */
    #[ORM\Column(name: 'last_modified', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $lastModified = null;

    public function __construct()
    {
        $this->lastModified = new \DateTime('now', new \DateTimeZone('UTC'));
    }

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

    public function getType(): string
    {
        return $this->type;
    }

    /** Set TYPE_NATIVE only from the seeding fixture; user flows get {@see computeType}'s result. */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isNative(): bool
    {
        return $this->type === self::TYPE_NATIVE;
    }

    /**
     * The type of a user-created flow follows from its trigger: a REAL trigger (cron / endpoint /
     * entity_change) makes it a `flow`; the "Subflow" trigger — or, legacy, no trigger at all —
     * makes it a `subflow`.
     *
     * @param array<int, array<string, mixed>>|null $steps
     */
    public static function computeType(?array $steps): string
    {
        $trigger = self::computeTriggerType($steps);

        return $trigger === null || $trigger === 'subflow' ? self::TYPE_SUBFLOW : self::TYPE_FLOW;
    }

    /**
     * The flow's effective ENABLED state, derived from its trigger step's `enabled` config flag:
     * true only when a trigger exists AND carries `enabled: true`. A missing trigger, an
     * unconfigured one or a missing flag all read as DISABLED — a broken entry point must not run.
     *
     * @param array<int, array<string, mixed>>|null $steps
     */
    public static function computeEnabled(?array $steps): bool
    {
        foreach ($steps ?? [] as $step) {
            if (\is_array($step) && \in_array($step['type'] ?? null, self::TRIGGER_STEP_TYPES, true)) {
                return \is_array($step['config'] ?? null) && ($step['config']['enabled'] ?? null) === true;
            }
        }

        return false;
    }

    /**
     * The flow's trigger step type (first trigger found in the steps), or null when it has none.
     *
     * @param array<int, array<string, mixed>>|null $steps
     */
    public static function computeTriggerType(?array $steps): ?string
    {
        foreach ($steps ?? [] as $step) {
            if (\is_array($step) && \in_array($step['type'] ?? null, self::TRIGGER_STEP_TYPES, true)) {
                return (string) $step['type'];
            }
        }

        return null;
    }

    public function getTriggerType(): ?string
    {
        return $this->triggerType;
    }

    public function setTriggerType(?string $triggerType): self
    {
        $this->triggerType = $triggerType;

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

    /**
     * @return array<int|string, mixed>|null
     */
    public function getDesign(): ?array
    {
        return $this->design;
    }

    /**
     * @param array<int|string, mixed>|null $design
     */
    public function setDesign(?array $design): self
    {
        $this->design = $design;

        return $this;
    }

    public function getLastExecuted(): ?\DateTimeInterface
    {
        return $this->lastExecuted;
    }

    public function setLastExecuted(?\DateTimeInterface $lastExecuted): self
    {
        $this->lastExecuted = $lastExecuted;

        return $this;
    }

    public function getLastFinished(): ?\DateTimeInterface
    {
        return $this->lastFinished;
    }

    public function setLastFinished(?\DateTimeInterface $lastFinished): self
    {
        $this->lastFinished = $lastFinished;

        return $this;
    }

    /**
     * Whether a run is currently in flight: it started and has not reported finishing yet.
     *
     * Raw state only — a process killed mid-run (fatal, container restart) never stamps
     * `last_finished` and would look "running" forever, so callers that BLOCK on this must also
     * apply a staleness cut-off (see `ScheduledFlowRunner::RUN_STALE_AFTER_SECONDS`).
     */
    public function isRunning(): bool
    {
        if ($this->lastExecuted === null) {
            return false;
        }

        return $this->lastFinished === null
            || $this->lastFinished->getTimestamp() < $this->lastExecuted->getTimestamp();
    }

    public function getLastModified(): ?\DateTimeInterface
    {
        return $this->lastModified;
    }

    public function setLastModified(\DateTimeInterface $lastModified): self
    {
        $this->lastModified = $lastModified;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
