<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
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
        private readonly BucketEntityDataStore $bucketStore,
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

            // Delegate validation + upsert to the database function — or, while "Use Bucket for
            // Entity Data" is on, write the batch to the bucket store instead (same semantics:
            // deep-merge, unchanged skipped, history archived; see BucketEntityDataStore).
            $entityRef = $entityId !== null
                ? $this->doctrine->getRepository(OntologyEntity::class)->find($entityId)
                : null;
            if ($entityRef !== null && $entityRef->getSystem()?->isExternal()
                && !$entityRef->isForceDbStorage() && $this->bucketStore->isEnabled()) {
                $outcome = $this->bucketUpsert($body, $entityRef);
            } else {
                $outcome = OntologyDataApiManager::parseUpsertResponse($this->callUpsertFunction($connection, $body));
            }

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
     * The bucket-backed arm: mirrors the PG function's message validation (the producer already
     * validated the batch, so these guards only catch a broken/foreign message) and returns the
     * same outcome shape parseUpsertResponse produces — errors abort the batch, changed lists the
     * ids actually written.
     *
     * @param array<string, mixed> $body
     *
     * @return array{errors: array<int, string>|null, changed: array<int, string>}
     */
    private function bucketUpsert(array $body, OntologyEntity $entity): array
    {
        $uuid = (string) ($body['uuid'] ?? '');
        $uniqueIds = \is_array($body['unique_id'] ?? null) ? array_values($body['unique_id']) : null;
        $payloads = \is_array($body['payload'] ?? null) ? array_values($body['payload']) : null;

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return ['errors' => ['invalid uuid'], 'changed' => []];
        }
        if ($uniqueIds === null || $payloads === null || \count($uniqueIds) !== \count($payloads)) {
            return ['errors' => ['mismatch between unique_id and payload sizes'], 'changed' => []];
        }
        $ids = array_map(static fn ($id): string => trim((string) $id), $uniqueIds);
        if (\in_array('', $ids, true)) {
            return ['errors' => ['unique_id value cannot be empty/null'], 'changed' => []];
        }
        if (\count($ids) !== \count(array_unique($ids))) {
            return ['errors' => ['cannot received duplicated unique_ids in a single operation'], 'changed' => []];
        }
        foreach ($payloads as $payload) {
            if (!\is_array($payload)) {
                return ['errors' => ['invalid payload format'], 'changed' => []];
            }
        }

        $updatedAt = date_create_immutable((string) ($body['updated_at'] ?? ''), new \DateTimeZone('UTC'))
            ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'errors' => null,
            'changed' => $this->bucketStore->upsertBatch($entity, $uuid, $ids, $payloads, $updatedAt),
        ];
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
