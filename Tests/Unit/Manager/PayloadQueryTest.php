<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\PayloadQuery;
use PHPUnit\Framework\TestCase;

class PayloadQueryTest extends TestCase
{
    // --- parseFilter --------------------------------------------------------------------------

    public function testAFilterDefaultsToEqualsAndKeepsItsScalarValue(): void
    {
        self::assertSame(['attribute' => 'a', 'compare' => 'EQ', 'value' => null], PayloadQuery::parseFilter(['attribute' => ' a ']));
        self::assertSame(['attribute' => 'a', 'compare' => '>', 'value' => 5], PayloadQuery::parseFilter(['attribute' => 'a', 'compare' => '>', 'value' => 5]));
        self::assertSame(['attribute' => 'a', 'compare' => 'LIKE', 'value' => true], PayloadQuery::parseFilter(['attribute' => 'a', 'compare' => 'LIKE', 'value' => true]));
    }

    /**
     * @dataProvider badFilterProvider
     */
    public function testBadFiltersAreRejected(mixed $filter, string $message): void
    {
        try {
            PayloadQuery::parseFilter($filter);
            self::fail('an OntologyApiException was expected');
        } catch (OntologyApiException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame('invalid_query', $e->getErrorCode());
            self::assertSame(400, $e->getStatusCode());
        }
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badFilterProvider(): array
    {
        return [
            'not an array' => ['x', 'Each filter requires a non-empty "attribute".'],
            'no attribute' => [[], 'Each filter requires a non-empty "attribute".'],
            'blank attribute' => [['attribute' => " \t"], 'Each filter requires a non-empty "attribute".'],
            'lower-case comparator' => [['attribute' => 'a', 'compare' => 'eq'], 'Unsupported filter compare "eq".'],
            'unknown comparator' => [['attribute' => 'a', 'compare' => '>=', 'value' => 1], 'Unsupported filter compare ">=".'],
            'array value' => [['attribute' => 'a', 'value' => [1]], 'Filter "value" must be a scalar.'],
        ];
    }

    // --- parseOrderBy -------------------------------------------------------------------------

    /**
     * @dataProvider orderByProvider
     *
     * @param array{string, string}|null $expected
     */
    public function testParseOrderBy(?string $orderBy, ?array $expected): void
    {
        self::assertSame($expected, PayloadQuery::parseOrderBy($orderBy));
    }

    /**
     * @return array<string, array{string|null, array{string, string}|null}>
     */
    public static function orderByProvider(): array
    {
        return [
            'null' => [null, null],
            'blank' => ["  \n", null],
            'attribute only' => ['name', ['name', 'ASC']],
            'ascending' => [' name asc ', ['name', 'ASC']],
            'descending' => ["name \t DESC", ['name', 'DESC']],
            'extra words are ignored' => ['name DESC nulls last', ['name', 'DESC']],
        ];
    }

    public function testAnUnknownDirectionIsRejected(): void
    {
        $this->expectException(OntologyApiException::class);
        $this->expectExceptionMessage('Unsupported orderBy direction "UP".');

        PayloadQuery::parseOrderBy('name up');
    }

    // --- SQL rendering ------------------------------------------------------------------------

    public function testSqlFiltersBindEveryAttributeAndValue(): void
    {
        $where = [];
        $params = [];

        PayloadQuery::appendSqlFilter(0, ['attribute' => 'a; DROP TABLE x', 'value' => "o'brien"], $where, $params);
        PayloadQuery::appendSqlFilter(1, ['attribute' => 'q', 'compare' => '<', 'value' => '2.5'], $where, $params);
        PayloadQuery::appendSqlFilter(2, ['attribute' => 't', 'compare' => '<', 'value' => 'abc'], $where, $params);
        PayloadQuery::appendSqlFilter(3, ['attribute' => 'n', 'compare' => 'LIKE'], $where, $params);

        self::assertSame([
            'payload->>:attr0 = :val0',
            "(payload->>:attr1 ~ '^-?[0-9]+(\\.[0-9]+)?\$' AND (payload->>:attr1)::numeric < CAST(:val1 AS numeric))",
            'payload->>:attr2 < :val2',
            'payload->>:attr3 LIKE :val3',
        ], $where);
        self::assertSame([
            'attr0' => 'a; DROP TABLE x', 'val0' => "o'brien",
            'attr1' => 'q', 'val1' => '2.5',
            'attr2' => 't', 'val2' => 'abc',
            'attr3' => 'n', 'val3' => '',
        ], $params);
    }

    public function testSqlFiltersRejectBadInputWithoutTouchingTheOutputs(): void
    {
        $where = ['entity_id = :entity_id'];
        $params = ['entity_id' => 1];

        try {
            PayloadQuery::appendSqlFilter(0, ['attribute' => 'a', 'compare' => 'NOPE'], $where, $params);
            self::fail('an OntologyApiException was expected');
        } catch (OntologyApiException) {
            self::assertSame(['entity_id = :entity_id'], $where);
            self::assertSame(['entity_id' => 1], $params);
        }
    }

    public function testSqlOrderByDefaultsToIdAndBindsTheAttribute(): void
    {
        $params = [];
        self::assertSame('id ASC', PayloadQuery::sqlOrderBy(null, $params));
        self::assertSame([], $params);

        self::assertSame('payload->>:orderAttr DESC', PayloadQuery::sqlOrderBy('created desc', $params));
        self::assertSame(['orderAttr' => 'created'], $params);
    }

    // --- PHP rendering ------------------------------------------------------------------------

    public function testRunValidatesBeforeLoadingAnything(): void
    {
        $loaded = false;
        $load = function () use (&$loaded): array {
            $loaded = true;

            return [];
        };

        foreach ([[[['compare' => 'EQ']], null], [[], 'a sideways']] as [$filters, $orderBy]) {
            try {
                PayloadQuery::run($load, $filters, $orderBy, 10, 0);
                self::fail('an OntologyApiException was expected');
            } catch (OntologyApiException) {
                self::assertFalse($loaded);
            }
        }
    }

    public function testRunFiltersOrdersAndPages(): void
    {
        $payloads = [
            ['id' => 1, 'g' => 'x', 'n' => 'b'],
            ['id' => 2, 'g' => 'y', 'n' => 'a'],
            ['id' => 3, 'g' => 'x', 'n' => 'c'],
            ['id' => 4, 'g' => 'x'],
        ];

        $rows = PayloadQuery::run(fn (): array => $payloads, [['attribute' => 'g', 'value' => 'x']], 'n desc', 2, 0);
        self::assertSame([4, 3], array_column($rows, 'id'), 'descending puts the missing (NULL) value first');

        $rows = PayloadQuery::run(fn (): array => $payloads, [['attribute' => 'g', 'value' => 'x']], 'n', 2, 2);
        self::assertSame([4], array_column($rows, 'id'), 'ascending puts it last; the second page has the remainder');

        self::assertSame([1, 2, 3, 4], array_column(PayloadQuery::run(fn (): array => $payloads, [], null, 10, 0), 'id'));
    }

    public function testRunRejectsAnUnknownComparatorInsteadOfMatchingNothing(): void
    {
        $this->expectException(OntologyApiException::class);
        $this->expectExceptionMessage('Unsupported filter compare "REGEX".');

        PayloadQuery::run(fn (): array => [['a' => 1]], [['attribute' => 'a', 'compare' => 'REGEX', 'value' => '.']], null, 10, 0);
    }

    /**
     * @dataProvider textFormProvider
     */
    public function testJsonTextForm(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, PayloadQuery::jsonTextForm($value));
    }

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function textFormProvider(): array
    {
        return [
            'null' => [null, null],
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'int' => [12, '12'],
            'float' => [1.5, '1.5'],
            'string' => ['x', 'x'],
            'empty string' => ['', ''],
            'list' => [[1, 'a'], '[1,"a"]'],
            'object with unicode and slashes' => [['k' => 'ü/é'], '{"k":"ü/é"}'],
            'empty array' => [[], '[]'],
        ];
    }

    public function testWhereAttributeEqualsComparesTheTextForm(): void
    {
        $payloads = [['a' => 1], ['a' => '1'], ['a' => true], ['a' => null], []];

        self::assertSame([['a' => 1], ['a' => '1']], PayloadQuery::whereAttributeEquals($payloads, 'a', '1'));
        self::assertSame([['a' => true]], PayloadQuery::whereAttributeEquals($payloads, 'a', 'true'));
        self::assertSame([], PayloadQuery::whereAttributeEquals($payloads, 'a', ''));
    }

    public function testOrderForFlowKeepsStorageOrderWithoutAnAttribute(): void
    {
        $payloads = [['n' => 2], ['n' => 1]];

        self::assertSame($payloads, PayloadQuery::orderForFlow($payloads, null, 'DESC'));
        self::assertSame($payloads, PayloadQuery::orderForFlow($payloads, '  ', 'ASC'));
    }

    /**
     * @dataProvider flowOrderProvider
     *
     * @param array<int, mixed> $values
     * @param array<int, mixed> $expected
     */
    public function testOrderForFlowComparesByType(array $values, string $direction, array $expected): void
    {
        $payloads = array_map(static fn (mixed $v): array => ['v' => $v], $values);

        $ordered = PayloadQuery::orderForFlow($payloads, ' v ', $direction);

        self::assertSame($expected, array_column($ordered, 'v'));
    }

    /**
     * @return array<string, array{array<int, mixed>, string, array<int, mixed>}>
     */
    public static function flowOrderProvider(): array
    {
        return [
            'numbers by value, not text' => [[10, 9, 100, 2.5], 'ASC', [2.5, 9, 10, 100]],
            'numbers descending' => [[10, 9, 100], 'desc', [100, 10, 9]],
            'strings lexically' => [['pear', 'apple', 'fig'], 'ASC', ['apple', 'fig', 'pear']],
            'numeric strings compare as text among themselves' => [['10', '9', '100'], 'ASC', ['10', '100', '9']],
            'a numeric string against a number compares by value' => [['100', 20, '3'], 'ASC', ['3', 20, '100']],
            'anything else by its json text' => [[true, false], 'ASC', [false, true]],
        ];
    }
}
