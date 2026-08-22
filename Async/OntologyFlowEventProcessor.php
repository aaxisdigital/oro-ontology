<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowEventTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes queued flow-execution events into aaxis_ontology_flow_events (plain DBAL insert — the
 * table is append-only log data, no ORM lifecycle needed). Malformed messages are rejected with
 * a log line; insert failures are rejected too and never retried into a poison loop (REJECT, not
 * requeue).
 */
class OntologyFlowEventProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly BucketFlowEventStore $bucketEvents,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [OntologyFlowEventTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        $body = $message->getBody();
        if (!\is_array($body)) {
            $this->logger->error('aaxis_ontology_flow_event: message body is not an array.');

            return self::REJECT;
        }
        $event = $body['event'] ?? null;
        if (!\is_string($event) || !\in_array($event, OntologyFlowEvent::EVENTS, true)) {
            $this->logger->error('aaxis_ontology_flow_event: unknown event kind.', ['event' => $event]);

            return self::REJECT;
        }

        try {
            // "Use Bucket for Flow Events": the event lands on the bucket instead of the table
            // (independent backends — flipping the toggle migrates nothing either way).
            if ($this->bucketEvents->isEnabled()) {
                $this->bucketEvents->append(
                    isset($body['flow_id']) && \is_numeric($body['flow_id']) ? (int) $body['flow_id'] : null,
                    \is_string($body['flow_name'] ?? null) ? mb_substr($body['flow_name'], 0, 128) : null,
                    \is_string($body['flow_uuid'] ?? null) ? $body['flow_uuid'] : null,
                    $event,
                    \is_string($body['datetime'] ?? null)
                        ? $body['datetime']
                        : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                    \is_array($body['payload'] ?? null) ? $body['payload'] : []
                );

                return self::ACK;
            }

            $this->doctrine->getConnection()->executeStatement(
                'INSERT INTO aaxis_ontology_flow_events (flow_id, flow_uuid, flow_name, event, datetime, payload)
                 VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb))',
                [
                    isset($body['flow_id']) && \is_numeric($body['flow_id']) ? (int) $body['flow_id'] : null,
                    \is_string($body['flow_uuid'] ?? null) ? $body['flow_uuid'] : null,
                    \is_string($body['flow_name'] ?? null) ? mb_substr($body['flow_name'], 0, 128) : null,
                    $event,
                    \is_string($body['datetime'] ?? null)
                        ? $body['datetime']
                        : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                    json_encode(\is_array($body['payload'] ?? null) ? $body['payload'] : []),
                ]
            );

            return self::ACK;
        } catch (\Throwable $e) {
            $this->logger->error('aaxis_ontology_flow_event: failed to store the event.', ['exception' => $e]);

            return self::REJECT;
        }
    }
}
