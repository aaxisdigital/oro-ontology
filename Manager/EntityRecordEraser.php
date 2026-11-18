<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The Entities page's "Erase": counts, previews and erases an entity's STORED records — all of
 * them, or those matching one {@see RecordFilter} condition — with their version history.
 *
 * Which store is read follows the record APIs: a bucket-backed entity (external, "Use Bucket for
 * Entity Data" on, not forcing DB storage) is counted, previewed and filter-erased on the BUCKET;
 * every other entity on `aaxis_ontology_data`. Erasing ALL records clears both stores, as it
 * always did — stale objects or rows from before the backend switch go too.
 *
 * Internal-system entities have no stored records here (their data is the OroCommerce table
 * itself, never erased from this page): every operation refuses them.
 */
class EntityRecordEraser
{
    public const int MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly BucketEntityDataStore $bucket,
    ) {
    }

    /**
     * How many records the selection holds (null filter = all).
     *
     * @param array{path: list<string>, operator: string, value: string}|null $filter
     */
    public function count(OntologyEntity $entity, ?array $filter): int
    {
        $this->assertErasable($entity);
        if ($this->bucketBacked($entity)) {
            return $filter === null ? $this->bucket->countLatest($entity) : \count($this->bucketSelection($entity, $filter));
        }
        [$where, $params, $types] = $this->where($entity, $filter);

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM aaxis_ontology_data d WHERE ' . $where, $params, $types);
    }

    /**
     * One page of the selection, ordered by unique id.
     *
     * @param array{path: list<string>, operator: string, value: string}|null $filter
     *
     * @return array{total: int, records: list<array{uniqueId: string, version: int, updatedAt: string|null, payload: mixed}>}
     */
    public function preview(OntologyEntity $entity, ?array $filter, int $page, int $pageSize): array
    {
        $this->assertErasable($entity);
        $pageSize = max(1, min($pageSize, self::MAX_PAGE_SIZE));
        $offset = (max(1, $page) - 1) * $pageSize;

        if ($this->bucketBacked($entity)) {
            $selection = $filter === null ? $this->bucket->listLatest($entity) : $this->bucketSelection($entity, $filter);
            usort($selection, static fn (array $a, array $b): int => strcmp((string) $a['uniqueId'], (string) $b['uniqueId']));

            return [
                'total' => \count($selection),
                'records' => array_map(static fn (array $envelope): array => [
                    'uniqueId' => (string) $envelope['uniqueId'],
                    'version' => (int) $envelope['version'],
                    'updatedAt' => isset($envelope['updatedAt']) ? (string) $envelope['updatedAt'] : null,
                    'payload' => $envelope['payload'],
                ], \array_slice($selection, $offset, $pageSize)),
            ];
        }

        [$where, $params, $types] = $this->where($entity, $filter);
        $rows = $this->connection()->fetchAllAssociative(
            // Validated integers, inlined (Postgres LIMIT parameter typing).
            sprintf('SELECT d.unique_id, d.version, d.updated_at, d.payload FROM aaxis_ontology_data d WHERE %s ORDER BY d.unique_id ASC, d.id ASC LIMIT %d OFFSET %d', $where, $pageSize, $offset),
            $params,
            $types
        );

        return [
            'total' => $this->count($entity, $filter),
            'records' => array_map(static fn (array $row): array => [
                'uniqueId' => (string) $row['unique_id'],
                'version' => (int) $row['version'],
                'updatedAt' => self::utcAtom($row['updated_at'] ?? null),
                'payload' => \is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : ($row['payload'] ?? null),
            ], $rows),
        ];
    }

    /**
     * Erases the selection — live records AND their version history — and returns how many live
     * records went.
     *
     * @param array{path: list<string>, operator: string, value: string}|null $filter
     */
    public function erase(OntologyEntity $entity, ?array $filter): int
    {
        $this->assertErasable($entity);
        if ($filter === null) {
            return $this->eraseAll($entity);
        }
        if ($this->bucketBacked($entity)) {
            $uniqueIds = array_map(static fn (array $envelope): string => (string) $envelope['uniqueId'], $this->bucketSelection($entity, $filter));

            return $this->bucket->eraseRecords($entity, $uniqueIds);
        }

        [$where, $params, $types] = $this->where($entity, $filter);

        return (int) $this->connection()->transactional(static function (Connection $connection) use ($where, $params, $types): int {
            // History first: it is found through the live rows the second statement removes.
            $connection->executeStatement(
                'DELETE FROM aaxis_ontology_data_history h USING aaxis_ontology_data d'
                . ' WHERE h.entity_id = :entity AND h.unique_id = d.unique_id AND ' . $where,
                $params,
                $types
            );

            return (int) $connection->executeStatement('DELETE FROM aaxis_ontology_data d WHERE ' . $where, $params, $types);
        });
    }

    /** Everything, from both stores (see the class docblock). */
    private function eraseAll(OntologyEntity $entity): int
    {
        $bucketDeleted = 0;
        if (($entity->getSystem()?->isExternal() ?? false) && $this->bucket->isEnabled()) {
            // Even for force-db entities: stale objects written before the flag flipped go too.
            $bucketDeleted = $this->bucket->purgeEntity($entity);
        }
        $params = ['entity' => (int) $entity->getId()];
        $types = ['entity' => ParameterType::INTEGER];

        $deleted = (int) $this->connection()->transactional(static function (Connection $connection) use ($params, $types): int {
            $connection->executeStatement('DELETE FROM aaxis_ontology_data_history WHERE entity_id = :entity', $params, $types);

            return (int) $connection->executeStatement('DELETE FROM aaxis_ontology_data WHERE entity_id = :entity', $params, $types);
        });

        return $deleted + $bucketDeleted;
    }

    /**
     * The bucket's live records that match.
     *
     * @param array{path: list<string>, operator: string, value: string} $filter
     *
     * @return list<array<string, mixed>> the matching envelopes
     */
    private function bucketSelection(OntologyEntity $entity, array $filter): array
    {
        return array_values(array_filter(
            $this->bucket->listLatest($entity),
            static fn (array $envelope): bool => \is_array($envelope['payload'] ?? null) && RecordFilter::matches($envelope['payload'], $filter)
        ));
    }

    /**
     * WHERE clause over `aaxis_ontology_data d` for the entity (+ the condition).
     *
     * @param array{path: list<string>, operator: string, value: string}|null $filter
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private function where(OntologyEntity $entity, ?array $filter): array
    {
        $params = ['entity' => (int) $entity->getId()];
        $where = 'd.entity_id = :entity';
        if ($filter !== null) {
            $where .= ' AND ' . RecordFilter::sql($filter, 'd.payload', $params);
        }

        return [$where, $params, ['entity' => ParameterType::INTEGER]];
    }

    private function bucketBacked(OntologyEntity $entity): bool
    {
        return ($entity->getSystem()?->isExternal() ?? false)
            && !$entity->isForceDbStorage()
            && $this->bucket->isEnabled();
    }

    private function assertErasable(OntologyEntity $entity): void
    {
        if (!($entity->getSystem()?->isExternal() ?? false)) {
            throw new \InvalidArgumentException('The records of an OroCommerce entity live in its own table and are not erased here.');
        }
    }

    /** The DB's UTC wall-clock datetime as ISO-8601 (what the bucket envelopes carry). */
    private static function utcAtom(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        $parsed = date_create_immutable($value, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->format(\DateTimeInterface::ATOM);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->doctrine->getConnection();

        return $connection;
    }
}
