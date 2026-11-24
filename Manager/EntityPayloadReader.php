<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The record payloads a DWL playground run reads — shared by the Entities grid's DWL popup and the
 * Data Playground page, so both read (and count) an entity's records the same way, and the way a
 * flow's Entity Read does:
 * - INTERNAL-system entities (an Oro entity class) from the Oro table itself
 *   ({@see OroEntityReader::readAll()} — the configured attributes, or every scalar column);
 * - external entities whose records live on the config bucket (toggle on, entity not forcing DB
 *   storage) from the bucket;
 * - everything else from `aaxis_ontology_data`.
 */
class EntityPayloadReader
{
    private readonly OroEntityReader $oroReader;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly BucketEntityDataStore $store,
        ?OroEntityReader $oroReader = null
    ) {
        $this->oroReader = $oroReader ?? new OroEntityReader($doctrine);
    }

    /** How many records a run over this entity can see (the "/ N total records" label). */
    public function total(OntologyEntity $entity): int
    {
        if (self::isInternal($entity)) {
            return $this->oroCount($entity);
        }
        if ($this->bucketBacked($entity)) {
            return $this->store->countLatest($entity);
        }

        return (int) $this->doctrine->getRepository(OntologyData::class)->count(['entity' => $entity]);
    }

    /**
     * The entity's record payloads, oldest first, at most `$limit` (null = all). Projects the payload
     * column only (no entity hydration): an unlimited run can span the whole table.
     *
     * @return list<array<string, mixed>>
     *
     * @throws OntologyApiException when an internal entity does not map to a readable Oro entity
     */
    public function payloads(OntologyEntity $entity, ?int $limit): array
    {
        if (self::isInternal($entity)) {
            return array_values($this->oroReader->readAll($entity, null, 'ASC', $limit));
        }
        if ($this->bucketBacked($entity)) {
            $payloads = array_map(
                static fn (array $envelope): array => \is_array($envelope['payload']) ? $envelope['payload'] : [],
                $this->store->listLatest($entity)
            );

            return $limit !== null ? \array_slice($payloads, 0, $limit) : $payloads;
        }

        $query = $this->doctrine->getManagerForClass(OntologyData::class)
            ->createQuery(
                'SELECT d.payload FROM ' . OntologyData::class . ' d'
                . ' WHERE d.entity = :entity ORDER BY d.id ASC'
            )
            ->setParameter('entity', $entity);
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }

        return array_map(
            static fn (array $row): array => \is_array($row['payload'] ?? null) ? $row['payload'] : [],
            $query->getArrayResult()
        );
    }

    /**
     * Whether this EXTERNAL entity's records live on the config bucket: toggle on + configured,
     * and the entity does not FORCE DB storage (the per-entity opt-out for hot entities).
     */
    public function bucketBacked(OntologyEntity $entity): bool
    {
        return ($entity->getSystem()?->isExternal() ?? false)
            && !$entity->isForceDbStorage()
            && $this->store->isEnabled();
    }

    /** An entity of an internal (OroCommerce) system: its name is an Oro entity class. */
    private static function isInternal(OntologyEntity $entity): bool
    {
        $system = $entity->getSystem();

        return $system !== null && !$system->isExternal();
    }

    /** Rows of the Oro table behind an internal entity (0 when its name is not a managed class). */
    private function oroCount(OntologyEntity $entity): int
    {
        $class = trim((string) $entity->getName());
        try {
            $manager = $class === '' ? null : $this->doctrine->getManagerForClass($class);

            return $manager instanceof EntityManagerInterface
                ? (int) $manager->createQuery('SELECT COUNT(e) FROM ' . $class . ' e')->getSingleScalarResult()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
