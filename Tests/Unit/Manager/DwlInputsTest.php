<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Manager\DwlInputs;
use PHPUnit\Framework\TestCase;

final class DwlInputsTest extends TestCase
{
    /** @var array<int, OntologyEntity> */
    private array $entities = [];

    protected function setUp(): void
    {
        foreach ([10, 11] as $id) {
            $entity = new OntologyEntity();
            (new \ReflectionProperty(OntologyEntity::class, 'id'))->setValue($entity, $id);
            $this->entities[$id] = $entity;
        }
    }

    private function parse(mixed $raw): array|string
    {
        return DwlInputs::parse($raw, fn (int $id): ?OntologyEntity => $this->entities[$id] ?? null);
    }

    /**
     * @dataProvider nameProvider
     */
    public function testANameIsADwlVariable(string $name, bool $valid): void
    {
        self::assertSame($valid, DwlInputs::isName($name));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function nameProvider(): array
    {
        return [
            'payload' => ['payload', true],
            'snake case' => ['order_lines', true],
            'leading underscore' => ['_x1', true],
            'max length' => [str_repeat('a', 64), true],
            'too long' => [str_repeat('a', 65), false],
            'leading digit' => ['1st', false],
            'a dash' => ['order-lines', false],
            'a space' => ['a b', false],
            'accented' => ['pedidó', false],
            'empty' => ['', false],
            'a keyword' => ['match', false],
            'a keyword in capitals' => ['Default', false],
        ];
    }

    public function testItReadsTheInputsInOrderWithTheirEntities(): void
    {
        $inputs = $this->parse([['entityId' => 10, 'name' => ' orders '], ['entityId' => 11, 'name' => 'lines']]);

        self::assertSame([['name' => 'orders', 'entity' => $this->entities[10]], ['name' => 'lines', 'entity' => $this->entities[11]]], $inputs);
        self::assertSame([['entityId' => 10, 'name' => 'orders'], ['entityId' => 11, 'name' => 'lines']], DwlInputs::toStored($inputs));
        self::assertSame([], $this->parse(null));
        self::assertSame([], $this->parse([]));
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testItRefusesBrokenLists(mixed $raw, string $reason): void
    {
        self::assertSame($reason, $this->parse($raw));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'not a list' => [['a' => ['entityId' => 10, 'name' => 'x']], 'inputs_invalid'],
            'not an array' => ['orders', 'inputs_invalid'],
            'too many' => [array_fill(0, DwlInputs::MAX_INPUTS + 1, ['entityId' => 10, 'name' => 'x']), 'inputs_invalid'],
            'an entry that is not an object' => [[5], 'inputs_invalid'],
            'an entity id that is not an int' => [[['entityId' => '10', 'name' => 'x']], 'inputs_invalid'],
            'a bad name' => [[['entityId' => 10, 'name' => 'order-lines']], 'input_name_invalid'],
            'no name' => [[['entityId' => 10]], 'input_name_invalid'],
            'a repeated name' => [[['entityId' => 10, 'name' => 'x'], ['entityId' => 11, 'name' => 'x']], 'input_name_duplicate'],
            'a missing entity' => [[['entityId' => 10, 'name' => 'x'], ['entityId' => 99, 'name' => 'y']], 'entity_missing'],
        ];
    }

    public function testTheSameEntityMayBeReadTwiceUnderTwoNames(): void
    {
        self::assertCount(2, $this->parse([['entityId' => 10, 'name' => 'a'], ['entityId' => 10, 'name' => 'b']]));
    }
}
