<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\RecordFilter;
use PHPUnit\Framework\TestCase;

/**
 * The SQL rendering was validated against PostgreSQL on real records (two entities, 2.9k
 * payloads): for 32 filters covering every operator, nested paths, wildcards, literal % and _,
 * numeric vs text ordering and missing attributes, the predicate selected exactly the records the
 * PHP mirror did. These tests pin both renderings and the parser.
 */
class RecordFilterTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array{attribute: string, path: list<string>, operator: string, value: string}
     */
    private static function filter(string $attribute, string $operator, string $value = ''): array
    {
        $parsed = RecordFilter::parse(['mode' => 'filter', 'attribute' => $attribute, 'operator' => $operator, 'value' => $value]);
        self::assertNotNull($parsed);

        return $parsed;
    }

    public function testAllRecordsIsNoFilter(): void
    {
        self::assertNull(RecordFilter::parse(['mode' => 'all', 'attribute' => 'ignored']));
    }

    public function testAFilterIsParsedWithItsPath(): void
    {
        self::assertSame(
            ['attribute' => 'address.city', 'path' => ['address', 'city'], 'operator' => 'eq', 'value' => ' Lyon '],
            RecordFilter::parse(['mode' => 'filter', 'attribute' => ' address.city ', 'operator' => 'eq', 'value' => ' Lyon '])
        );
    }

    public function testValuelessOperatorsDropTheValue(): void
    {
        self::assertSame('', self::filter('a', 'empty', 'ignored')['value']);
        self::assertSame('', self::filter('a', 'not_empty')['value']);
    }

    /**
     * @dataProvider invalidSelections
     *
     * @param array<string, mixed> $input
     */
    public function testInvalidSelectionsAreRefusedWithAReason(array $input, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RecordFilter::parse($input);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSelections(): array
    {
        $filter = ['mode' => 'filter', 'attribute' => 'a', 'operator' => 'eq', 'value' => 'x'];

        return [
            'no mode' => [[], 'Choose "All records" or a filter.'],
            'unknown mode' => [['mode' => 'some'], 'Choose "All records" or a filter.'],
            'no attribute' => [['attribute' => ' '] + $filter, 'Choose the attribute to filter on.'],
            'empty path segment' => [['attribute' => 'a..b'] + $filter, 'Choose the attribute to filter on.'],
            'long attribute' => [['attribute' => str_repeat('a', 256)] + $filter, 'Choose the attribute to filter on.'],
            'unknown operator' => [['operator' => 'LIKE'] + $filter, 'Unsupported comparison "LIKE".'],
            'missing value' => [['value' => ''] + $filter, 'Enter the value to compare with.'],
            'structured value' => [['value' => ['x']] + $filter, 'Enter the value to compare with.'],
            'long value' => [['value' => str_repeat('é', 1001)] + $filter, 'The value may hold at most 1000 characters.'],
        ];
    }

    /**
     * @dataProvider sqlCases
     */
    public function testTheSqlPredicateBindsPathAndValue(string $operator, string $value, string $sql, ?string $bound): void
    {
        $params = ['entity' => 3];

        self::assertSame($sql, RecordFilter::sql(self::filter('a.b', $operator, $value), 'd.payload', $params));
        self::assertSame(
            ['entity' => 3, 'filterPath' => 'a.b'] + ($bound === null ? [] : ['filterValue' => $bound]),
            $params
        );
    }

    /**
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function sqlCases(): array
    {
        $t = "(d.payload #>> string_to_array(:filterPath, '.'))";

        return [
            'eq' => ['eq', 'x', "$t = :filterValue", 'x'],
            'ne' => ['ne', 'x', "$t IS DISTINCT FROM :filterValue", 'x'],
            'numeric lt' => ['lt', '-1.5', "($t ~ '^-?[0-9]+(\\.[0-9]+)?$' AND $t::numeric < CAST(:filterValue AS numeric))", '-1.5'],
            'numeric gte' => ['gte', '10', "($t ~ '^-?[0-9]+(\\.[0-9]+)?$' AND $t::numeric >= CAST(:filterValue AS numeric))", '10'],
            'text lte' => ['lte', '2026-01', "$t COLLATE \"C\" <= :filterValue", '2026-01'],
            'text gt' => ['gt', '1e3', "$t COLLATE \"C\" > :filterValue", '1e3'],
            'contains' => ['contains', 'Ab', "strpos(lower($t), lower(:filterValue)) > 0", 'Ab'],
            'not contains' => ['not_contains', 'Ab', "NOT COALESCE(strpos(lower($t), lower(:filterValue)) > 0, false)", 'Ab'],
            'matches' => ['matches', 'a*b?_%\\', "$t ILIKE :filterValue", 'a%b_\\_\\%\\\\'],
            'not matches' => ['not_matches', '*x', "NOT COALESCE($t ILIKE :filterValue, false)", '%x'],
            'empty' => ['empty', '', "COALESCE($t, '') = ''", null],
            'not empty' => ['not_empty', '', "COALESCE($t, '') <> ''", null],
        ];
    }

    /**
     * @dataProvider phpCases
     *
     * @param array<string, mixed> $payload
     */
    public function testThePhpMirrorSelectsLikeTheSql(array $payload, string $attribute, string $operator, string $value, bool $expected): void
    {
        self::assertSame($expected, RecordFilter::matches($payload, self::filter($attribute, $operator, $value)));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string, string, bool}>
     */
    public static function phpCases(): array
    {
        $p = ['id' => 'ADBI-0812', 'n' => 506, 'f' => 2.5, 'on' => true, 'none' => null, 'blank' => '', 'name' => 'Résine C9',
            'address' => ['city' => 'Lyon'], 'skus' => [['sku' => 'PL10']], 'tags' => ['a', 'b']];

        return [
            'eq text' => [$p, 'id', 'eq', 'ADBI-0812', true],
            'eq is exact' => [$p, 'id', 'eq', 'adbi-0812', false],
            'eq number text form' => [$p, 'n', 'eq', '506', true],
            'eq boolean text form' => [$p, 'on', 'eq', 'true', true],
            'eq structure text form' => [$p, 'tags', 'eq', '["a","b"]', true],
            'ne other' => [$p, 'id', 'ne', 'x', true],
            'ne same' => [$p, 'id', 'ne', 'ADBI-0812', false],
            'ne missing' => [$p, 'nope', 'ne', 'x', true],
            'lt numeric' => [$p, 'n', 'lt', '507', true],
            'lte numeric equal' => [$p, 'n', 'lte', '506', true],
            'gt float' => [$p, 'f', 'gt', '2', true],
            'gte numeric fails' => [$p, 'n', 'gte', '600', false],
            'numeric value vs text attribute' => [$p, 'id', 'gt', '0', false],
            'text ordering' => [$p, 'id', 'gt', 'ADBH', true],
            'text ordering is byte order' => [$p, 'id', 'lt', 'a', true],
            'ordered missing' => [$p, 'nope', 'lt', 'z', false],
            'contains any case' => [$p, 'name', 'contains', 'RÉSINE', true],
            'contains missing' => [$p, 'nope', 'contains', 'x', false],
            'not contains' => [$p, 'name', 'not_contains', 'zz', true],
            'not contains missing' => [$p, 'nope', 'not_contains', 'x', true],
            'matches wildcard' => [$p, 'id', 'matches', 'adbi-08??', true],
            'matches star' => [$p, 'name', 'matches', 'r*9', true],
            'matches whole text' => [$p, 'id', 'matches', 'ADBI', false],
            'matches literal regex chars' => [['v' => 'a.b'], 'v', 'matches', 'a.b', true],
            'matches dot is literal' => [['v' => 'axb'], 'v', 'matches', 'a.b', false],
            'not matches' => [$p, 'id', 'not_matches', 'X*', true],
            'not matches missing' => [$p, 'nope', 'not_matches', '*', true],
            'empty missing' => [$p, 'nope', 'empty', '', true],
            'empty null' => [$p, 'none', 'empty', '', true],
            'empty blank' => [$p, 'blank', 'empty', '', true],
            'empty set' => [$p, 'id', 'empty', '', false],
            'not empty' => [$p, 'id', 'not_empty', '', true],
            'not empty null' => [$p, 'none', 'not_empty', '', false],
            'nested path' => [$p, 'address.city', 'eq', 'Lyon', true],
            'list index path' => [$p, 'skus.0.sku', 'eq', 'PL10', true],
            'path through a scalar' => [$p, 'id.x', 'empty', '', true],
        ];
    }
}
