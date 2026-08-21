<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowEventTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues flow-execution events (one MQ message per event; the datetime is stamped HERE, at emit
 * time — the consumer may write much later). Emission must never break a running flow: producer
 * failures are logged and swallowed.
 *
 * Event kinds and payloads: see {@see \Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent}.
 */
class OntologyFlowEventEmitter
{
    public function __construct(
        private readonly MessageProducerInterface $producer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(?OntologyFlow $flow, ?string $flowUuid, string $event, array $payload = []): void
    {
        $this->emitRaw($flow?->getId(), $flow?->getName(), $flowUuid, $event, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function emitRaw(?int $flowId, ?string $flowName, ?string $flowUuid, string $event, array $payload = []): void
    {
        try {
            $this->producer->send(OntologyFlowEventTopic::getName(), [
                'flow_id' => $flowId,
                'flow_uuid' => $flowUuid,
                'flow_name' => $flowName,
                'event' => $event,
                'datetime' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('aaxis_ontology_flow_event: could not queue the event.', ['exception' => $e]);
        }
    }
}
