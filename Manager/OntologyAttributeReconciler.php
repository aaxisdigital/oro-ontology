<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Keeps an entity's attribute definitions in sync with the data written for it, and enforces the
 * attribute contract on incoming records:
 *
 *  - {@see assertValid()} rejects a record that is missing a required attribute or whose value has a
 *    type different from the attribute's declared datatype (datatype `undefined` accepts anything).
 *  - {@see syncFromRecords()} creates any attribute present in the records but not yet defined on the
 *    entity, as datatype `undefined`, not required. Nested object keys become dotted paths
 *    ("address.city"); an attribute pre-declared as `object` collapses its subtree (its nested keys
 *    are NOT created as separate attributes).
 *
 * Attribute names match the dotted payload path. The maximum attribute-name length (100) is honoured;
 * longer paths are skipped.
 */
class OntologyAttributeReconciler
{
    private const int MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /**
     * @param array<int|string, mixed> $payload
     *
     * @throws OntologyApiException when a required attribute is missing or a value's type mismatches
     */
    public function assertValid(OntologyEntity $entity, array $payload): void
    {
        foreach ($entity->getAttributes() as $attribute) {
            $name = (string) $attribute->getName();
            if ($name === '') {
                continue;
            }

            // Resolve every occurrence of the attribute, descending into arrays of objects so a path
            // like orders.headerData.VBAK_VKORG yields one value per array element.
            $values = $this->resolveValues($payload, explode('.', $name));
            $present = array_filter($values, static fn ($v) => $v !== null) !== [];

            if ($attribute->isRequired() && !$present) {
                throw OntologyApiException::invalidPayload(sprintf('Missing required attribute "%s".', $name));
            }

            $datatype = $attribute->getDatatype();
            if ($datatype === OntologyEntityAttribute::TYPE_UNDEFINED) {
                continue;
            }
            foreach ($values as $value) {
                if ($value !== null && !$this->valueMatchesType($value, $datatype)) {
                    throw OntologyApiException::invalidPayload(
                        sprintf('Attribute "%s" must be of type "%s".', $name, $datatype)
                    );
                }
            }
        }
    }

    /**
     * Creates the attributes present in the given records that the entity does not define yet
     * (datatype `undefined`, not required), persisting them in one flush.
     *
     * @param array<int, array<int|string, mixed>> $records
     */
    public function syncFromRecords(OntologyEntity $entity, array $records): void
    {
        $existing = [];
        foreach ($entity->getAttributes() as $attribute) {
            $existing[(string) $attribute->getName()] = $attribute;
        }

        $uniqueAttribute = (string) $entity->getUniqueAttribute();

        $created = false;
        foreach ($records as $record) {
            if (!\is_array($record)) {
                continue;
            }
            foreach ($this->collectPaths($record, '', $existing) as $path) {
                if ($path === '' || isset($existing[$path]) || mb_strlen($path) > self::MAX_NAME_LENGTH) {
                    continue;
                }
                $attribute = (new OntologyEntityAttribute())
                    ->setName($path)
                    ->setDatatype(OntologyEntityAttribute::TYPE_UNDEFINED)
                    // The attribute used as the entity's unique id is always required.
                    ->setRequired($path === $uniqueAttribute);
                $entity->addAttribute($attribute);
                $existing[$path] = $attribute;
                $created = true;
            }
        }

        if ($created) {
            $this->doctrine->getManagerForClass(OntologyEntity::class)->flush();
        }
    }

    /**
     * Collects the attribute paths present in a payload.
     *
     * - A scalar (and an array of scalars / empty array) is a leaf attribute.
     * - An associative object is descended into; its keys become dotted sub-paths.
     * - An array of objects is descended into as well: every element's keys are unioned onto the same
     *   dotted path (no indexes), so a list like orders.headerData[{...}] yields orders.headerData.<key>.
     * - In both descend cases, an attribute already declared as `object` for that path collapses the
     *   subtree (its nested keys are NOT expanded).
     *
     * @param array<int|string, mixed>               $payload
     * @param array<string, OntologyEntityAttribute> $existing
     *
     * @return array<int, string>
     */
    private function collectPaths(array $payload, string $prefix, array $existing): array
    {
        $paths = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (!\is_array($value)) {
                $paths[] = $path;

                continue;
            }

            $declared = $existing[$path] ?? null;
            if ($declared !== null && $declared->getDatatype() === OntologyEntityAttribute::TYPE_OBJECT) {
                // Pre-declared object: keep it as one attribute, don't expand its nested keys.
                continue;
            }

            $nested = array_is_list($value)
                ? $this->collectListPaths($value, $path, $existing)
                : $this->collectPaths($value, $path, $existing);
            foreach ($nested as $sub) {
                $paths[] = $sub;
            }
        }

        return $paths;
    }

    /**
     * Paths within a list: each object element's keys are unioned onto the same path (no indexes);
     * a list of scalars (or an empty list) is a single leaf attribute.
     *
     * @param array<int, mixed>                      $list
     * @param array<string, OntologyEntityAttribute> $existing
     *
     * @return array<int, string>
     */
    private function collectListPaths(array $list, string $path, array $existing): array
    {
        $paths = [];
        foreach ($list as $element) {
            if (\is_array($element) && !array_is_list($element)) {
                foreach ($this->collectPaths($element, $path, $existing) as $nested) {
                    $paths[] = $nested;
                }
            }
        }

        return $paths === [] ? [$path] : $paths;
    }

    /**
     * Resolves all values at a dotted path. Associative levels consume one segment; when a level is
     * an array of objects, the remaining segments are applied to every element — so a path through
     * arrays yields one value per element (e.g. orders.headerData.VBAK_VKORG over a list of headers).
     *
     * @param mixed             $current
     * @param array<int, string> $segments
     *
     * @return array<int, mixed> the resolved values (empty when the path does not resolve)
     */
    private function resolveValues(mixed $current, array $segments): array
    {
        if ($segments === []) {
            return [$current];
        }
        if (\is_array($current) && array_is_list($current)) {
            $values = [];
            foreach ($current as $element) {
                foreach ($this->resolveValues($element, $segments) as $value) {
                    $values[] = $value;
                }
            }

            return $values;
        }
        $segment = $segments[0];
        if (\is_array($current) && \array_key_exists($segment, $current)) {
            return $this->resolveValues($current[$segment], \array_slice($segments, 1));
        }

        return [];
    }

    private function valueMatchesType(mixed $value, string $datatype): bool
    {
        return match ($datatype) {
            OntologyEntityAttribute::TYPE_BOOLEAN => \is_bool($value),
            // A number must be a real number (JSON int/float); a numeric string does not qualify.
            OntologyEntityAttribute::TYPE_NUMBER => \is_int($value) || \is_float($value),
            OntologyEntityAttribute::TYPE_TEXT,
            OntologyEntityAttribute::TYPE_DATE,
            OntologyEntityAttribute::TYPE_TIME,
            OntologyEntityAttribute::TYPE_DATETIME => \is_string($value),
            OntologyEntityAttribute::TYPE_OBJECT => \is_array($value),
            default => true,
        };
    }
}
