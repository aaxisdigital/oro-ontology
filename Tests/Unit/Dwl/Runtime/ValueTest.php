<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\ValueType;
use PHPUnit\Framework\TestCase;

class ValueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        class_exists(Value::class); // ValueType lives in Value.php
    }

    public function testObjectKeyLookupKeepsTheFirstOccurrence(): void
    {
        $object = Value::object([
            [Value::string('a'), Value::number(1)],
            [Value::number(2), Value::string('two')],
            [Value::string('a'), Value::number(3)],
        ]);

        self::assertSame(1, $object->getKey('a')->data);
        self::assertSame('two', $object->getKey('2')->data);
        self::assertNull($object->getKey('missing'));
        // The index is built once and reused.
        self::assertSame(1, $object->getKey('a')->data);
    }

    public function testKeyLookupOnANonObjectIsNull(): void
    {
        self::assertNull(Value::array([Value::number(1)])->getKey('0'));
    }

    public function testSingletons(): void
    {
        self::assertSame(Value::null(), Value::null());
        self::assertSame(Value::boolean(true), Value::boolean(true));
        self::assertSame(Value::boolean(false), Value::boolean(false));
        self::assertNotSame(Value::boolean(true), Value::boolean(false));
    }

    /**
     * @dataProvider comparisons
     */
    public function testCompare(Value $a, Value $b, int $expected): void
    {
        self::assertSame($expected, Value::compare($a, $b) <=> 0);
    }

    public static function comparisons(): array
    {
        class_exists(Value::class);
        $date = static fn (string $d): Value => Value::dateTime(new \DateTimeImmutable($d), 'date');

        return [
            'strings lexically' => [Value::string('b'), Value::string('a'), 1],
            'strings by byte order' => [Value::string('B'), Value::string('a'), -1],
            'booleans' => [Value::boolean(false), Value::boolean(true), -1],
            'numbers' => [Value::number(2), Value::number(2.0), 0],
            'dates' => [$date('2020-01-02'), $date('2020-01-01'), 1],
            'date and datetime' => [$date('2020-01-01'), Value::dateTime(new \DateTimeImmutable('2020-01-01'), 'datetime'), 0],
            'mixed falls back to numbers' => [Value::string('10'), Value::number(9), 1],
            'null counts as zero' => [Value::null(), Value::number(1), -1],
        ];
    }

    /**
     * @dataProvider truthiness
     */
    public function testTruthiness(Value $value, bool $expected): void
    {
        self::assertSame($expected, $value->isTruthy());
    }

    public static function truthiness(): array
    {
        class_exists(Value::class);

        return [
            'true' => [Value::boolean(true), true],
            'false' => [Value::boolean(false), false],
            'null' => [Value::null(), false],
            'empty string' => [Value::string(''), false],
            'string' => [Value::string('0'), true],
            'zero' => [Value::number(0), false],
            'float zero' => [Value::number(0.0), false],
            'number' => [Value::number(-1), true],
            'empty array' => [Value::array([]), false],
            'array' => [Value::array([Value::null()]), true],
            'empty object' => [Value::object([]), false],
            'object' => [Value::object([[Value::string('a'), Value::null()]]), true],
            'regex' => [Value::regex(''), true],
            'function' => [Value::func(static fn () => null), true],
        ];
    }

    public function testToPhp(): void
    {
        $value = Value::array([
            Value::string('s'),
            Value::number(1.5),
            Value::boolean(true),
            Value::null(),
            Value::object([[Value::string('k'), Value::array([])]]),
            Value::dateTime(new \DateTimeImmutable('2020-01-02 03:04:05.25', new \DateTimeZone('+02:00')), 'datetime'),
            Value::period(new \DateInterval('P1D'), 'P1D'),
            Value::regex('a+'),
        ]);

        self::assertEquals(
            ['s', 1.5, true, null, (object) ['k' => []], '2020-01-02T03:04:05.250000+02:00', 'P1D', 'a+'],
            $value->toPhp()
        );
    }

    public function testObjectsWithRawEntriesConvertAsIs(): void
    {
        $object = new Value(ValueType::Object, [['raw', 'value']]);

        self::assertEquals((object) ['raw' => 'value'], $object->toPhp());
    }

    /**
     * @dataProvider temporalKinds
     */
    public function testFormatTemporal(string $kind, string $expected): void
    {
        $dateTime = new \DateTimeImmutable('2020-01-02 03:04:05', new \DateTimeZone('UTC'));

        self::assertSame($expected, Value::formatTemporal(Value::dateTime($dateTime, $kind)));
    }

    public static function temporalKinds(): array
    {
        return [
            'date' => ['date', '2020-01-02'],
            'time' => ['time', '03:04:05'],
            'local time' => ['localtime', '03:04:05'],
            'local datetime' => ['local_datetime', '2020-01-02T03:04:05'],
            'datetime' => ['datetime', '2020-01-02T03:04:05+00:00'],
        ];
    }

    public function testFormatTemporalDefaultsToDatetimeWithoutAKind(): void
    {
        $value = new Value(ValueType::Date, new \DateTimeImmutable('2020-01-02 03:04:05', new \DateTimeZone('UTC')));

        self::assertSame('2020-01-02T03:04:05+00:00', $value->toPhp());
    }

    public function testPeriodWithoutIsoRendersEmpty(): void
    {
        self::assertSame('', (new Value(ValueType::Period, new \DateInterval('P1D')))->toPhp());
    }
}
