<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;

/**
 * The object-key layout of {@see BucketEntityDataStore} (the entity segment is the entity ID —
 * names are only unique PER SYSTEM and change on rename, ids never do; the unique-id segment is
 * rawurlencoded so a "/" inside it cannot fork the hierarchy):
 *
 *   latest:  {base}/entity-data/{entity-id}/{uid}.json
 *   history: {base}/entity-data-history/{entity-id}/{uid}/{yyyymmddhhmiss}/{version}/{uuid}.json
 */
final class EntityDataKeys
{
    private const string LATEST_ROOT = 'entity-data';
    private const string HISTORY_ROOT = 'entity-data-history';

    public function __construct(private readonly OntologyBucketClient $client)
    {
    }

    /** The prefix every live record of the entity lives under. */
    public function latestPrefix(OntologyEntity $entity): string
    {
        return $this->prefixed(self::LATEST_ROOT . '/' . (int) $entity->getId() . '/');
    }

    public function latestKey(OntologyEntity $entity, string $uniqueId): string
    {
        return $this->latestPrefix($entity) . rawurlencode($uniqueId) . '.json';
    }

    /** The prefix every archived snapshot of the entity lives under. */
    public function historyRootPrefix(OntologyEntity $entity): string
    {
        return $this->prefixed(self::HISTORY_ROOT . '/' . (int) $entity->getId() . '/');
    }

    /** The prefix of one record's archived snapshots. */
    public function historyPrefix(OntologyEntity $entity, string $uniqueId): string
    {
        return $this->historyRootPrefix($entity) . rawurlencode($uniqueId) . '/';
    }

    public function historyKey(OntologyEntity $entity, string $uniqueId, string $updatedAtAtom, int $version, string $uuid): string
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
}
