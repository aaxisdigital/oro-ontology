<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityReader;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityWriter;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalCategory;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalEntityWorld;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalOwner;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalProduct;
use PHPUnit\Framework\TestCase;

class OroEntityWriterTest extends TestCase
{
    private InternalEntityWorld $world;
    private OroEntityWriter $writer;

    protected function setUp(): void
    {
        $this->world = new InternalEntityWorld(fn (string $class) => $this->createMock($class));
        $this->writer = new OroEntityWriter($this->world->registry, new OroEntityReader($this->world->registry));
    }

    /**
     * @param array<int, string> $attributes
     */
    private function entity(string $uniqueAttribute = 'sku', array $attributes = [], ?string $class = InternalProduct::class): OntologyEntity
    {
        $entity = (new OntologyEntity())->setName($class)->setUniqueAttribute($uniqueAttribute);
        foreach ($attributes as $name) {
            $entity->addAttribute((new OntologyEntityAttribute())->setName($name));
        }

        return $entity;
    }

    /**
     * Associations only take part in a payload when they are configured as attributes (with none
     * configured the payload is the scalar columns).
     */
    private function withAssociations(): OntologyEntity
    {
        return $this->entity('sku', ['sku', 'category', 'owner']);
    }

    private static function product(string $sku, int $id = 1): InternalProduct
    {
        $product = new InternalProduct();
        $product->id = $id;
        $product->sku = $sku;

        return $product;
    }

    private function assertRejected(callable $call, string $code, int $status, string $message): void
    {
        try {
            $call();
            self::fail('an OntologyApiException was expected');
        } catch (OntologyApiException $e) {
            self::assertSame($code, $e->getErrorCode());
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($message, $e->getMessage());
        }
    }

    // --- update: the happy paths --------------------------------------------------------------

    public function testItWritesThePresentKeysAndReportsOnlyTheRowsThatChanged(): void
    {
        $a = self::product('A', 1);
        $a->name = 'old';
        $b = self::product('B', 2);
        $b->name = 'same';
        $this->world->found = [$a, $b];

        $changed = $this->writer->update($this->entity(), ['A', 'B'], [['name' => 'new', 'qty' => 4], ['name' => 'same']]);

        self::assertSame(['A'], $changed);
        self::assertSame('new', $a->name);
        self::assertSame(4, $a->qty);
        self::assertSame('same', $b->name);
        self::assertSame(1, $this->world->flushes, 'one flush per batch');
        self::assertSame([['sku' => ['A', 'B']]], $this->world->findByCalls);
    }

    public function testAbsentKeysAreLeftAloneAndNullClears(): void
    {
        $row = self::product('A');
        $row->name = 'keep';
        $row->qty = 9;
        $row->weight = 2.5;
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity(), ['A'], [['qty' => null]]);

        self::assertSame(['A'], $changed);
        self::assertNull($row->qty);
        self::assertSame('keep', $row->name);
        self::assertSame(2.5, $row->weight);
    }

    public function testIdentifierAndUniqueColumnsAreNeverWritten(): void
    {
        $row = self::product('A', 5);
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity(), ['A'], [['id' => 99, 'sku' => 'HACKED', 'ghost' => 1]]);

        self::assertSame([], $changed);
        self::assertSame(5, $row->id);
        self::assertSame('A', $row->sku);
    }

    public function testOnlyConfiguredAttributesAreWritable(): void
    {
        $row = self::product('A');
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity('sku', ['sku', 'name']), ['A'], [['name' => 'n', 'qty' => 7]]);

        self::assertSame(['A'], $changed);
        self::assertSame('n', $row->name);
        self::assertNull($row->qty, 'qty is not one of the configured attributes');
    }

    // --- coercion + change detection ----------------------------------------------------------

    public function testValuesAreCoercedToTheColumnType(): void
    {
        $row = self::product('A');
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity(), ['A'], [[
            'qty' => '12',
            'weight' => '1.25',
            'active' => 'true',
            'price' => '19.90',
            'createdAt' => '2026-03-04T05:06:07+00:00',
            'releaseDate' => '2026-03-04',
            'openingTime' => '08:30:00',
            'tags' => [1, 2],
            'meta' => ['k' => ['v']],
        ]]);

        self::assertSame(['A'], $changed);
        self::assertSame(12, $row->qty);
        self::assertSame(1.25, $row->weight);
        self::assertTrue($row->active);
        self::assertSame('19.90', $row->price);
        self::assertInstanceOf(\DateTime::class, $row->createdAt);
        self::assertSame('2026-03-04T05:06:07+00:00', $row->createdAt->format(\DATE_ATOM));
        self::assertInstanceOf(\DateTimeImmutable::class, $row->releaseDate);
        self::assertSame('2026-03-04', $row->releaseDate->format('Y-m-d'));
        self::assertSame('08:30:00', $row->openingTime->format('H:i:s'));
        self::assertSame(['1', '2'], $row->tags);
        self::assertSame(['k' => ['v']], $row->meta);
    }

    public function testBooleansAndScalarListsAreCoercedLeniently(): void
    {
        $row = self::product('A');
        $this->world->found = [$row];

        $this->writer->update($this->entity(), ['A'], [['active' => false, 'tags' => 'solo']]);
        self::assertFalse($row->active);
        self::assertSame(['solo'], $row->tags);

        $this->writer->update($this->entity(), ['A'], [['active' => 'no']]);
        self::assertFalse($row->active);

        $this->writer->update($this->entity(), ['A'], [['active' => '1']]);
        self::assertTrue($row->active);
    }

    public function testAnEmptyStringClearsADateColumn(): void
    {
        $row = self::product('A');
        $row->createdAt = new \DateTime('2020-01-01');
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity(), ['A'], [['createdAt' => '']]);

        self::assertSame(['A'], $changed);
        self::assertNull($row->createdAt);
    }

    public function testEqualValuesDoNotCountAsChanges(): void
    {
        $row = self::product('A');
        $row->price = '1.50';
        $row->qty = 3;
        $row->createdAt = new \DateTime('2026-03-04T05:06:07+00:00');
        $row->name = null;
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity(), ['A'], [[
            'price' => '1.5',                                 // loosely equal decimals
            'qty' => '3',                                     // coerced, then equal
            'createdAt' => '2026-03-04T05:06:07+00:00',        // dates compare by value
            'name' => null,                                   // null onto null
        ]]);

        self::assertSame([], $changed);
        self::assertSame('1.50', $row->price, 'an unchanged value must not be rewritten');
    }

    public function testANullOnlyEqualsNull(): void
    {
        $row = self::product('A');
        $row->qty = 0;
        $row->name = '';
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity(), ['A'], [['qty' => null, 'name' => null]]);

        self::assertSame(['A'], $changed);
        self::assertNull($row->qty);
        self::assertNull($row->name);
    }

    public function testANonStringDateIsRejected(): void
    {
        $this->world->found = [self::product('A')];

        $this->assertRejected(
            fn () => $this->writer->update($this->entity(), ['A'], [['createdAt' => 20260304]]),
            'invalid_payload',
            400,
            'Record "A": "createdAt" must be a date/time string.'
        );
        self::assertSame(0, $this->world->flushes);
    }

    public function testAnUnparseableDateIsRejected(): void
    {
        $this->world->found = [self::product('A')];

        $this->assertRejected(
            fn () => $this->writer->update($this->entity(), ['A'], [['releaseDate' => 'not a date']]),
            'invalid_payload',
            400,
            'Record "A": "not a date" is not a valid date/time for "releaseDate".'
        );
    }

    // --- associations -------------------------------------------------------------------------

    public function testAToOneAssociationTakesTheRelatedIdAsAReference(): void
    {
        $row = self::product('A');
        $this->world->found = [$row];

        $changed = $this->writer->update($this->withAssociations(), ['A'], [['category' => '42', 'owner' => 'ACME']]);

        self::assertSame(['A'], $changed);
        self::assertEquals(new InternalCategory(42), $row->category);
        self::assertEquals(new InternalOwner('ACME'), $row->owner);
        // A numeric id for an integer key is cast; a string key stays a string.
        self::assertSame([[InternalCategory::class, 42], [InternalOwner::class, 'ACME']], $this->world->references);
    }

    public function testAnUnchangedAssociationIsNotTouched(): void
    {
        $row = self::product('A');
        $row->category = new InternalCategory(42);
        $row->owner = new InternalOwner('ACME');
        $this->world->found = [$row];

        $changed = $this->writer->update($this->withAssociations(), ['A'], [['category' => 42, 'owner' => 'ACME']]);

        self::assertSame([], $changed);
        self::assertSame([], $this->world->references);
    }

    public function testAnAssociationCanBeClearedWithNullOrAnEmptyString(): void
    {
        $row = self::product('A');
        $row->category = new InternalCategory(1);
        $row->owner = new InternalOwner('ACME');
        $this->world->found = [$row];

        $changed = $this->writer->update($this->withAssociations(), ['A'], [['category' => null, 'owner' => '']]);

        self::assertSame(['A'], $changed);
        self::assertNull($row->category);
        self::assertNull($row->owner);
        self::assertSame([], $this->world->references);
    }

    public function testClearingAnAlreadyEmptyAssociationChangesNothing(): void
    {
        $this->world->found = [self::product('A')];

        self::assertSame([], $this->writer->update($this->withAssociations(), ['A'], [['category' => null]]));
    }

    public function testANonNumericIdForAnIntegerKeyedAssociationIsRejected(): void
    {
        $this->world->found = [self::product('A')];

        $this->assertRejected(
            fn () => $this->writer->update($this->withAssociations(), ['A'], [['category' => 'abc']]),
            'invalid_payload',
            400,
            'Record "A": "category" must be the numeric id of the related record.'
        );
        self::assertSame(0, $this->world->flushes);
    }

    // --- addressing the rows ------------------------------------------------------------------

    public function testAnUnknownUniqueIdRejectsTheWholeBatchBeforeAnythingIsWritten(): void
    {
        $a = self::product('A');
        $this->world->found = [$a];

        $this->assertRejected(
            fn () => $this->writer->update($this->entity(), ['A', 'X', 'Y'], [['name' => 'n'], ['name' => 'n'], ['name' => 'n']]),
            'invalid_payload',
            400,
            'No OroCommerce record found for unique id(s) "X", "Y" — internal-system writes UPDATE existing records only.'
        );
        self::assertNull($a->name);
        self::assertSame(0, $this->world->flushes);
    }

    public function testAnAmbiguousUniqueValueRejectsTheBatch(): void
    {
        $this->world->found = [self::product('A', 1), self::product('A', 2)];

        $this->assertRejected(
            fn () => $this->writer->update($this->entity(), ['A'], [['name' => 'n']]),
            'invalid_payload',
            400,
            sprintf('Several "%s" records share the unique attribute value "A" — the write is ambiguous.', InternalProduct::class)
        );
    }

    public function testNonNumericIdsAgainstAnIntegerColumnAreNeverLookedUp(): void
    {
        $row = self::product('A', 7);
        $this->world->found = [$row];

        $this->assertRejected(
            fn () => $this->writer->update($this->entity('id'), ['7', 'seven'], [['name' => 'n'], ['name' => 'n']]),
            'invalid_payload',
            400,
            'No OroCommerce record found for unique id(s) "seven" — internal-system writes UPDATE existing records only.'
        );
        self::assertSame([['id' => ['7']]], $this->world->findByCalls);
    }

    public function testABatchOfOnlyUnaddressableIdsQueriesNothing(): void
    {
        $this->assertRejected(
            fn () => $this->writer->update($this->entity('id'), ['seven'], [['name' => 'n']]),
            'invalid_payload',
            400,
            'No OroCommerce record found for unique id(s) "seven" — internal-system writes UPDATE existing records only.'
        );
        self::assertSame([], $this->world->findByCalls);
    }

    public function testTheUniqueAttributeMayBeAToOneAssociation(): void
    {
        $row = self::product('A');
        $row->category = new InternalCategory(3);
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity('category', ['category', 'name']), ['3'], [['name' => 'n']]);

        self::assertSame(['3'], $changed);
        self::assertSame('n', $row->name);
        self::assertSame([['category' => ['3']]], $this->world->findByCalls);
    }

    public function testAnAssociationUniqueAttributeIsNotWritable(): void
    {
        $row = self::product('A');
        $row->category = new InternalCategory(3);
        $this->world->found = [$row];

        $changed = $this->writer->update($this->entity('category', ['category']), ['3'], [['category' => 99]]);

        self::assertSame([], $changed);
        self::assertSame(3, $row->category->id);
    }

    public function testAnAssociationUniqueAttributeWithANonNumericIdFindsNothing(): void
    {
        $this->assertRejected(
            fn () => $this->writer->update($this->entity('category'), ['abc'], [['name' => 'n']]),
            'invalid_payload',
            400,
            'No OroCommerce record found for unique id(s) "abc" — internal-system writes UPDATE existing records only.'
        );
    }

    public function testAUniqueAttributeThatIsNotAFieldIsUnwritable(): void
    {
        $this->assertRejected(
            fn () => $this->writer->update($this->entity('variants'), ['x'], [[]]),
            'internal_entity_unwritable',
            422,
            sprintf('Entity "%s" cannot be written to OroCommerce: unique attribute "variants" is not one of its fields.', InternalProduct::class)
        );
    }

    /**
     * @dataProvider unmanagedEntityProvider
     */
    public function testAnEntityThatIsNotAManagedClassIsUnwritable(?string $class): void
    {
        $this->assertRejected(
            fn () => $this->writer->update($this->entity('sku', [], $class), ['x'], [[]]),
            'internal_entity_unwritable',
            422,
            sprintf('Entity "%s" cannot be written to OroCommerce: its name is not a managed OroCommerce entity class.', (string) $class)
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unmanagedEntityProvider(): array
    {
        return ['unknown class' => ['App\\Nope'], 'no name' => [null]];
    }

    public function testAClassWhoseMetadataCannotBeLoadedIsUnwritable(): void
    {
        $this->world->metadataFails = true;

        $this->assertRejected(
            fn () => $this->writer->update($this->entity(), ['x'], [[]]),
            'internal_entity_unwritable',
            422,
            sprintf('Entity "%s" cannot be written to OroCommerce: its name is not a managed OroCommerce entity class.', InternalProduct::class)
        );
    }

    // --- flushing -----------------------------------------------------------------------------

    public function testAFlushFailureBecomesAnInvalidPayloadError(): void
    {
        $this->world->found = [self::product('A')];
        $this->world->flushFailure = new \RuntimeException('value too long for type character varying(10)');

        $this->assertRejected(
            fn () => $this->writer->update($this->entity(), ['A'], [['name' => 'n']]),
            'invalid_payload',
            400,
            sprintf('Writing to "%s" failed: value too long for type character varying(10)', InternalProduct::class)
        );
    }
}
