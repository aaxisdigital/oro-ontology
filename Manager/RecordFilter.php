<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * ONE attribute condition over stored record payloads — the selection of the Entities page's
 * "Erase" popup — in its two renderings, which must select exactly the same records:
 *
 *  - a SQL predicate over a jsonb payload column ({@see sql()}): the attribute path and the value
 *    are BOUND, the operator is whitelisted, so user input never reaches the SQL text;
 *  - the same condition evaluated in PHP ({@see matches()}) over a decoded payload, for
 *    BUCKET-backed entities whose records are not in the table.
 *
 * Semantics, both renderings:
 *  - the attribute is a payload path, dots separating nested keys (`address.city`) — the same
 *    dotted names the attribute reconciler creates; the value compared is that path's TEXT form
 *    (`payload #>> path`: null when missing or JSON null, true/false, structures as JSON text);
 *  - eq / ne compare that text exactly; ne also selects records WITHOUT the attribute (it is the
 *    negation of eq), and so do not_contains / not_matches of theirs;
 *  - lt / lte / gt / gte compare NUMERICALLY when the value is a number (records whose attribute
 *    is not numeric are then never selected), otherwise by text in byte order (`COLLATE "C"`,
 *    PHP's strcmp);
 *  - contains: case-insensitive substring; matches: case-insensitive WILDCARD pattern over the
 *    whole text (`*` any run of characters, `?` exactly one);
 *  - empty: missing, null or ''; not_empty: anything else.
 */
final class RecordFilter
{
    public const array OPERATORS = [
        'eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'contains', 'not_contains', 'matches', 'not_matches', 'empty', 'not_empty',
    ];

    /** Operators that take no value. */
    private const array VALUELESS = ['empty', 'not_empty'];

    private const array ORDERED = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];

    /** A plain decimal number — what `payload #>>` renders for JSON numbers. */
    private const string NUMERIC = '-?[0-9]+(\.[0-9]+)?';

    private const int MAX_ATTRIBUTE = 255;
    private const int MAX_VALUE = 1000;

    /**
     * The selection posted by the popup: `{mode: 'all'}` → null (every record), or
     * `{mode: 'filter', attribute, operator, value}` → the validated condition.
     *
     * @param array<string, mixed> $input
     *
     * @return array{attribute: string, path: list<string>, operator: string, value: string}|null
     *
     * @throws \InvalidArgumentException with a message fit for the user
     */
    public static function parse(array $input): ?array
    {
        $mode = (string) ($input['mode'] ?? '');
        if ($mode === 'all') {
            return null;
        }
        if ($mode !== 'filter') {
            throw new \InvalidArgumentException('Choose "All records" or a filter.');
        }

        $attribute = trim((string) ($input['attribute'] ?? ''));
        $path = explode('.', $attribute);
        if ($attribute === '' || \strlen($attribute) > self::MAX_ATTRIBUTE || \in_array('', $path, true)) {
            throw new \InvalidArgumentException('Choose the attribute to filter on.');
        }

        $operator = (string) ($input['operator'] ?? '');
        if (!\in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported comparison "%s".', $operator));
        }

        $raw = $input['value'] ?? '';
        $value = \is_scalar($raw) ? (string) $raw : '';
        if (\in_array($operator, self::VALUELESS, true)) {
            $value = '';
        } elseif ($value === '') {
            throw new \InvalidArgumentException('Enter the value to compare with.');
        } elseif (mb_strlen($value) > self::MAX_VALUE) {
            throw new \InvalidArgumentException(sprintf('The value may hold at most %d characters.', self::MAX_VALUE));
        }

        return ['attribute' => $attribute, 'path' => $path, 'operator' => $operator, 'value' => $value];
    }

    // --- SQL ----------------------------------------------------------------------------------

    /**
     * The condition as a SQL predicate over $column (a jsonb payload column, qualified when the
     * statement joins tables), binding `filterPath` and `filterValue` into $params.
     *
     * @param array{path: list<string>, operator: string, value: string} $filter
     * @param array<string, mixed>                                         $params
     */
    public static function sql(array $filter, string $column, array &$params): string
    {
        // The path is ONE bound string split in SQL, so no array literal is ever built by hand.
        $params['filterPath'] = implode('.', $filter['path']);
        $text = sprintf("(%s #>> string_to_array(:filterPath, '.'))", $column);
        $operator = $filter['operator'];
        if (\in_array($operator, self::VALUELESS, true)) {
            return sprintf("COALESCE(%s, '') %s ''", $text, $operator === 'empty' ? '=' : '<>');
        }

        $params['filterValue'] = $operator === 'matches' || $operator === 'not_matches'
            ? self::likePattern($filter['value'])
            : $filter['value'];

        return match ($operator) {
            'eq' => sprintf('%s = :filterValue', $text),
            'ne' => sprintf('%s IS DISTINCT FROM :filterValue', $text),
            'contains' => sprintf('strpos(lower(%s), lower(:filterValue)) > 0', $text),
            'not_contains' => sprintf('NOT COALESCE(strpos(lower(%s), lower(:filterValue)) > 0, false)', $text),
            'matches' => sprintf('%s ILIKE :filterValue', $text),
            'not_matches' => sprintf('NOT COALESCE(%s ILIKE :filterValue, false)', $text),
            default => self::orderedSql($text, self::ORDERED[$operator], $filter['value']),
        };
    }

    private static function orderedSql(string $text, string $sqlOperator, string $value): string
    {
        if (self::isNumber($value)) {
            // Only numeric-looking values are cast, so a text value never raises a cast error.
            return sprintf("(%s ~ '^%s$' AND %s::numeric %s CAST(:filterValue AS numeric))", $text, self::NUMERIC, $text, $sqlOperator);
        }

        return sprintf('%s COLLATE "C" %s :filterValue', $text, $sqlOperator);
    }

    /** The wildcard pattern as an ILIKE pattern: LIKE's own metacharacters escaped first. */
    private static function likePattern(string $pattern): string
    {
        return strtr($pattern, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '*' => '%', '?' => '_']);
    }

    // --- PHP ----------------------------------------------------------------------------------

    /**
     * The same condition over one decoded payload — the mirror of {@see sql()}.
     *
     * @param array<int|string, mixed>                                     $payload
     * @param array{path: list<string>, operator: string, value: string} $filter
     */
    public static function matches(array $payload, array $filter): bool
    {
        $text = PayloadQuery::jsonTextForm(self::valueAt($payload, $filter['path']));
        $value = $filter['value'];

        return match ($filter['operator']) {
            'empty' => $text === null || $text === '',
            'not_empty' => $text !== null && $text !== '',
            'eq' => $text === $value,
            'ne' => $text !== $value,
            'contains' => self::contains($text, $value),
            'not_contains' => !self::contains($text, $value),
            'matches' => self::wildcardMatches($text, $value),
            'not_matches' => !self::wildcardMatches($text, $value),
            default => self::orderedMatches($text, $filter['operator'], $value),
        };
    }

    /**
     * `#>>`'s walk: each segment indexes an object key (or a list position); anything else ends
     * the path at null.
     *
     * @param array<int|string, mixed> $payload
     * @param list<string>             $path
     */
    private static function valueAt(array $payload, array $path): mixed
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function contains(?string $text, string $value): bool
    {
        return $text !== null && str_contains(mb_strtolower($text), mb_strtolower($value));
    }

    private static function wildcardMatches(?string $text, string $pattern): bool
    {
        if ($text === null) {
            return false;
        }
        $regex = strtr(preg_quote($pattern, '~'), ['\\*' => '.*', '\\?' => '.']);

        return preg_match('~^' . $regex . '$~isu', $text) === 1;
    }

    private static function orderedMatches(?string $text, string $operator, string $value): bool
    {
        if ($text === null) {
            return false;
        }
        if (self::isNumber($value)) {
            if (!self::isNumber($text)) {
                return false;
            }
            $cmp = (float) $text <=> (float) $value;
        } else {
            $cmp = strcmp($text, $value) <=> 0;
        }

        return match ($operator) {
            'lt' => $cmp < 0,
            'lte' => $cmp <= 0,
            'gt' => $cmp > 0,
            default => $cmp >= 0,
        };
    }

    private static function isNumber(string $value): bool
    {
        return preg_match('~^' . self::NUMERIC . '$~', $value) === 1;
    }
}
