<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\EntityPayloadReader;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityReader;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\OrmStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EntityPayloadReaderTest extends TestCase
{
    private OrmStub $orm;
    private BucketEntityDataStore&MockObject $store;

    protected function setUp(): void
    {
        $this->orm = new OrmStub(fn (string $class) => $this->createMock($class));
        $this->store = $this->createMock(BucketEntityDataStore::class);
    }

    private function reader(): EntityPayloadReader
    {
        return new EntityPayloadReader($this->orm->registry, $this->store);
    }

    private static function entity(bool $external = true, bool $forceDb = false): OntologyEntity
    {
        return (new OntologyEntity())
            ->setName('Order')
            ->setSystem((new OntologySystem())->setName('ERP')->setExternal($external))
            ->setForceDbStorage($forceDb);
    }

    public function testItReadsTheTableOldestFirstUpToTheLimit(): void
    {
        $entity = self::entity();
        $this->orm->queryResult = static fn (): array => [['payload' => ['a' => 1]], ['payload' => null], ['payload' => 'junk']];

        self::assertSame([['a' => 1], [], []], $this->reader()->payloads($entity, 2));
        self::assertSame('SELECT d.payload FROM ' . OntologyData::class . ' d WHERE d.entity = :entity ORDER BY d.id ASC', $this->orm->queries[0]['dql']);
        self::assertSame(['entity' => $entity], $this->orm->queries[0]['parameters']);
        self::assertSame(2, $this->orm->queries[0]['maxResults']);

        $this->reader()->payloads($entity, null);
        self::assertNull($this->orm->queries[1]['maxResults']);
    }

    public function testItCountsTheTable(): void
    {
        $entity = self::entity();
        $this->orm->repository(OntologyData::class)->method('count')->with(['entity' => $entity])->willReturn(41);

        self::assertSame(41, $this->reader()->total($entity));
    }

    public function testABucketBackedEntityIsReadAndCountedFromTheBucket(): void
    {
        $entity = self::entity();
        $this->store->method('isEnabled')->willReturn(true);
        $this->store->method('countLatest')->willReturn(3);
        $this->store->method('listLatest')->willReturn([['payload' => ['n' => 1]], ['payload' => ['n' => 2]], ['payload' => 'junk']]);

        self::assertTrue($this->reader()->bucketBacked($entity));
        self::assertSame(3, $this->reader()->total($entity));
        self::assertSame([['n' => 1], ['n' => 2], []], $this->reader()->payloads($entity, null));
        self::assertSame([['n' => 1]], $this->reader()->payloads($entity, 1));
        self::assertSame([], $this->orm->queries);
    }

    public function testAnInternalEntityIsReadAndCountedFromItsOroTableLikeAFlowEntityRead(): void
    {
        $entity = self::entity(false)->setName('Oro\\Bundle\\ProductBundle\\Entity\\Product');
        $oro = $this->createMock(OroEntityReader::class);
        $oro->expects(self::once())->method('readAll')->with($entity, null, 'ASC', 2)->willReturn([['id' => 1], ['id' => 2]]);
        $this->orm->queryResult = static fn (string $dql, array $parameters, ?int $max, string $terminal): int|array
            => $terminal === 'getSingleScalarResult' ? 42 : [];
        $this->store->method('isEnabled')->willReturn(true); // never consulted for an internal entity

        $reader = new EntityPayloadReader($this->orm->registry, $this->store, $oro);

        self::assertSame([['id' => 1], ['id' => 2]], $reader->payloads($entity, 2));
        self::assertSame(42, $reader->total($entity));
        self::assertSame('SELECT COUNT(e) FROM Oro\\Bundle\\ProductBundle\\Entity\\Product e', $this->orm->queries[0]['dql']);
    }

    public function testAnInternalEntityThatIsNoOroClassCountsZeroAndCannotBeRead(): void
    {
        $entity = self::entity(false)->setName('NotAClass');
        $this->orm->managesClasses = false;

        self::assertSame(0, $this->reader()->total($entity));
        $this->expectException(OntologyApiException::class);
        $this->reader()->payloads($entity, null);
    }

    public function testACountThatFailsIsZero(): void
    {
        $this->orm->managerFailure = new \RuntimeException('boom');

        self::assertSame(0, $this->reader()->total(self::entity(false)));
    }

    public function testInternalAndForcedDbEntitiesStayOnTheTable(): void
    {
        $this->store->method('isEnabled')->willReturn(true);

        self::assertFalse($this->reader()->bucketBacked(self::entity(false)));
        self::assertFalse($this->reader()->bucketBacked(self::entity(true, true)));
        self::assertFalse($this->reader()->bucketBacked(new OntologyEntity()));
    }
}
