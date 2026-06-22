<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Consumes {@see OntologyDataUpsertTopic} messages.
 *
 * For every message it records an entry in aaxis_ontology_data_events and then delegates the
 * actual validation and upsert work to the aaxis_ontology_data_upsert database function. Depending on
 * the function outcome it either closes the event (on validation errors) or hands the result
 * over to the next stage of the flow.
 */
class OntologyDataUpsertProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
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

        try {
            $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // Insert the inbound event (all columns but changed_ids and finished_at).
            $eventId = $this->insertEvent($connection, $body, $startedAt);

            // Delegate validation + upsert to the database function.
            $response = $this->callUpsertFunction($connection, $body);

            $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
            $hasErrors = isset($payload['errors']);

            if ($hasErrors) {
                // Log the errors as a json message with an "errors" key.
                $this->logger->error((string) json_encode(['errors' => $payload['errors']]));

                // Close the event with the finished datetime.
                $connection->update(
                    'aaxis_ontology_data_events',
                    ['finished_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))],
                    ['id' => $eventId],
                    ['finished_at' => Types::DATETIME_IMMUTABLE]
                );

                return self::ACK;
            }

            // TODO: no validation errors - publish the upsert result ($response) to the next
            // TODO: queue/topic once that destination is defined.

            return self::ACK;
        } catch (\Throwable $e) {
            $this->logger->error('aaxis_ontology_data_upsert: failed to process message.', ['exception' => $e]);

            return self::REJECT;
        }
    }

    /**
     * Inserts a row into aaxis_ontology_data_events and returns its id.
     *
     * @param array<string, mixed> $body
     */
    private function insertEvent(Connection $connection, array $body, \DateTimeInterface $startedAt): int
    {
        $uniqueIds = \is_array($body['unique_id'] ?? null) ? array_values($body['unique_id']) : [];

        return (int) $connection->fetchOne(
            'INSERT INTO aaxis_ontology_data_events (flow_id, uuid, entity_id, unique_ids, started_at)'
            . ' VALUES (?, ?, ?, ?, ?) RETURNING id',
            [
                $body['flow_id'] ?? null,
                $body['uuid'] ?? null,
                $body['entity_id'] ?? null,
                // simple_array column: stored as a comma-separated string.
                $uniqueIds === [] ? null : implode(',', array_map('strval', $uniqueIds)),
                $startedAt->format('Y-m-d H:i:s'),
            ],
            [
                \PDO::PARAM_INT,
                \PDO::PARAM_STR,
                \PDO::PARAM_INT,
                \PDO::PARAM_STR,
                \PDO::PARAM_STR,
            ]
        );
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
