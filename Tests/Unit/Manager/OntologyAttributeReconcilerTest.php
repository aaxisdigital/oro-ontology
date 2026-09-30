<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute as Attr;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyAttributeReconciler;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

class OntologyAttributeReconcilerTest extends TestCase
{
    private function reconciler(int $expectedFlushes = 0): OntologyAttributeReconciler
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly($expectedFlushes))->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(OntologyEntity::class)->willReturn($manager);

        return new OntologyAttributeReconciler($registry);
    }

    /**
     * @param array<string, array{string, bool}|string> $attributes name => datatype | [datatype, required]
     */
    private function entity(array $attributes, string $unique = 'id'): OntologyEntity
    {
        $entity = (new OntologyEntity())->setName('Order')->setUniqueAttribute($unique);
        foreach ($attributes as $name => $spec) {
            [$datatype, $required] = \is_array($spec) ? $spec : [$spec, false];
            $entity->addAttribute((new Attr())->setName((string) $name)->setDatatype($datatype)->setRequired($required));
        }

        return $entity;
    }

    /**
     * @return array<string, Attr>
     */
    private static function attributesByName(OntologyEntity $entity): array
    {
        $byName = [];
        foreach ($entity->getAttributes() as $attribute) {
            $byName[(string) $attribute->getName()] = $attribute;
        }

        return $byName;
    }

    private function assertRejected(OntologyEntity $entity, array $payload, string $message): void
    {
        try {
            $this->reconciler()->assertValid($entity, $payload);
            self::fail('an OntologyApiException was expected');
        } catch (OntologyApiException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('invalid_payload', $e->getErrorCode());
        }
    }

    // --- assertValid --------------------------------------------------------------------------

    public function testAValidRecordPasses(): void
    {
        $entity = $this->entity([
            'id' => [Attr::TYPE_TEXT, true],
            'qty' => Attr::TYPE_NUMBER,
            'paid' => Attr::TYPE_BOOLEAN,
            'meta' => Attr::TYPE_OBJECT,
            'placed' => Attr::TYPE_DATETIME,
            'anything' => Attr::TYPE_UNDEFINED,
            'optional' => Attr::TYPE_TEXT,
        ]);

        $this->reconciler()->assertValid($entity, [
            'id' => 'SO-1',
            'qty' => 3.5,
            'paid' => false,
            'meta' => ['a' => 1],
            'placed' => '2026-01-01T00:00:00Z',
            'anything' => [1, 'x', null],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testAMissingRequiredAttributeIsRejected(): void
    {
        $this->assertRejected($this->entity(['id' => [Attr::TYPE_TEXT, true]]), ['other' => 1], 'Missing required attribute "id".');
    }

    public function testANullRequiredAttributeCountsAsMissing(): void
    {
        $this->assertRejected($this->entity(['id' => [Attr::TYPE_UNDEFINED, true]]), ['id' => null], 'Missing required attribute "id".');
    }

    public function testFalsyValuesAreNotMissing(): void
    {
        $entity = $this->entity(['flag' => [Attr::TYPE_BOOLEAN, true], 'n' => [Attr::TYPE_NUMBER, true], 's' => [Attr::TYPE_TEXT, true]]);

        $this->reconciler()->assertValid($entity, ['flag' => false, 'n' => 0, 's' => '']);

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider mismatchProvider
     */
    public function testATypeMismatchIsRejected(string $datatype, mixed $value): void
    {
        $this->assertRejected(
            $this->entity(['field' => $datatype]),
            ['field' => $value],
            sprintf('Attribute "field" must be of type "%s".', $datatype)
        );
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function mismatchProvider(): array
    {
        return [
            'boolean given a string' => [Attr::TYPE_BOOLEAN, 'true'],
            'boolean given an int' => [Attr::TYPE_BOOLEAN, 1],
            'number given a numeric string' => [Attr::TYPE_NUMBER, '12'],
            'number given a bool' => [Attr::TYPE_NUMBER, true],
            'text given an int' => [Attr::TYPE_TEXT, 5],
            'date given an array' => [Attr::TYPE_DATE, ['2026-01-01']],
            'time given a number' => [Attr::TYPE_TIME, 1200],
            'datetime given a bool' => [Attr::TYPE_DATETIME, false],
            'object given a scalar' => [Attr::TYPE_OBJECT, 'x'],
        ];
    }

    public function testAnUnknownDatatypeAcceptsAnything(): void
    {
        $this->reconciler()->assertValid($this->entity(['field' => 'mystery']), ['field' => ['x']]);

        $this->addToAssertionCount(1);
    }

    public function testNestedPathsResolveThroughObjects(): void
    {
        $entity = $this->entity(['address.city' => [Attr::TYPE_TEXT, true]]);

        $this->reconciler()->assertValid($entity, ['address' => ['city' => 'Recife']]);
        $this->assertRejected($entity, ['address' => ['city' => 4]], 'Attribute "address.city" must be of type "text".');
        $this->assertRejected($entity, ['address' => ['street' => 'x']], 'Missing required attribute "address.city".');
        $this->assertRejected($entity, ['address' => 'flat'], 'Missing required attribute "address.city".');
    }

    public function testPathsThroughListsOfObjectsAreCheckedPerElement(): void
    {
        $entity = $this->entity(['orders.org' => [Attr::TYPE_TEXT, true]]);

        // Required = present in at least one element; every present value must match the type.
        $this->reconciler()->assertValid($entity, ['orders' => [['org' => null], ['org' => '1000'], ['other' => 1]]]);
        $this->assertRejected($entity, ['orders' => [['org' => '1000'], ['org' => 5]]], 'Attribute "orders.org" must be of type "text".');
        $this->assertRejected($entity, ['orders' => [['org' => null], ['x' => 1]]], 'Missing required attribute "orders.org".');
        $this->assertRejected($entity, ['orders' => []], 'Missing required attribute "orders.org".');
    }

    public function testAnAttributeWithoutANameIsIgnored(): void
    {
        $entity = $this->entity(['' => [Attr::TYPE_TEXT, true]]);

        $this->reconciler()->assertValid($entity, []);

        $this->addToAssertionCount(1);
    }

    // --- syncFromRecords ----------------------------------------------------------------------

    public function testUnknownAttributesAreCreatedAsUndefinedAndFlushedOnce(): void
    {
        $entity = $this->entity(['id' => Attr::TYPE_TEXT]);

        $this->reconciler(1)->syncFromRecords($entity, [
            ['id' => 'a', 'name' => 'x', 'qty' => 2],
            ['id' => 'b', 'name' => 'y', 'extra' => true],
        ]);

        $byName = self::attributesByName($entity);
        self::assertSame(['id', 'name', 'qty', 'extra'], array_keys($byName));
        foreach (['name', 'qty', 'extra'] as $created) {
            self::assertSame(Attr::TYPE_UNDEFINED, $byName[$created]->getDatatype());
            self::assertFalse($byName[$created]->isRequired());
            self::assertSame($entity, $byName[$created]->getEntity());
        }
        self::assertSame(Attr::TYPE_TEXT, $byName['id']->getDatatype(), 'an existing attribute is left alone');
    }

    public function testTheUniqueAttributeIsCreatedRequired(): void
    {
        $entity = $this->entity([], 'sku');

        $this->reconciler(1)->syncFromRecords($entity, [['sku' => 'A', 'other' => 1]]);

        $byName = self::attributesByName($entity);
        self::assertTrue($byName['sku']->isRequired());
        self::assertFalse($byName['other']->isRequired());
    }

    public function testNothingIsFlushedWhenNothingWasCreated(): void
    {
        $entity = $this->entity(['id' => Attr::TYPE_TEXT, 'qty' => Attr::TYPE_NUMBER]);

        $this->reconciler(0)->syncFromRecords($entity, [['id' => 'a', 'qty' => 1], [], ['qty' => 2]]);

        self::assertCount(2, $entity->getAttributes());
    }

    public function testNestedObjectsBecomeDottedPaths(): void
    {
        $entity = $this->entity([]);

        $this->reconciler(1)->syncFromRecords($entity, [['address' => ['city' => 'Recife', 'geo' => ['lat' => 1]], 'tags' => ['a', 'b'], 'empty' => []]]);

        self::assertSame(['address.city', 'address.geo.lat', 'tags', 'empty'], array_keys(self::attributesByName($entity)));
    }

    public function testListsOfObjectsUnionTheirKeysWithoutIndexes(): void
    {
        $entity = $this->entity([]);

        $this->reconciler(1)->syncFromRecords($entity, [['orders' => [['id' => 1, 'org' => 'x'], ['id' => 2, 'note' => 'n'], 'scalar', [1, 2]]]]);

        self::assertSame(['orders.id', 'orders.org', 'orders.note'], array_keys(self::attributesByName($entity)));
    }

    public function testAListWithoutAnyObjectIsALeafAttribute(): void
    {
        $entity = $this->entity([]);

        $this->reconciler(1)->syncFromRecords($entity, [['matrix' => [[1, 2], [3]]]]);

        self::assertSame(['matrix'], array_keys(self::attributesByName($entity)));
    }

    public function testAnObjectDeclaredAttributeCollapsesItsSubtree(): void
    {
        $entity = $this->entity(['meta' => Attr::TYPE_OBJECT, 'orders' => Attr::TYPE_OBJECT, 'address' => Attr::TYPE_UNDEFINED]);

        $this->reconciler(1)->syncFromRecords($entity, [[
            'meta' => ['a' => 1],
            'orders' => [['id' => 1]],
            'address' => ['city' => 'x'],
        ]]);

        // Only the undefined (not object) one is expanded.
        self::assertSame(['meta', 'orders', 'address', 'address.city'], array_keys(self::attributesByName($entity)));
    }

    public function testOverlongPathsAreSkipped(): void
    {
        $long = str_repeat('a', 101);
        $entity = $this->entity([]);

        $this->reconciler(1)->syncFromRecords($entity, [[$long => 1, str_repeat('b', 100) => 2]]);

        self::assertSame([str_repeat('b', 100)], array_keys(self::attributesByName($entity)));
    }

    public function testEmptyKeysAndNonArrayRecordsAreSkipped(): void
    {
        $entity = $this->entity([]);

        $this->reconciler(1)->syncFromRecords($entity, ['scalar', ['' => 1, 'ok' => 2], 7]);

        self::assertSame(['ok'], array_keys(self::attributesByName($entity)));
    }

    public function testIntegerKeysBecomePathSegments(): void
    {
        $entity = $this->entity([]);

        $this->reconciler(1)->syncFromRecords($entity, [[5 => 'five']]);

        self::assertSame(['5'], array_map('strval', array_keys(self::attributesByName($entity))));
    }
}
