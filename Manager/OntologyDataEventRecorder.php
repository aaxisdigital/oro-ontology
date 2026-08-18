<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The single writer of `aaxis_ontology_data_events` — the run log every data upsert produces.
 *
 * BOTH upsert paths go through it so their event rows can never drift apart again:
 *  - synchronous ({@see OntologyDataApiManager::upsertRecordsSync}, used by flow writer steps),
 *  - asynchronous ({@see \Aaxis\Bundle\OntologyBundle\Async\OntologyDataUpsertProcessor}, used by
 *    the back-office "Add Data" (Manual flow) and the REST API).
 *
 * An event is {@see open}ed when the batch starts and {@see close}d as soon as the
 * `aaxis_ontology_data_upsert` function answers — with the ids it actually created/updated on
 * success, with none on a validation failure. Reading that answer is {@see parseUpsertResponse}'s
 * job, the one place that knows the function's response shape.
 */
class OntologyDataEventRecorder
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * Opens an event row (start time + the ids the batch carries) and returns its id.
     *
     * @param array<int, string> $uniqueIds
     */
    public function open(?int $flowId, ?string $uuid, ?int $entityId, array $uniqueIds, \DateTimeInterface $startedAt): int
    {
        return (int) $this->connection()->fetchOne(
            'INSERT INTO aaxis_ontology_data_events (flow_id, uuid, entity_id, unique_ids, started_at)'
            . ' VALUES (?, ?, ?, ?, ?) RETURNING id',
            [
                $flowId,
                $uuid,
                $entityId,
                self::encodeIds($uniqueIds),
                $startedAt->format('Y-m-d H:i:s'),
            ],
            [\PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_STR]
        );
    }

    /**
     * Closes an event: the ids actually written plus the finish time. ALWAYS called once the
     * upsert function has answered — including on validation failure, where the run is over too
     * (an event left open reads as "still running" in the Events page).
     *
     * @param array<int, string> $changedIds
     */
    public function close(int $eventId, array $changedIds): void
    {
        $this->connection()->update(
            'aaxis_ontology_data_events',
            [
                'changed_ids' => self::encodeIds($changedIds),
                'finished_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
            ['id' => $eventId],
            ['changed_ids' => \PDO::PARAM_STR, 'finished_at' => \PDO::PARAM_STR, 'id' => \PDO::PARAM_INT]
        );
    }

    /**
     * Reads the `aaxis_ontology_data_upsert` response: its `payload` is either `{errors: [...]}`
     * when the batch was rejected, or a map of unique id → the applied diff, where an UNTOUCHED
     * record is marked with json null. So "changed" = the keys carrying a diff.
     *
     * @param array<string, mixed> $response the decoded function response
     *
     * @return array{changed: array<int, string>, errors: array<int, string>|null}
     */
    public function parseUpsertResponse(array $response): array
    {
        $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
        // A REJECTED batch answers with the whole payload being {errors: [messages…]} — one key,
        // holding a LIST of strings. A successful write is keyed by unique id, and each value is
        // the diff OBJECT (or null when untouched) — so the SHAPE decides, not the key. Otherwise
        // a record whose unique id is literally "errors" would read as a rejected batch: the async
        // path would log a bogus error and the sync path would throw AFTER committing the data.
        $errors = $payload['errors'] ?? null;
        if (array_keys($payload) === ['errors'] && \is_array($errors) && array_is_list($errors)
            && $errors !== [] && array_filter($errors, 'is_array') === []
        ) {
            return ['changed' => [], 'errors' => array_map('strval', $errors)];
        }

        // array_keys() hands back INTs for numeric-looking unique ids ("123" => 123) — stringify
        // before they reach the comma-joined column.
        $changed = array_keys(array_filter($payload, static fn ($diff) => $diff !== null));

        return ['changed' => array_values(array_map('strval', $changed)), 'errors' => null];
    }

    /**
     * simple_array encoding: NULL for an empty list, never an empty string — Doctrine reads ''
     * back as [''], a phantom one-element array that shows up as a count of 1 with a blank id.
     *
     * LIMITATION: simple_array joins on "," with no escaping, so a unique id CONTAINING a comma
     * reads back split into several — the column cannot represent it (the Events page would then
     * show an inflated count). Unique ids are any non-empty scalar from the payload, so this is
     * reachable with e.g. a name-like unique attribute. Fixing it properly means moving both id
     * columns to jsonb (entity + installer + a versioned migration converting existing rows).
     *
     * @param array<int, string> $ids
     */
    private static function encodeIds(array $ids): ?string
    {
        $ids = array_values(array_filter(array_map('strval', $ids), static fn (string $id) => $id !== ''));

        return $ids === [] ? null : implode(',', $ids);
    }

    private function connection(): Connection
    {
        return $this->doctrine->getConnection();
    }
}
