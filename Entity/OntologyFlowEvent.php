<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Ontology "Flow Event": one row per flow-execution event, written ASYNCHRONOUSLY by
 * {@see \Aaxis\Bundle\OntologyBundle\Async\OntologyFlowEventProcessor} from the messages
 * {@see \Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter} queues — logging never
 * blocks a running flow.
 *
 * Event kinds: flow-start (payload {trigger, user?}), flow-finish ({}), flow-exception
 * ({message}), data-upsert ({entity, uniqueIds, changedIds}), log-message ({message} — the Event
 * notification step) and step ({name, type} — one per executed step). flowId/flowName are plain
 * copies (NO foreign key): the execution record survives the flow being renamed or deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'aaxis_ontology_flow_events')]
#[ORM\Index(columns: ['flow_id'], name: 'aaxis_ontology_flow_events_flow_idx')]
#[ORM\Index(columns: ['flow_uuid'], name: 'aaxis_ontology_flow_events_uuid_idx')]
#[ORM\Index(columns: ['datetime'], name: 'aaxis_ontology_flow_events_datetime_idx')]
#[Config(
    defaultValues: [
        'entity' => ['icon' => 'fa-bolt'],
        'security' => ['type' => 'ACL', 'group_name' => ''],
    ]
)]
class OntologyFlowEvent
{
    public const array EVENTS = [
        'flow-start', 'flow-finish', 'subflow-start', 'subflow-finish',
        'flow-exception', 'data-upsert', 'log-message', 'step',
    ];

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'flow_id', type: Types::INTEGER, nullable: true)]
    private ?int $flowId = null;

    #[ORM\Column(name: 'flow_uuid', type: Types::STRING, length: 36, nullable: true)]
    private ?string $flowUuid = null;

    #[ORM\Column(name: 'flow_name', type: Types::STRING, length: 128, nullable: true)]
    private ?string $flowName = null;

    #[ORM\Column(name: 'event', type: Types::STRING, length: 32)]
    private ?string $event = null;

    /**
     * Mapped as a STRING deliberately: the column is TIMESTAMP(6) (microseconds — see the
     * migration) and Oro's global UTCDateTimeType override hydrates `datetime` columns with a
     * STRICT 'Y-m-d H:i:s' createFromFormat that rejects fractional seconds. The raw value
     * ("2026-08-21 17:26:59.257448") is what consumers get; parse with date_create() as needed.
     */
    #[ORM\Column(name: 'datetime', type: Types::STRING, length: 32)]
    private ?string $datetime = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'payload', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $payload = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFlowId(): ?int
    {
        return $this->flowId;
    }

    public function getFlowUuid(): ?string
    {
        return $this->flowUuid;
    }

    public function getFlowName(): ?string
    {
        return $this->flowName;
    }

    public function getEvent(): ?string
    {
        return $this->event;
    }

    public function getDatetime(): ?string
    {
        return $this->datetime;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }
}
