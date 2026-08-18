<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes records of an INTERNAL-system ontology entity into the OroCommerce entity itself —
 * the write-side counterpart of {@see OroEntityReader}.
 *
 * UPDATE ONLY: each record must match an existing row by the entity's unique attribute; a batch
 * naming a missing (or ambiguous) row is rejected whole, before anything is written. Creating Oro
 * entities generically is deliberately unsupported — rows carry required relations, ownership and
 * defaults no flat payload can satisfy.
 *
 * A record writes the intersection of its payload keys with the entity's readable fields (the
 * SAME resolution the reader uses, {@see OroEntityReader::searchableFields}, minus identifier and
 * unique-attribute columns): present keys are written — null included — absent keys left alone, so
 * a partial payload is a partial update. To-one associations take the related id; values are
 * coerced per the column's Doctrine type. Changes go through the ORM (one flush per batch), and
 * only rows whose values actually differed count as changed.
 */
class OroEntityWriter
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly OroEntityReader $reader,
    ) {
    }

    /**
     * @param array<int, string>                          $uniqueIds parallel to $payloads
     * @param array<int, array<int|string, mixed>>        $payloads
     *
     * @return array<int, string> the unique ids whose row actually changed
     *
     * @throws OntologyApiException
     */
    public function update(OntologyEntity $entity, array $uniqueIds, array $payloads): array
    {
        [$em, $md] = $this->resolve($entity);
        $uniqueAttr = trim((string) $entity->getUniqueAttribute());
        $rows = $this->loadRows($entity, $em, $md, $uniqueAttr, $uniqueIds);

        // The writable set: what the reader exposes, minus the columns that identify the row.
        $writable = array_diff(
            $this->reader->searchableFields($entity),
            $md->getIdentifierFieldNames(),
            [$uniqueAttr]
        );

        $changed = [];
        foreach ($uniqueIds as $i => $uid) {
            $row = $rows[$uid];
            $payload = $payloads[$i];
            $dirty = false;
            foreach ($writable as $field) {
                if (!\array_key_exists($field, $payload)) {
                    continue; // absent key = leave the column alone (partial update)
                }
                $dirty = $this->writeField($em, $md, $row, $field, $payload[$field], $uid) || $dirty;
            }
            if ($dirty) {
                $changed[] = $uid;
            }
        }

        try {
            $em->flush();
        } catch (\Exception $e) {
            throw OntologyApiException::invalidPayload(sprintf(
                'Writing to "%s" failed: %s',
                (string) $entity->getName(),
                $e->getMessage()
            ));
        }

        return $changed;
    }

    /**
     * Sets one field/association if the value actually differs; reports whether it did.
     */
    private function writeField(EntityManagerInterface $em, ClassMetadata $md, object $row, string $field, mixed $value, string $uid): bool
    {
        if ($md->hasField($field)) {
            $new = $this->coerce($value, (string) $md->getTypeOfField($field), $field, $uid);
            $old = $md->getFieldValue($row, $field);
            if ($this->unchanged($old, $new)) {
                return false;
            }
            $md->setFieldValue($row, $field, $new);

            return true;
        }

        // To-one association: the payload carries the related id (what the reader exports).
        $targetClass = $md->getAssociationTargetClass($field);
        $targetMd = $em->getClassMetadata($targetClass);
        $old = $md->getFieldValue($row, $field);
        $oldId = $old === null ? null : (current($targetMd->getIdentifierValues($old)) ?: null);
        $newId = ($value === null || $value === '') ? null : $value;
        if (($oldId === null && $newId === null) || (string) $oldId === (string) $newId) {
            return false;
        }
        if ($newId !== null) {
            $idType = (string) $targetMd->getTypeOfField($targetMd->getSingleIdentifierFieldName());
            if (\in_array($idType, ['integer', 'smallint', 'bigint'], true)) {
                if (!is_numeric((string) $newId)) {
                    throw OntologyApiException::invalidPayload(sprintf(
                        'Record "%s": "%s" must be the numeric id of the related record.',
                        $uid,
                        $field
                    ));
                }
                $newId = (int) $newId;
            }
        }
        $md->setFieldValue($row, $field, $newId === null ? null : $em->getReference($targetClass, $newId));

        return true;
    }

    /**
     * Loads every targeted row in ONE query and requires the batch to be fully addressable:
     * a unique id with no row (or, on a non-unique column, several) rejects the whole batch
     * before anything is written.
     *
     * @param array<int, string> $uniqueIds
     *
     * @return array<string, object> unique id => managed row
     *
     * @throws OntologyApiException
     */
    private function loadRows(OntologyEntity $entity, EntityManagerInterface $em, ClassMetadata $md, string $uniqueAttr, array $uniqueIds): array
    {
        $isField = $md->hasField($uniqueAttr);
        if (!$isField && !($md->hasAssociation($uniqueAttr) && $md->isAssociationWithSingleJoinColumn($uniqueAttr))) {
            throw $this->unwritable($entity, sprintf('unique attribute "%s" is not one of its fields', $uniqueAttr));
        }

        // Ids that cannot exist in an integer column are simply missing — reported, not a DB error.
        $comparedType = $isField
            ? (string) $md->getTypeOfField($uniqueAttr)
            : (string) $em->getClassMetadata($md->getAssociationTargetClass($uniqueAttr))
                ->getTypeOfField($em->getClassMetadata($md->getAssociationTargetClass($uniqueAttr))->getSingleIdentifierFieldName());
        $intTyped = \in_array($comparedType, ['integer', 'smallint', 'bigint'], true);
        $lookable = $intTyped ? array_values(array_filter($uniqueIds, 'is_numeric')) : $uniqueIds;

        $rows = $lookable === [] ? [] : $em->getRepository($md->getName())->findBy([$uniqueAttr => $lookable]);

        $byId = [];
        foreach ($rows as $row) {
            $key = $isField
                ? (string) $md->getFieldValue($row, $uniqueAttr)
                : (string) current($em->getClassMetadata($md->getAssociationTargetClass($uniqueAttr))
                    ->getIdentifierValues($md->getFieldValue($row, $uniqueAttr)));
            if (isset($byId[$key])) {
                throw OntologyApiException::invalidPayload(sprintf(
                    'Several "%s" records share the unique attribute value "%s" — the write is ambiguous.',
                    (string) $entity->getName(),
                    $key
                ));
            }
            $byId[$key] = $row;
        }

        $missing = array_values(array_diff($uniqueIds, array_keys($byId)));
        if ($missing !== []) {
            throw OntologyApiException::invalidPayload(sprintf(
                'No OroCommerce record found for unique id(s) %s — internal-system writes UPDATE existing records only.',
                '"' . implode('", "', $missing) . '"'
            ));
        }

        return $byId;
    }

    /**
     * Coerces a JSON payload value to what the column's Doctrine type expects. Nulls pass through
     * (present key + null = clear the column).
     */
    private function coerce(mixed $value, string $type, string $field, string $uid): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer', 'smallint' => (int) $value,
            'float' => (float) $value,
            'boolean' => \is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date', 'date_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable',
            'time', 'time_immutable' => $this->toDateTime($value, $type, $field, $uid),
            'simple_array' => array_map(strval(...), \is_array($value) ? $value : [$value]),
            // bigint/decimal/money/percent (numeric strings), json/array (arrays), strings: as-is.
            default => $value,
        };
    }

    private function toDateTime(mixed $value, string $type, string $field, string $uid): ?\DateTimeInterface
    {
        if ($value === '') {
            return null;
        }
        if (!\is_string($value)) {
            throw OntologyApiException::invalidPayload(sprintf(
                'Record "%s": "%s" must be a date/time string.',
                $uid,
                $field
            ));
        }
        try {
            return str_contains($type, 'immutable') ? new \DateTimeImmutable($value) : new \DateTime($value);
        } catch (\Exception) {
            throw OntologyApiException::invalidPayload(sprintf(
                'Record "%s": "%s" is not a valid date/time for "%s".',
                $uid,
                $value,
                $field
            ));
        }
    }

    /**
     * Whether the stored value already equals the incoming one — the definition of a record that
     * does NOT count as changed. Dates compare by value, scalars loosely (a decimal "1.50" equals
     * "1.5"), nulls only equal nulls.
     */
    private function unchanged(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }
        if ($old instanceof \DateTimeInterface && $new instanceof \DateTimeInterface) {
            return $old == $new;
        }

        return $old == $new;
    }

    /**
     * @return array{0: EntityManagerInterface, 1: ClassMetadata}
     *
     * @throws OntologyApiException
     */
    private function resolve(OntologyEntity $entity): array
    {
        $class = trim((string) $entity->getName());
        $em = $class === '' ? null : $this->doctrine->getManagerForClass($class);
        if (!$em instanceof EntityManagerInterface) {
            throw $this->unwritable($entity, 'its name is not a managed OroCommerce entity class');
        }

        try {
            return [$em, $em->getClassMetadata($class)];
        } catch (\Throwable) {
            throw $this->unwritable($entity, 'its name is not a managed OroCommerce entity class');
        }
    }

    private function unwritable(OntologyEntity $entity, string $reason): OntologyApiException
    {
        return OntologyApiException::internalEntityUnwritable((string) $entity->getName(), $reason);
    }
}
