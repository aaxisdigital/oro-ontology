<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;

/**
 * The filter / order-by semantics of the Ontology data API's `query`, in its two renderings:
 *
 *  - SQL fragments over `aaxis_ontology_data.payload` ({@see appendSqlFilter()},
 *    {@see sqlOrderBy()}) — every attribute name and value is BOUND, operators and directions are
 *    whitelisted, so user input never reaches the SQL text;
 *  - the same semantics evaluated in PHP ({@see run()}) over the decoded payloads of a
 *    BUCKET-backed entity, whose records are not in the table. Values compare by their jsonb TEXT
 *    form (`payload->>attr`); `<` / `>` compare numerically when both sides are numeric.
 *
 * Both renderings validate identically ({@see parseFilter()}, {@see parseOrderBy()}), so the caller
 * cannot tell which backend served the request.
 */
final class PayloadQuery
{
    /** Allowed filter operators mapped to their SQL operator. */
    private const array COMPARATORS = [
        'EQ' => '=',
        'LIKE' => 'LIKE',
        '<' => '<',
        '>' => '>',
    ];

    /**
     * Validates one filter.
     *
     * @return array{attribute: string, compare: string, value: mixed} value is null or a scalar
     *
     * @throws OntologyApiException
     */
    public static function parseFilter(mixed $filter): array
    {
        $filter = \is_array($filter) ? $filter : [];

        $attribute = isset($filter['attribute']) ? trim((string) $filter['attribute']) : '';
        if ($attribute === '') {
            throw OntologyApiException::invalidQuery('Each filter requires a non-empty "attribute".');
        }

        $compare = (string) ($filter['compare'] ?? 'EQ');
        if (!isset(self::COMPARATORS[$compare])) {
            throw OntologyApiException::invalidQuery(sprintf('Unsupported filter compare "%s".', $compare));
        }

        $value = $filter['value'] ?? null;
        if ($value !== null && !\is_scalar($value)) {
            throw OntologyApiException::invalidQuery('Filter "value" must be a scalar.');
        }

        return ['attribute' => $attribute, 'compare' => $compare, 'value' => $value];
    }

    /**
     * Parses an "<attribute> [ASC|DESC]" string; null when there is no ordering to apply.
     *
     * @return array{0: string, 1: string}|null the attribute and the whitelisted direction
     *
     * @throws OntologyApiException
     */
    public static function parseOrderBy(?string $orderBy): ?array
    {
        $orderBy = trim((string) $orderBy);
        if ($orderBy === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $orderBy) ?: [];
        $attribute = trim((string) ($parts[0] ?? ''));
        $direction = strtoupper(trim((string) ($parts[1] ?? 'ASC')));

        if ($attribute === '') {
            throw OntologyApiException::invalidQuery('orderBy requires an attribute name.');
        }
        if (!\in_array($direction, ['ASC', 'DESC'], true)) {
            throw OntologyApiException::invalidQuery(sprintf('Unsupported orderBy direction "%s".', $direction));
        }

        return [$attribute, $direction];
    }

    // --- SQL rendering ------------------------------------------------------------------------

    /**
     * Validates one filter and appends its SQL fragment + bound parameters.
     *
     * @param array<int, string>   $where
     * @param array<string, mixed> $params
     *
     * @throws OntologyApiException
     */
    public static function appendSqlFilter(int $index, mixed $filter, array &$where, array &$params): void
    {
        ['attribute' => $attribute, 'compare' => $compare, 'value' => $value] = self::parseFilter($filter);

        $attrKey = 'attr' . $index;
        $valKey = 'val' . $index;
        $params[$attrKey] = $attribute;

        if (($compare === '<' || $compare === '>') && is_numeric($value)) {
            // Compare numerically, but only for rows whose value looks numeric, to avoid cast errors.
            // The bound value is cast in-SQL so it stays a plain (text) parameter.
            $where[] = sprintf(
                "(payload->>:%s ~ '^-?[0-9]+(\\.[0-9]+)?$' AND (payload->>:%s)::numeric %s CAST(:%s AS numeric))",
                $attrKey,
                $attrKey,
                self::COMPARATORS[$compare],
                $valKey
            );
        } else {
            $where[] = sprintf('payload->>:%s %s :%s', $attrKey, self::COMPARATORS[$compare], $valKey);
        }
        $params[$valKey] = (string) $value;
    }

    /**
     * Builds the ORDER BY clause. Defaults to "id ASC"; an "<attribute> [ASC|DESC]" string orders by
     * that payload attribute. The attribute is bound; the direction is whitelisted.
     *
     * @param array<string, mixed> $params
     *
     * @throws OntologyApiException
     */
    public static function sqlOrderBy(?string $orderBy, array &$params): string
    {
        $order = self::parseOrderBy($orderBy);
        if ($order === null) {
            return 'id ASC';
        }

        $params['orderAttr'] = $order[0];

        return sprintf('payload->>:orderAttr %s', $order[1]);
    }

    // --- PHP rendering (bucket-backed entities) -----------------------------------------------

    /**
     * Filters, orders and pages decoded payloads exactly as the SQL path would (see the class
     * docblock). Filters/orderBy are validated FIRST with the SQL path's own errors — the payloads
     * (a bucket listing) are only loaded once the query is known to be valid.
     *
     * @param \Closure(): array<int, array<int|string, mixed>>                     $loadPayloads
     * @param array<int, array{attribute?: mixed, compare?: mixed, value?: mixed}> $filters
     *
     * @return array<int, array<int|string, mixed>>
     *
     * @throws OntologyApiException
     */
    public static function run(\Closure $loadPayloads, array $filters, ?string $orderBy, int $pageSize, int $offset): array
    {
        $parsed = array_map(static function (mixed $filter): array {
            $f = self::parseFilter($filter);

            return ['attribute' => $f['attribute'], 'compare' => $f['compare'], 'value' => (string) $f['value']];
        }, array_values($filters));
        $order = self::parseOrderBy($orderBy);

        $payloads = array_values(array_filter(
            $loadPayloads(),
            static fn (array $payload): bool => self::matchesAll($payload, $parsed)
        ));
        if ($order !== null) {
            $payloads = self::sortByText($payloads, $order[0], $order[1] === 'DESC');
        }

        return \array_slice($payloads, $offset, $pageSize);
    }

    /**
     * Payloads whose $attribute has the given text form (`payload->>attr = value`), in the given order.
     *
     * @param array<int, array<int|string, mixed>> $payloads
     *
     * @return array<int, array<int|string, mixed>>
     */
    public static function whereAttributeEquals(array $payloads, string $attribute, string $value): array
    {
        return array_values(array_filter(
            $payloads,
            static fn (array $payload): bool => self::jsonTextForm($payload[$attribute] ?? null) === $value
        ));
    }

    /**
     * Orders payloads for a FLOW read: optional, by ONE attribute with jsonb-flavoured comparison
     * (numbers numerically, strings lexically — see {@see compareJsonValues()}). No attribute keeps
     * the given (storage) order.
     *
     * @param array<int, array<int|string, mixed>> $payloads
     *
     * @return array<int, array<int|string, mixed>>
     */
    public static function orderForFlow(array $payloads, ?string $orderBy, string $direction): array
    {
        $orderBy = trim((string) $orderBy);
        if ($orderBy !== '') {
            $desc = strtoupper($direction) === 'DESC';
            usort($payloads, static function (array $a, array $b) use ($orderBy, $desc): int {
                $cmp = self::compareJsonValues($a[$orderBy] ?? null, $b[$orderBy] ?? null);

                return $desc ? -$cmp : $cmp;
            });
        }

        return $payloads;
    }

    /**
     * The `payload ->> attr` text form of a decoded JSON value: null stays null (never matches),
     * booleans render true/false, structures render as their JSON text, scalars as strings.
     */
    public static function jsonTextForm(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    /**
     * @param array<int|string, mixed>                                         $payload
     * @param array<int, array{attribute: string, compare: string, value: string}> $filters
     */
    private static function matchesAll(array $payload, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!self::filterMatches($payload[$filter['attribute']] ?? null, $filter['compare'], $filter['value'])) {
                return false;
            }
        }

        return true;
    }

    /** One filter against one payload value — the PHP mirror of {@see appendSqlFilter()}'s SQL. */
    private static function filterMatches(mixed $raw, string $compare, string $value): bool
    {
        $text = self::jsonTextForm($raw);
        if ($text === null) {
            return false; // SQL comparisons against NULL never match
        }

        return match ($compare) {
            'EQ' => $text === $value,
            'LIKE' => self::likeMatches($text, $value),
            '<', '>' => self::orderedMatches($text, $compare, $value),
            default => false,
        };
    }

    /** SQL LIKE: `%` spans any run of characters (line breaks included), `_` exactly one. */
    private static function likeMatches(string $text, string $pattern): bool
    {
        $regex = str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '~'));

        return preg_match('~^' . $regex . '$~s', $text) === 1;
    }

    /** `<` / `>`: numeric when both sides are numeric, otherwise by text. */
    private static function orderedMatches(string $text, string $compare, string $value): bool
    {
        if (is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            $cmp = (float) $text <=> (float) $value;
        } else {
            $cmp = strcmp($text, $value) <=> 0;
        }

        return $compare === '<' ? $cmp < 0 : $cmp > 0;
    }

    /**
     * Text ordering like `payload->>attr`; SQL NULLs sort last ascending / first descending.
     *
     * @param array<int, array<int|string, mixed>> $payloads
     *
     * @return array<int, array<int|string, mixed>>
     */
    private static function sortByText(array $payloads, string $attribute, bool $desc): array
    {
        usort($payloads, static function (array $a, array $b) use ($attribute, $desc): int {
            $left = self::jsonTextForm($a[$attribute] ?? null);
            $right = self::jsonTextForm($b[$attribute] ?? null);
            if ($left === null || $right === null) {
                $cmp = ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
            } else {
                $cmp = strcmp($left, $right) <=> 0;
            }

            return $desc ? -$cmp : $cmp;
        });

        return $payloads;
    }

    /**
     * jsonb-flavoured ordering for {@see orderForFlow()}: numbers compare numerically, strings
     * lexically; mixed/other types fall back to their JSON text (an approximation of jsonb's
     * type-ranked order, fine for the homogeneous attributes flows sort on).
     */
    private static function compareJsonValues(mixed $a, mixed $b): int
    {
        return match (true) {
            is_numeric($a) && is_numeric($b) && !\is_string($a) && !\is_string($b) => $a <=> $b,
            \is_string($a) && \is_string($b) => strcmp($a, $b) <=> 0,
            is_numeric($a) && is_numeric($b) => (float) $a <=> (float) $b,
            default => strcmp((string) json_encode($a), (string) json_encode($b)) <=> 0,
        };
    }
}
