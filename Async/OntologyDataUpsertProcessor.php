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
            $entity = $entityId !== null
                ? $this->doctrine->getRepository(OntologyEntity::class)->find($entityId)
                : null;

            $this->report($this->upsert($body, $entity), $body, $entity, $flowId, $flowName, $uuid);

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
     * Delegates validation + upsert to the database function — or, while "Use Bucket for Entity
     * Data" is on, writes the batch to the bucket store instead (same semantics: deep-merge,
     * unchanged skipped, history archived; see BucketEntityDataStore).
     *
     * @param array<string, mixed> $body
     *
     * @return array{errors: array<int, string>|null, changed: array<int, string>}
     */
    private function upsert(array $body, ?OntologyEntity $entity): array
    {
        if ($entity !== null && $entity->getSystem()?->isExternal()
            && !$entity->isForceDbStorage() && $this->bucketStore->isEnabled()) {
            return $this->bucketUpsert($body, $entity);
        }

        /** @var Connection $connection */
        $connection = $this->doctrine->getConnection();

        return OntologyDataApiManager::parseUpsertResponse($this->callUpsertFunction($connection, $body));
    }

    /**
     * Records the batch's outcome on the flow's event trail: a rejected batch surfaces as a
     * flow-exception event (and the log), a written one as a data-upsert event.
     *
     * @param array{errors: array<int, string>|null, changed: array<int, string>} $outcome
     * @param array<string, mixed>                                                $body
     */
    private function report(array $outcome, array $body, ?OntologyEntity $entity, ?int $flowId, ?string $flowName, ?string $uuid): void
    {
        if ($outcome['errors'] !== null) {
            $this->logger->error((string) json_encode(['errors' => $outcome['errors']]));
            $this->flowEvents->emitRaw($flowId, $flowName, $uuid, 'flow-exception', [
                'message' => implode('; ', $outcome['errors']),
            ]);

            return;
        }

        $this->flowEvents->emitRaw($flowId, $flowName, $uuid, 'data-upsert', [
            'entity' => (string) $entity?->getName(),
            'uniqueIds' => \is_array($body['unique_id'] ?? null) ? array_values($body['unique_id']) : [],
            'changedIds' => $outcome['changed'],
        ]);
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

        $ids = [];
        $error = $this->batchShapeError($uuid, $uniqueIds, $payloads);
        if ($error === null) {
            $ids = array_map(static fn ($id): string => trim((string) $id), $uniqueIds);
            $error = $this->batchContentError($ids, $payloads);
        }
        if ($error !== null) {
            return ['errors' => [$error], 'changed' => []];
        }

        $updatedAt = date_create_immutable((string) ($body['updated_at'] ?? ''), new \DateTimeZone('UTC'))
            ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'errors' => null,
            'changed' => $this->bucketStore->upsertBatch($entity, $uuid, $ids, $payloads, $updatedAt),
        ];
    }

    /**
     * @param array<int, mixed>|null $uniqueIds
     * @param array<int, mixed>|null $payloads
     */
    private function batchShapeError(string $uuid, ?array $uniqueIds, ?array $payloads): ?string
    {
        return match (true) {
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) => 'invalid uuid',
            $uniqueIds === null || $payloads === null || \count($uniqueIds) !== \count($payloads)
                => 'mismatch between unique_id and payload sizes',
            default => null,
        };
    }

    /**
     * @param array<int, string> $ids
     * @param array<int, mixed>  $payloads
     */
    private function batchContentError(array $ids, array $payloads): ?string
    {
        return match (true) {
            \in_array('', $ids, true) => 'unique_id value cannot be empty/null',
            \count($ids) !== \count(array_unique($ids)) => 'cannot received duplicated unique_ids in a single operation',
            array_filter($payloads, static fn ($payload): bool => !\is_array($payload)) !== [] => 'invalid payload format',
            default => null,
        };
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
