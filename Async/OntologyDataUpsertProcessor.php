<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Consumes {@see OntologyDataUpsertTopic} messages.
 *
 * For every message it opens an entry in aaxis_ontology_data_events and then delegates the actual
 * validation and upsert work to the aaxis_ontology_data_upsert database function. The event is
 * CLOSED either way — with the ids the function actually wrote on success, with none when it
 * rejected the batch — through {@see OntologyDataEventRecorder}, the same writer the synchronous
 * path uses, so both report identically on the Events page.
 */
class OntologyDataUpsertProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly OntologyFlowEventEmitter $flowEvents,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        // Linked to the default queue (Config::DEFAULT_QUEUE_NAME -> "oro.default").
        return [OntologyDataUpsertTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        $body = $message->getBody();
        if (!\is_array($body)) {
            $this->logger->error('aaxis_ontology_data_upsert: message body is not an array.');

            return self::REJECT;
        }

        /** @var Connection $connection */
        $connection = $this->doctrine->getConnection();

        $flowId = null;
        $flowName = null;
        $uuid = null;
        try {
            // Reject a malformed message BEFORE emitting anything: casting garbage would bury a
            // broken producer as flow_id 0 / uuid "Array" in events that look legitimate.
            $flowId = $this->asId($body['flow_id'] ?? null, 'flow_id');
            $entityId = $this->asId($body['entity_id'] ?? null, 'entity_id');
            $uuid = $body['uuid'] ?? null;
            if ($uuid !== null && !\is_string($uuid)) {
                throw new \InvalidArgumentException('uuid must be a string, got ' . get_debug_type($uuid));
            }
            $flowName = $flowId !== null
                ? $this->doctrine->getRepository(OntologyFlow::class)->find($flowId)?->getName()
                : null;

            // Delegate validation + upsert to the database function.
            $outcome = OntologyDataApiManager::parseUpsertResponse($this->callUpsertFunction($connection, $body));

            if ($outcome['errors'] !== null) {
                // A rejected batch surfaces as a flow-exception event (and the log).
                $this->logger->error((string) json_encode(['errors' => $outcome['errors']]));
                $this->flowEvents->emitRaw($flowId, $flowName, $uuid, 'flow-exception', [
                    'message' => implode('; ', $outcome['errors']),
                ]);

                return self::ACK;
            }

            $entityName = $entityId !== null
                ? (string) $this->doctrine->getRepository(OntologyEntity::class)->find($entityId)?->getName()
                : '';
            $this->flowEvents->emitRaw($flowId, $flowName, $uuid, 'data-upsert', [
                'entity' => $entityName,
                'uniqueIds' => \is_array($body['unique_id'] ?? null) ? array_values($body['unique_id']) : [],
                'changedIds' => $outcome['changed'],
            ]);

            return self::ACK;
        } catch (\Throwable $e) {
            // A crash (a database failure included) is a failed run like any other: it surfaces
            // as a flow-exception event, not just a log line.
            $this->logger->error('aaxis_ontology_data_upsert: failed to process message.', ['exception' => $e]);
            $this->flowEvents->emitRaw($flowId, $flowName, \is_string($uuid) ? $uuid : null, 'flow-exception', [
                'message' => $e->getMessage(),
            ]);

            return self::REJECT;
        }
    }

    /**
     * A message id column (flow_id / entity_id): null when absent, an int when numeric, and a hard
     * failure otherwise — the message is then REJECTed and logged instead of silently landing as 0.
     */
    private function asId(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be numeric, got %s', $field, get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * Calls aaxis_ontology_data_upsert with the inbound message and returns the decoded json response.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function callUpsertFunction(Connection $connection, array $body): array
    {
        $input = json_encode([
            'flow_id' => $body['flow_id'] ?? null,
            'uuid' => $body['uuid'] ?? null,
            'entity_id' => $body['entity_id'] ?? null,
            'unique_id' => $body['unique_id'] ?? [],
            'updated_at' => $body['updated_at'] ?? null,
            'payload' => $body['payload'] ?? [],
        ], JSON_THROW_ON_ERROR);

        $raw = $connection->fetchOne('SELECT aaxis_ontology_data_upsert(CAST(? AS jsonb))', [$input]);

        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
