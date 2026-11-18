<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\EntityRecordEraser;
use Aaxis\Bundle\OntologyBundle\Manager\RecordFilter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The filtered DELETE pair was run against PostgreSQL inside a rolled-back transaction: the
 * matching records and exactly their history went, every other record, history row and entity
 * stayed. These tests pin which store answers and what is sent.
 */
class EntityRecordEraserTest extends TestCase
{
    private const string PRED = "(d.payload #>> string_to_array(:filterPath, '.')) = :filterValue";

    private Connection&MockObject $connection;
    private BucketEntityDataStore&MockObject $bucket;
    private EntityRecordEraser $eraser;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('transactional')->willReturnCallback(fn (\Closure $work) => $work($this->connection));
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($this->connection);
        $this->bucket = $this->createMock(BucketEntityDataStore::class);
        $this->eraser = new EntityRecordEraser($doctrine, $this->bucket);
    }

    private static function entity(bool $external = true, bool $forceDb = false): OntologyEntity
    {
        $entity = (new OntologyEntity())->setName('Order')->setForceDbStorage($forceDb)
            ->setSystem((new OntologySystem())->setName('ERP')->setExternal($external));
        (new \ReflectionProperty(OntologyEntity::class, 'id'))->setValue($entity, 7);

        return $entity;
    }

    /**
     * @return array{attribute: string, path: list<string>, operator: string, value: string}
     */
    private static function filter(string $value = 'x'): array
    {
        $filter = RecordFilter::parse(['mode' => 'filter', 'attribute' => 'status', 'operator' => 'eq', 'value' => $value]);
        self::assertNotNull($filter);

        return $filter;
    }

    private function bucketOn(): void
    {
        $this->bucket->method('isEnabled')->willReturn(true);
        $this->bucket->method('listLatest')->willReturn([
            ['uniqueId' => 'b', 'version' => 2, 'updatedAt' => '2026-01-02T00:00:00+00:00', 'payload' => ['status' => 'x']],
            ['uniqueId' => 'a', 'version' => 1, 'updatedAt' => '2026-01-01T00:00:00+00:00', 'payload' => ['status' => 'x']],
            ['uniqueId' => 'c', 'version' => 1, 'payload' => ['status' => 'y']],
            ['uniqueId' => 'd', 'version' => 1, 'payload' => null],
        ]);
    }

    // --- table --------------------------------------------------------------------------------

    public function testCountingAllTableRecords(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with('SELECT COUNT(*) FROM aaxis_ontology_data d WHERE d.entity_id = :entity', ['entity' => 7], ['entity' => ParameterType::INTEGER])
            ->willReturn('12');

        self::assertSame(12, $this->eraser->count(self::entity(), null));
    }

    public function testCountingAFilteredSelection(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with(
                'SELECT COUNT(*) FROM aaxis_ontology_data d WHERE d.entity_id = :entity AND ' . self::PRED,
                ['entity' => 7, 'filterPath' => 'status', 'filterValue' => 'x']
            )
            ->willReturn(3);

        self::assertSame(3, $this->eraser->count(self::entity(), self::filter()));
    }

    public function testPreviewingAPageOrderedByUniqueId(): void
    {
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringStartsWith('SELECT d.unique_id, d.version, d.updated_at, d.payload FROM aaxis_ontology_data d WHERE d.entity_id = :entity AND '),
                    self::stringEndsWith('ORDER BY d.unique_id ASC, d.id ASC LIMIT 50 OFFSET 100')
                ),
                ['entity' => 7, 'filterPath' => 'status', 'filterValue' => 'x']
            )
            ->willReturn([
                ['unique_id' => 'A-1', 'version' => '3', 'updated_at' => '2026-03-04 05:06:07', 'payload' => '{"status":"x"}'],
                ['unique_id' => 'A-2', 'version' => 1, 'updated_at' => null, 'payload' => null],
            ]);
        $this->connection->method('fetchOne')->willReturn(102);

        self::assertSame([
            'total' => 102,
            'records' => [
                ['uniqueId' => 'A-1', 'version' => 3, 'updatedAt' => '2026-03-04T05:06:07+00:00', 'payload' => ['status' => 'x']],
                ['uniqueId' => 'A-2', 'version' => 1, 'updatedAt' => null, 'payload' => null],
            ],
        ], $this->eraser->preview(self::entity(), self::filter(), 3, 500));
    }

    public function testErasingAFilteredSelectionDropsItsHistoryFirst(): void
    {
        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$statements): int {
            $statements[] = [$sql, $params];

            return \count($statements) === 1 ? 9 : 4;
        });

        self::assertSame(4, $this->eraser->erase(self::entity(), self::filter()));
        $params = ['entity' => 7, 'filterPath' => 'status', 'filterValue' => 'x'];
        self::assertSame([
            ['DELETE FROM aaxis_ontology_data_history h USING aaxis_ontology_data d WHERE h.entity_id = :entity AND h.unique_id = d.unique_id AND d.entity_id = :entity AND ' . self::PRED, $params],
            ['DELETE FROM aaxis_ontology_data d WHERE d.entity_id = :entity AND ' . self::PRED, $params],
        ], $statements);
    }

    public function testErasingAllClearsTheTableAndAnEnabledBucketEvenForAForcedDbEntity(): void
    {
        $entity = self::entity(true, true);
        $this->bucket->method('isEnabled')->willReturn(true);
        $this->bucket->expects(self::once())->method('purgeEntity')->with($entity)->willReturn(5);
        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 2;
        });

        self::assertSame(7, $this->eraser->erase($entity, null));
        self::assertSame([
            'DELETE FROM aaxis_ontology_data_history WHERE entity_id = :entity',
            'DELETE FROM aaxis_ontology_data WHERE entity_id = :entity',
        ], $statements);
    }

    public function testErasingAllWithoutABucketOnlyClearsTheTable(): void
    {
        $this->bucket->method('isEnabled')->willReturn(false);
        $this->bucket->expects(self::never())->method('purgeEntity');
        $this->connection->method('executeStatement')->willReturn(3);

        self::assertSame(3, $this->eraser->erase(self::entity(), null));
    }

    public function testAForcedDbEntityIsFilteredOnTheTableEvenWithTheBucketOn(): void
    {
        $this->bucket->method('isEnabled')->willReturn(true);
        $this->bucket->expects(self::never())->method('listLatest');
        $this->connection->expects(self::once())->method('fetchOne')->willReturn(1);

        self::assertSame(1, $this->eraser->count(self::entity(true, true), self::filter()));
    }

    // --- bucket -------------------------------------------------------------------------------

    public function testCountingOnTheBucket(): void
    {
        $this->bucketOn();
        $this->bucket->method('countLatest')->willReturn(4);
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertSame([4, 2], [$this->eraser->count(self::entity(), null), $this->eraser->count(self::entity(), self::filter())]);
    }

    public function testPreviewingOnTheBucketSortsAndPages(): void
    {
        $this->bucketOn();

        self::assertSame([
            'total' => 2,
            'records' => [['uniqueId' => 'b', 'version' => 2, 'updatedAt' => '2026-01-02T00:00:00+00:00', 'payload' => ['status' => 'x']]],
        ], $this->eraser->preview(self::entity(), self::filter(), 2, 1));
        self::assertSame(['a', 'b', 'c', 'd'], array_column($this->eraser->preview(self::entity(), null, 1, 10)['records'], 'uniqueId'));
        self::assertNull($this->eraser->preview(self::entity(), null, 1, 10)['records'][2]['updatedAt']);
    }

    public function testErasingAFilteredSelectionOnTheBucket(): void
    {
        $this->bucketOn();
        $this->bucket->expects(self::once())->method('eraseRecords')->with(self::entity(), ['b', 'a'])->willReturn(2);
        $this->connection->expects(self::never())->method('executeStatement');

        self::assertSame(2, $this->eraser->erase(self::entity(), self::filter()));
    }

    // --- internal -----------------------------------------------------------------------------

    /**
     * @dataProvider operations
     */
    public function testAnInternalEntityIsRefused(string $operation): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The records of an OroCommerce entity live in its own table and are not erased here.');
        $this->connection->expects(self::never())->method(self::anything());

        match ($operation) {
            'count' => $this->eraser->count(self::entity(false), null),
            'preview' => $this->eraser->preview(self::entity(false), null, 1, 10),
            default => $this->eraser->erase(self::entity(false), null),
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operations(): array
    {
        return ['count' => ['count'], 'preview' => ['preview'], 'erase' => ['erase']];
    }
}
