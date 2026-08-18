<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads records of an INTERNAL-system ontology entity from the OroCommerce entity itself.
 *
 * An internal system (external = false) has no rows in `aaxis_ontology_data` — its entity's `name`
 * is an Oro entity class and the data lives in that entity's own table. This reader produces the
 * same shape the external store would: one plain payload array per record.
 *
 * The payload carries the ontology entity's configured ATTRIBUTES (their names are Oro field names,
 * enforced by the entity form); an entity with no attributes falls back to every scalar column.
 * Values are selected directly (no entity hydration, so "all" can span a large table): to-one
 * associations become the related identifier, to-many attributes are skipped, and date/time values
 * are formatted per their Doctrine type so payloads stay JSON-safe.
 */
class OroEntityReader
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * The record whose unique attribute equals $uniqueId, or null when there is none. A non-numeric
     * id against an integer column is simply "not found", never a database error.
     *
     * @return array<string, mixed>|null
     *
     * @throws OntologyApiException when the entity does not map to a readable Oro entity
     */
    public function readById(OntologyEntity $entity, string $uniqueId): ?array
    {
        [$em, $md, $selects] = $this->prepare($entity);

        $uniqueAttr = trim((string) $entity->getUniqueAttribute());
        $comparedType = null;
        if ($md->hasField($uniqueAttr)) {
            $comparedType = (string) $md->getTypeOfField($uniqueAttr);
        } elseif ($md->hasAssociation($uniqueAttr) && $md->isAssociationWithSingleJoinColumn($uniqueAttr)) {
            // Unique attribute pointing at a to-one association: compare against the related id.
            $target = $em->getClassMetadata($md->getAssociationTargetClass($uniqueAttr));
            $comparedType = (string) $target->getTypeOfField($target->getSingleIdentifierFieldName());
        } else {
            throw $this->unreadable($entity, sprintf('unique attribute "%s" is not one of its fields', $uniqueAttr));
        }
        if (\in_array($comparedType, ['integer', 'smallint', 'bigint'], true) && !is_numeric($uniqueId)) {
            return null;
        }

        $rows = $em->createQuery(sprintf(
            'SELECT %s FROM %s e WHERE e.%s = :uid ORDER BY %s',
            implode(', ', $selects),
            $md->getName(),
            $uniqueAttr,
            $this->identifierOrder($md)
        ))
            ->setParameter('uid', $uniqueId)
            ->setMaxResults(1)
            ->getArrayResult();

        return $rows === [] ? null : $this->normalizeRow($rows[0], $md);
    }

    /**
     * Every record whose $attribute column equals $value, ordered by identifier — [] when nothing
     * matches. The attribute must be one of the payload fields ({@see searchableFields}). A value
     * the column type cannot hold (letters against an integer, garbage against a date) is simply
     * "no match", never a database error.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws OntologyApiException when the entity does not map to a readable Oro entity or the
     *                              attribute is not one of its readable fields
     */
    public function readByAttribute(OntologyEntity $entity, string $attribute, string $value): array
    {
        [$em, $md, $selects] = $this->prepare($entity);

        $attribute = trim($attribute);
        if (!\in_array($attribute, $this->payloadFields($entity, $md), true)) {
            throw $this->unreadable($entity, sprintf('"%s" is not one of its readable attributes', $attribute));
        }

        $query = $em->createQuery(sprintf(
            'SELECT %s FROM %s e WHERE e.%s = :val ORDER BY %s',
            implode(', ', $selects),
            $md->getName(),
            $attribute,
            $this->identifierOrder($md)
        ))->setParameter('val', $value);

        try {
            $rows = $query->getArrayResult();
        } catch (DriverException $e) {
            // SQLSTATE class 22 = data exception: the value does not fit the column's type, which
            // for a search means "no record holds this value".
            if (str_starts_with((string) $e->getSQLState(), '22')) {
                return [];
            }
            throw $e;
        }

        return array_map(fn (array $row): array => $this->normalizeRow($row, $md), $rows);
    }

    /**
     * The field names a payload of this entity carries — what by-attribute searches and order-by
     * can address: the configured attributes that are real fields (or to-one associations), or
     * every scalar column when no attributes are configured.
     *
     * @return array<int, string>
     *
     * @throws OntologyApiException when the entity does not map to a readable Oro entity
     */
    public function searchableFields(OntologyEntity $entity): array
    {
        [, $md] = $this->resolve($entity);

        return $this->payloadFields($entity, $md);
    }

    /**
     * Every record (optionally ordered by one field and capped), oldest first by identifier. An
     * order-by that is not a real field is ignored — same leniency as the external store, which
     * orders by a missing jsonb attribute without complaint.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws OntologyApiException when the entity does not map to a readable Oro entity
     */
    public function readAll(OntologyEntity $entity, ?string $orderBy = null, string $direction = 'ASC', ?int $limit = null): array
    {
        [$em, $md, $selects] = $this->prepare($entity);

        $orderBy = trim((string) $orderBy);
        $order = $md->hasField($orderBy)
            ? sprintf('e.%s %s, %s', $orderBy, strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC', $this->identifierOrder($md))
            : $this->identifierOrder($md);

        $query = $em->createQuery(sprintf(
            'SELECT %s FROM %s e ORDER BY %s',
            implode(', ', $selects),
            $md->getName(),
            $order
        ));
        if ($limit !== null && $limit > 0) {
            $query->setMaxResults($limit);
        }

        return array_map(fn (array $row): array => $this->normalizeRow($row, $md), $query->getArrayResult());
    }

    /**
     * Resolves the Oro entity class behind the ontology entity.
     *
     * @return array{0: EntityManagerInterface, 1: ClassMetadata}
     *
     * @throws OntologyApiException
     */
    private function resolve(OntologyEntity $entity): array
    {
        $class = trim((string) $entity->getName());
        $em = $class === '' ? null : $this->doctrine->getManagerForClass($class);
        if (!$em instanceof EntityManagerInterface) {
            throw $this->unreadable($entity, 'its name is not a managed OroCommerce entity class');
        }

        try {
            $md = $em->getClassMetadata($class);
        } catch (\Throwable) {
            throw $this->unreadable($entity, 'its name is not a managed OroCommerce entity class');
        }

        return [$em, $md];
    }

    /**
     * The validated payload field names: configured attributes that are real fields or to-one
     * associations (collection-valued associations and stale names have no flat representation and
     * are dropped), or every scalar column when no attributes are configured.
     *
     * @return array<int, string>
     *
     * @throws OntologyApiException when nothing is readable
     */
    private function payloadFields(OntologyEntity $entity, ClassMetadata $md): array
    {
        $names = [];
        foreach ($entity->getAttributes() as $attribute) {
            $name = trim((string) $attribute->getName());
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        // No attributes configured: the payload is every scalar column of the entity.
        $names = $names === [] ? $md->getFieldNames() : array_keys($names);

        $valid = [];
        foreach ($names as $name) {
            if ($md->hasField($name) || ($md->hasAssociation($name) && $md->isAssociationWithSingleJoinColumn($name))) {
                $valid[] = $name;
            }
        }
        if ($valid === []) {
            throw $this->unreadable($entity, 'none of its attributes is a readable field');
        }

        return $valid;
    }

    /**
     * Resolves the entity and builds the DQL select list for its payload fields. All names are
     * validated against the class metadata, so they are safe to interpolate.
     *
     * @return array{0: EntityManagerInterface, 1: ClassMetadata, 2: array<int, string>}
     *
     * @throws OntologyApiException
     */
    private function prepare(OntologyEntity $entity): array
    {
        [$em, $md] = $this->resolve($entity);

        $selects = array_map(
            static fn (string $name): string => $md->hasField($name)
                ? sprintf('e.%s AS %s', $name, $name)
                : sprintf('IDENTITY(e.%s) AS %s', $name, $name),
            $this->payloadFields($entity, $md)
        );

        return [$em, $md, $selects];
    }

    /** Deterministic tiebreak/default ordering over the identifier field(s). */
    private function identifierOrder(ClassMetadata $md): string
    {
        return implode(', ', array_map(
            static fn (string $field): string => sprintf('e.%s ASC', $field),
            $md->getIdentifierFieldNames()
        ));
    }

    /**
     * Makes a selected row JSON-safe: date/time values formatted per their Doctrine type, enums to
     * their backing value, leftover objects to string or dropped. Scalars, arrays (json /
     * simple_array columns) and decimal strings pass through untouched.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, ClassMetadata $md): array
    {
        foreach ($row as $field => $value) {
            if ($value instanceof \DateTimeInterface) {
                $type = $md->hasField($field) ? (string) $md->getTypeOfField($field) : '';
                $row[$field] = match ($type) {
                    'date', 'date_immutable' => $value->format('Y-m-d'),
                    'time', 'time_immutable' => $value->format('H:i:s'),
                    default => $value->format(\DateTimeInterface::ATOM),
                };
            } elseif ($value instanceof \BackedEnum) {
                $row[$field] = $value->value;
            } elseif ($value instanceof \Stringable) {
                $row[$field] = (string) $value;
            } elseif (\is_object($value)) {
                $row[$field] = null;
            }
        }

        return $row;
    }

    private function unreadable(OntologyEntity $entity, string $reason): OntologyApiException
    {
        return OntologyApiException::internalEntityUnreadable((string) $entity->getName(), $reason);
    }
}
