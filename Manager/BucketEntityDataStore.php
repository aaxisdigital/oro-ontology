<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;

/**
 * EXTERNAL-entity data storage on the configured S3 bucket — the alternative backend to the
 * aaxis_ontology_data(+_history) tables, active while "Use Bucket for Entity Data" is on.
 *
 * Object layout (the entity segment is the entity ID — names are only unique PER SYSTEM and
 * change on rename, ids never do; the unique-id segment is rawurlencoded so a "/" inside it
 * cannot fork the hierarchy):
 *
 *   latest:  {base}/entity-data/{entity-id}/{uid}.json
 *   history: {base}/entity-data-history/{entity-id}/{uid}/{yyyymmddhhmiss}/{version}/{uuid}.json
 *
 * The LATEST record deliberately lives at a FIXED key (not the timestamped shape history uses):
 * a read is ONE GET, an upsert overwrites in place (no stale "latest" object to clean up), and
 * listing entity-data/{entity}/ enumerates exactly the live records. The per-version metadata
 * (version, uuid, updatedAt) rides inside the JSON ENVELOPE each object stores:
 * {entityId, entity (name, informational), uniqueId, version, uuid, updatedAt, payload} — which
 * is also HOW AN ARCHIVE KNOWS ITS KEY: the upsert GETs the current latest anyway (for the merge
 * and the unchanged check), and that envelope carries the version/uuid/updatedAt the history key
 * is built from. History objects are FULL SNAPSHOTS of
 * that version (unlike the DB path's reverse-diffs) — self-contained retrieval was the point of
 * moving to the bucket.
 *
 * Upsert semantics mirror the aaxis_ontology_data_upsert PG function: incoming payloads DEEP-MERGE
 * into the existing one (objects merge recursively, arrays/scalars replace), a write that changes
 * nothing is skipped (not in changedIds, no version bump), a new record starts at
 * max(history)+1 and an update archives the previous snapshot at GREATEST(live, max(history)+1)
 * with the new latest at that +1 — version continuity across delete/recreate included.
 */
class BucketEntityDataStore
{
    private const LATEST_ROOT = 'entity-data';
    private const HISTORY_ROOT = 'entity-data-history';

    public function __construct(private readonly OntologyBucketClient $client)
    {
    }

    /** Whether the bucket backend is active (toggle on + connection configured). */
    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    /**
     * The latest envelope of one record, or null when it does not exist.
     *
     * @return array{entity: string, uniqueId: string, version: int, uuid: string, updatedAt: string, payload: array}|null
     */
    public function readLatest(OntologyEntity $entity, string $uniqueId): ?array
    {
        $raw = $this->client->get($this->latestKey($entity, $uniqueId));

        return $raw === null ? null : $this->decodeEnvelope($raw);
    }

    /**
     * Every live record of the entity (envelopes). One LIST plus one GET per record — fine for the
     * moderate volumes this store targets; a very large entity pays N round trips.
     *
     * @return list<array<string, mixed>>
     */
    public function listLatest(OntologyEntity $entity): array
    {
        $records = [];
        foreach ($this->client->listKeys($this->latestPrefix($entity)) as $key) {
            $raw = $this->client->get($key);
            if ($raw === null) {
                continue; // deleted between LIST and GET
            }
            $envelope = $this->decodeEnvelope($raw);
            if ($envelope !== null) {
                $records[] = $envelope;
            }
        }

        return $records;
    }

    /** Number of live records of the entity (counts keys — no GETs). */
    public function countLatest(OntologyEntity $entity): int
    {
        return \count($this->client->listKeys($this->latestPrefix($entity)));
    }

    /**
     * Upserts a validated batch (parallel ids/payloads from prepareUpsertBatch) with the PG
     * function's semantics. Returns the ids that were actually created or changed.
     *
     * @param list<string> $uniqueIds
     * @param list<array>  $payloads
     *
     * @return list<string>
     */
    public function upsertBatch(OntologyEntity $entity, string $uuid, array $uniqueIds, array $payloads, \DateTimeImmutable $updatedAt): array
    {
        $changed = [];

        foreach ($uniqueIds as $i => $uid) {
            $incoming = $payloads[$i];
            $existing = $this->readLatest($entity, $uid);
            $maxHistory = $this->maxHistoryVersion($entity, $uid);

            if ($existing === null) {
                $this->writeLatest($entity, $uid, $maxHistory + 1, $uuid, $updatedAt, $incoming);
                $changed[] = $uid;

                continue;
            }

            $merged = self::deepMerge($existing['payload'], $incoming);
            if (self::jsonEquals($merged, $existing['payload'])) {
                continue; // nothing would change — no version, no archive, not a changed id
            }

            // The archived key's date/version/uuid come from the envelope the latest object
            // itself stores — already in hand, since the merge above needed the GET anyway.
            $archiveVersion = max((int) $existing['version'], $maxHistory + 1);
            $this->client->put(
                $this->historyKey($entity, $uid, $existing['updatedAt'], $archiveVersion, $existing['uuid']),
                $this->encodeEnvelope($entity, $uid, $archiveVersion, $existing['uuid'], $existing['updatedAt'], $existing['payload'])
            );
            $this->writeLatest($entity, $uid, $archiveVersion + 1, $uuid, $updatedAt, $merged);
            $changed[] = $uid;
        }

        return $changed;
    }

    /**
     * Every version of one record, newest first — the live envelope marked current, then the
     * archived snapshots (full payloads; no diff reconstruction needed on this backend).
     *
     * @return list<array{version: int, uuid: string, updatedAt: string, current: bool, payload: array}>
     */
    public function versions(OntologyEntity $entity, string $uniqueId): array
    {
        $versions = [];
        $latest = $this->readLatest($entity, $uniqueId);
        if ($latest !== null) {
            $versions[] = [
                'version' => (int) $latest['version'],
                'uuid' => (string) $latest['uuid'],
                'updatedAt' => (string) $latest['updatedAt'],
                'current' => true,
                'payload' => $latest['payload'],
            ];
        }

        $history = [];
        foreach ($this->client->listKeys($this->historyPrefix($entity, $uniqueId)) as $key) {
            $raw = $this->client->get($key);
            $envelope = $raw === null ? null : $this->decodeEnvelope($raw);
            if ($envelope !== null) {
                $history[] = $envelope;
            }
        }
        usort($history, static fn (array $a, array $b): int => (int) $b['version'] <=> (int) $a['version']);
        foreach ($history as $envelope) {
            $versions[] = [
                'version' => (int) $envelope['version'],
                'uuid' => (string) $envelope['uuid'],
                'updatedAt' => (string) $envelope['updatedAt'],
                'current' => false,
                'payload' => $envelope['payload'],
            ];
        }

        return $versions;
    }

    /** Deletes every object of the entity — live records AND history. Returns the live count. */
    public function purgeEntity(OntologyEntity $entity): int
    {
        $latestKeys = $this->client->listKeys($this->latestPrefix($entity));
        foreach ($latestKeys as $key) {
            $this->client->delete($key);
        }
        foreach ($this->client->listKeys($this->historyRootPrefix($entity)) as $key) {
            $this->client->delete($key);
        }

        return \count($latestKeys);
    }

    // --- Keys -------------------------------------------------------------------------------

    private function latestPrefix(OntologyEntity $entity): string
    {
        return $this->prefixed(self::LATEST_ROOT . '/' . (int) $entity->getId() . '/');
    }

    private function latestKey(OntologyEntity $entity, string $uniqueId): string
    {
        return $this->latestPrefix($entity) . rawurlencode($uniqueId) . '.json';
    }

    private function historyRootPrefix(OntologyEntity $entity): string
    {
        return $this->prefixed(self::HISTORY_ROOT . '/' . (int) $entity->getId() . '/');
    }

    private function historyPrefix(OntologyEntity $entity, string $uniqueId): string
    {
        return $this->historyRootPrefix($entity) . rawurlencode($uniqueId) . '/';
    }

    private function historyKey(OntologyEntity $entity, string $uniqueId, string $updatedAtAtom, int $version, string $uuid): string
    {
        $stamp = $this->timestampSegment($updatedAtAtom);

        return $this->historyPrefix($entity, $uniqueId) . $stamp . '/' . $version . '/' . rawurlencode($uuid) . '.json';
    }

    private function prefixed(string $key): string
    {
        $base = $this->client->basePath();

        return $base === '' ? $key : $base . '/' . $key;
    }

    /** yyyymmddhhmiss (UTC) from the version's updatedAt; a zero stamp when unparseable. */
    private function timestampSegment(string $updatedAtAtom): string
    {
        $parsed = date_create($updatedAtAtom, new \DateTimeZone('UTC'));

        return $parsed === false ? '00000000000000' : $parsed->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis');
    }

    // --- Envelopes ---------------------------------------------------------------------------

    private function writeLatest(OntologyEntity $entity, string $uid, int $version, string $uuid, \DateTimeImmutable $updatedAt, array $payload): void
    {
        $this->client->put(
            $this->latestKey($entity, $uid),
            $this->encodeEnvelope($entity, $uid, $version, $uuid, $updatedAt->format(\DateTimeInterface::ATOM), $payload)
        );
    }

    private function encodeEnvelope(OntologyEntity $entity, string $uid, int $version, string $uuid, string $updatedAtAtom, array $payload): string
    {
        return json_encode([
            'entityId' => (int) $entity->getId(),
            'entity' => (string) $entity->getName(),
            'uniqueId' => $uid,
            'version' => $version,
            'uuid' => $uuid,
            'updatedAt' => $updatedAtAtom,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{entity: string, uniqueId: string, version: int, uuid: string, updatedAt: string, payload: array}|null */
    private function decodeEnvelope(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !isset($decoded['uniqueId'])) {
            return null; // foreign object under our prefix — ignore rather than crash the listing
        }

        return [
            'entity' => (string) ($decoded['entity'] ?? ''),
            'uniqueId' => (string) $decoded['uniqueId'],
            'version' => (int) ($decoded['version'] ?? 1),
            'uuid' => (string) ($decoded['uuid'] ?? ''),
            'updatedAt' => (string) ($decoded['updatedAt'] ?? ''),
            'payload' => \is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
        ];
    }

    /** Highest archived version of the record — the continuity floor for new numbering. */
    private function maxHistoryVersion(OntologyEntity $entity, string $uniqueId): int
    {
        $max = 0;
        $prefix = $this->historyPrefix($entity, $uniqueId);
        foreach ($this->client->listKeys($prefix) as $key) {
            // …/{uid}/{yyyymmddhhmiss}/{version}/{uuid}.json — version is the second-to-last segment.
            $segments = explode('/', $key);
            $version = (int) ($segments[\count($segments) - 2] ?? 0);
            $max = max($max, $version);
        }

        return $max;
    }

    // --- Merge / compare (mirrors the aaxis_ontology_jsonb_* PG functions) --------------------

    /**
     * Recursively merges $new into $old: JSON objects merge key by key, arrays and scalars are
     * replaced by the incoming value, keys absent from $new survive. (An empty PHP array is
     * ambiguous between {} and [] once json-decoded; it is treated as "not an object", so an
     * incoming value replaces it — the same net result the PG merge produces for both readings.)
     */
    public static function deepMerge(mixed $old, mixed $new): mixed
    {
        if (!self::isJsonObject($old)) {
            return $new ?? $old;
        }
        if (!self::isJsonObject($new)) {
            return $old;
        }

        $result = $old;
        foreach ($new as $key => $value) {
            if (\array_key_exists($key, $result) && self::isJsonObject($result[$key]) && self::isJsonObject($value)) {
                $result[$key] = self::deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** Structural equality with key order normalized (json objects are unordered). */
    public static function jsonEquals(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    private static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    private static function isJsonObject(mixed $value): bool
    {
        return \is_array($value) && $value !== [] && !array_is_list($value);
    }
}
