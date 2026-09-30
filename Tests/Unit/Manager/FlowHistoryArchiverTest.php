<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\FlowHistoryArchiver;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowHistoryArchiverTest extends TestCase
{
    private Connection&MockObject $connection;
    private FlowHistoryArchiver $archiver;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($this->connection);
        $this->archiver = new FlowHistoryArchiver($doctrine);
    }

    public function testAnUnchangedDefinitionIsNeverArchived(): void
    {
        $this->connection->expects(self::never())->method('fetchOne');

        $this->archiver->archiveIfExecuted(1, 'F', [['a' => 1]], ['v' => 2], [['a' => 1]], ['v' => 2]);
    }

    /**
     * @dataProvider neverRan
     */
    public function testAFlowThatNeverRanArchivesNothing(mixed $lastExecuted): void
    {
        $this->connection->method('fetchOne')->willReturn($lastExecuted);
        $this->connection->expects(self::never())->method('fetchAssociative');
        $this->connection->expects(self::never())->method('executeStatement');

        $this->archiver->archiveIfExecuted(1, 'F', null, null, [['a' => 1]], null);
    }

    public static function neverRan(): array
    {
        return ['no row' => [false], 'null column' => [null]];
    }

    public function testNoRunSinceTheLatestArchiveMeansNothingToArchive(): void
    {
        $this->connection->method('fetchOne')->willReturn('2026-01-01 10:00:00');
        $this->connection->method('fetchAssociative')->willReturn(['version' => 3, 'last_executed' => '2026-01-01 10:00:00']);
        $this->connection->expects(self::never())->method('executeStatement');

        $this->archiver->archiveIfExecuted(1, 'F', [], null, [['a' => 1]], null);
    }

    public function testArchivesTheReplacedRevisionAsTheNextVersion(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with('SELECT last_executed FROM aaxis_ontology_flow WHERE id = ?', [7])
            ->willReturn('2026-02-01 09:00:00');
        $this->connection->method('fetchAssociative')->willReturn(['version' => '3', 'last_executed' => '2026-01-01 10:00:00']);
        $this->connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('INSERT INTO aaxis_ontology_flow_history'),
            self::callback(static fn (array $p): bool => $p[0] === 7 && $p[1] === 4 && $p[2] === 'Orders'
                && $p[3] === '[{"type":"cron"}]' && $p[4] === '{"version":2}' && $p[5] === '2026-02-01 09:00:00'
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $p[6]) === 1)
        );

        $this->archiver->archiveIfExecuted(7, 'Orders', [['type' => 'cron']], ['version' => 2], [], null);
    }

    public function testTheFirstArchiveIsVersionOneAndKeepsNullCopies(): void
    {
        $this->connection->method('fetchOne')->willReturn('2026-02-01 09:00:00');
        $this->connection->method('fetchAssociative')->willReturn(false);
        $this->connection->expects(self::once())->method('executeStatement')->with(
            self::anything(),
            self::callback(static fn (array $p): bool => $p[1] === 1 && $p[3] === null && $p[4] === null)
        );

        $this->archiver->archiveIfExecuted(7, 'Orders', null, null, [['type' => 'cron']], null);
    }
}
