<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityReader;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalCategory;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalEntityWorld;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InternalProduct;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\ProductStatus;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

class OroEntityReaderTest extends TestCase
{
    private const string ALL_SELECTS = 'e.id AS id, e.sku AS sku, e.name AS name, e.price AS price, e.qty AS qty, e.active AS active, '
        . 'e.weight AS weight, e.createdAt AS createdAt, e.releaseDate AS releaseDate, e.openingTime AS openingTime, '
        . 'e.tags AS tags, e.meta AS meta';

    private InternalEntityWorld $world;
    private OroEntityReader $reader;

    protected function setUp(): void
    {
        $this->world = new InternalEntityWorld(fn (string $class) => $this->createMock($class));
        $this->reader = new OroEntityReader($this->world->registry);
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

    private function assertUnreadable(callable $call, string $reason): void
    {
        try {
            $call();
            self::fail('an OntologyApiException was expected');
        } catch (OntologyApiException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('internal_entity_unreadable', $e->getErrorCode());
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    // --- readById -----------------------------------------------------------------------------

    public function testReadByIdSelectsEveryScalarColumnWhenNoAttributesAreConfigured(): void
    {
        $this->world->results = [[['id' => 7, 'sku' => 'A-1']]];

        $row = $this->reader->readById($this->entity(), 'A-1');

        self::assertSame(['id' => 7, 'sku' => 'A-1'], $row);
        self::assertSame(
            'SELECT ' . self::ALL_SELECTS . ' FROM ' . InternalProduct::class . ' e WHERE e.sku = :uid ORDER BY e.id ASC',
            $this->world->lastDql()
        );
        self::assertSame(['uid' => 'A-1'], $this->world->queries[0]['parameters']);
        self::assertSame(1, $this->world->queries[0]['maxResults']);
    }

    public function testReadByIdReturnsNullWhenNothingMatches(): void
    {
        $this->world->results = [[]];

        self::assertNull($this->reader->readById($this->entity(), 'nope'));
    }

    public function testConfiguredAttributesDriveTheSelectList(): void
    {
        $this->world->results = [[]];
        // 'variants' is a to-many association and 'ghost' does not exist: both are dropped; a to-one
        // association becomes IDENTITY(); blank and duplicate names are ignored.
        $entity = $this->entity('sku', ['sku', ' name ', 'category', 'variants', 'ghost', '', 'name']);

        $this->reader->readById($entity, 'A');

        self::assertStringStartsWith(
            'SELECT e.sku AS sku, e.name AS name, IDENTITY(e.category) AS category FROM ',
            $this->world->lastDql()
        );
    }

    public function testANonNumericIdAgainstAnIntegerColumnIsNotFoundWithoutQuerying(): void
    {
        self::assertNull($this->reader->readById($this->entity('id'), 'abc'));
        self::assertSame([], $this->world->queries);

        $this->world->results = [[['id' => 12]]];
        self::assertSame(['id' => 12], $this->reader->readById($this->entity('id'), '12'));
    }

    public function testTheUniqueAttributeMayBeAToOneAssociationComparedByTheRelatedId(): void
    {
        self::assertNull($this->reader->readById($this->entity('category'), 'abc'), 'the related id is an integer');
        self::assertSame([], $this->world->queries);

        $this->world->results = [[['category' => 3]]];
        $row = $this->reader->readById($this->entity('category'), '3');

        self::assertSame(['category' => 3], $row);
        self::assertStringContainsString('WHERE e.category = :uid', $this->world->lastDql());
    }

    public function testAStringKeyedAssociationAcceptsAnyValue(): void
    {
        $this->world->results = [[['owner' => 'ACME']]];

        self::assertSame(['owner' => 'ACME'], $this->reader->readById($this->entity('owner'), 'not-a-number'));
    }

    public function testAUniqueAttributeThatIsNotAFieldIsUnreadable(): void
    {
        $this->assertUnreadable(
            fn () => $this->reader->readById($this->entity('variants'), 'x'),
            'unique attribute "variants" is not one of its fields'
        );
    }

    /**
     * @dataProvider unmanagedEntityProvider
     */
    public function testAnEntityThatIsNotAManagedClassIsUnreadable(?string $class): void
    {
        $this->assertUnreadable(
            fn () => $this->reader->readById($this->entity('sku', [], $class), 'x'),
            'its name is not a managed OroCommerce entity class'
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unmanagedEntityProvider(): array
    {
        return ['unknown class' => ['App\\Nope'], 'blank name' => ['  '], 'no name' => [null]];
    }

    public function testAClassWhoseMetadataCannotBeLoadedIsUnreadable(): void
    {
        $this->world->metadataFails = true;

        $this->assertUnreadable(
            fn () => $this->reader->searchableFields($this->entity()),
            'its name is not a managed OroCommerce entity class'
        );
    }

    public function testAnEntityWhoseAttributesAreAllStaleIsUnreadable(): void
    {
        $this->assertUnreadable(
            fn () => $this->reader->readById($this->entity('sku', ['ghost', 'variants']), 'x'),
            'none of its attributes is a readable field'
        );
    }

    // --- normalization ------------------------------------------------------------------------

    public function testRowsAreMadeJsonSafe(): void
    {
        $when = new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('UTC'));
        $this->world->results = [[[
            'id' => 1,
            'createdAt' => $when,
            'releaseDate' => $when,
            'openingTime' => $when,
            'aliasDate' => $when,
            'status' => ProductStatus::Active,
            'label' => new class () implements \Stringable {
                public function __toString(): string
                {
                    return 'shown';
                }
            },
            'blob' => new \stdClass(),
            'tags' => ['a', 'b'],
            'price' => '1.50',
            'nothing' => null,
        ]]];

        $row = $this->reader->readById($this->entity(), 'x');

        self::assertSame([
            'id' => 1,
            'createdAt' => '2026-03-04T05:06:07+00:00',
            'releaseDate' => '2026-03-04',
            'openingTime' => '05:06:07',
            'aliasDate' => '2026-03-04T05:06:07+00:00',
            'status' => 'active',
            'label' => 'shown',
            'blob' => null,
            'tags' => ['a', 'b'],
            'price' => '1.50',
            'nothing' => null,
        ], $row);
    }

    // --- readByAttribute ----------------------------------------------------------------------

    public function testReadByAttributeReturnsEveryMatchingRecordOrderedByIdentifier(): void
    {
        $this->world->results = [[['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'x']]];

        $rows = $this->reader->readByAttribute($this->entity(), ' name ', 'x');

        self::assertSame([['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'x']], $rows);
        self::assertSame(
            'SELECT ' . self::ALL_SELECTS . ' FROM ' . InternalProduct::class . ' e WHERE e.name = :val ORDER BY e.id ASC',
            $this->world->lastDql()
        );
        self::assertSame(['val' => 'x'], $this->world->queries[0]['parameters']);
    }

    public function testReadByAttributeRejectsAnAttributeThatIsNotAReadableField(): void
    {
        $this->assertUnreadable(
            fn () => $this->reader->readByAttribute($this->entity('sku', ['sku']), 'name', 'x'),
            '"name" is not one of its readable attributes'
        );
        $this->assertUnreadable(
            fn () => $this->reader->readByAttribute($this->entity(), 'name; DROP TABLE x', 'x'),
            'is not one of its readable attributes'
        );
        self::assertSame([], $this->world->queries, 'user input must never reach the DQL');
    }

    public function testAValueTheColumnCannotHoldMeansNoMatch(): void
    {
        $this->world->results = [$this->driverError('22P02')];

        self::assertSame([], $this->reader->readByAttribute($this->entity(), 'qty', 'letters'));
    }

    public function testOtherDatabaseErrorsPropagate(): void
    {
        $error = $this->driverError('42703');
        $this->world->results = [$error];

        $this->expectExceptionObject($error);
        $this->reader->readByAttribute($this->entity(), 'qty', '1');
    }

    private function driverError(string $sqlState): DriverException
    {
        return new DriverException(new class ($sqlState) extends \Exception implements DriverExceptionInterface {
            public function __construct(private readonly string $state)
            {
                parent::__construct('SQLSTATE ' . $state);
            }

            public function getSQLState(): string
            {
                return $this->state;
            }
        }, null);
    }

    // --- searchableFields + readAll -----------------------------------------------------------

    public function testSearchableFieldsAreTheReadablePayloadFields(): void
    {
        self::assertSame(
            ['id', 'sku', 'name', 'price', 'qty', 'active', 'weight', 'createdAt', 'releaseDate', 'openingTime', 'tags', 'meta'],
            $this->reader->searchableFields($this->entity())
        );
        self::assertSame(['sku', 'category'], $this->reader->searchableFields($this->entity('sku', ['sku', 'category', 'variants', 'ghost'])));
    }

    public function testReadAllOrdersByAFieldWithAnIdentifierTiebreak(): void
    {
        $this->world->results = [[['id' => 2, 'sku' => 'b'], ['id' => 1, 'sku' => 'a']]];

        $rows = $this->reader->readAll($this->entity(), ' sku ', 'desc', 50);

        self::assertSame([['id' => 2, 'sku' => 'b'], ['id' => 1, 'sku' => 'a']], $rows);
        self::assertSame(
            'SELECT ' . self::ALL_SELECTS . ' FROM ' . InternalProduct::class . ' e ORDER BY e.sku DESC, e.id ASC',
            $this->world->lastDql()
        );
        self::assertSame(50, $this->world->queries[0]['maxResults']);
    }

    /**
     * @dataProvider readAllOrderProvider
     */
    public function testReadAllOrdering(?string $orderBy, string $direction, ?int $limit, string $expectedOrder, ?int $expectedLimit): void
    {
        $this->reader->readAll($this->entity(), $orderBy, $direction, $limit);

        self::assertStringEndsWith(' e ORDER BY ' . $expectedOrder, $this->world->lastDql());
        self::assertSame($expectedLimit, $this->world->queries[0]['maxResults']);
    }

    /**
     * @return array<string, array{string|null, string, int|null, string, int|null}>
     */
    public static function readAllOrderProvider(): array
    {
        return [
            'default' => [null, 'ASC', null, 'e.id ASC', null],
            'ascending field' => ['name', 'ASC', 10, 'e.name ASC, e.id ASC', 10],
            'unknown direction falls back to ASC' => ['name', 'sideways', null, 'e.name ASC, e.id ASC', null],
            'not a field is ignored' => ['ghost', 'DESC', 0, 'e.id ASC', null],
            'an association is not an order field' => ['category', 'DESC', -3, 'e.id ASC', null],
            'injection attempt is ignored' => ['name DESC; DROP', 'DESC', null, 'e.id ASC', null],
        ];
    }
}
