<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Command;

use Aaxis\Bundle\OntologyBundle\Command\PurgeFlowHistoryCommand;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeFlowHistoryCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $report
     *
     * @return array{int, string}
     */
    private function execute(array $report): array
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->expects(self::once())->method('purge')->willReturn($report + ['cutoff' => '2026-03-10 11:00:00.000000', 'vacuumed' => []]);
        $tester = new CommandTester(new PurgeFlowHistoryCommand($maintenance));

        return [$tester->execute([]), $tester->getDisplay()];
    }

    public function testItRunsDailyThroughOroCron(): void
    {
        $command = new PurgeFlowHistoryCommand($this->createMock(FlowRunMaintenance::class));

        self::assertSame('15 2 * * *', $command->getDefaultDefinition());
        self::assertSame('aaxis:ontology:flow-history:purge', $command->getName());
    }

    public function testItReportsWhatEachStoreLost(): void
    {
        [$code, $display] = $this->execute(['events' => 120, 'runs' => 7, 'bucketConfigured' => true, 'bucketObjects' => 33]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('older than 2026-03-10 11:00:00.000000 (UTC)', $display);
        self::assertStringContainsString('events: 120', $display);
        self::assertStringContainsString('runs: 7', $display);
        self::assertStringContainsString('bucket objects: 33', $display);
        self::assertStringContainsString('vacuumed: none', $display);
    }

    public function testItListsTheTablesItVacuumed(): void
    {
        [, $display] = $this->execute(['events' => 1, 'runs' => 1, 'bucketConfigured' => false, 'bucketObjects' => null,
            'vacuumed' => ['aaxis_ontology_flow_events', 'aaxis_ontology_flow_runs']]);

        self::assertStringContainsString('vacuumed: aaxis_ontology_flow_events, aaxis_ontology_flow_runs', $display);
    }

    public function testASkippedBucketIsNotAFailure(): void
    {
        [$code, $display] = $this->execute(['events' => 0, 'runs' => 0, 'bucketConfigured' => false, 'bucketObjects' => null]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('bucket objects: skipped (no bucket configured)', $display);
    }

    /**
     * @dataProvider failures
     */
    public function testAFailedStoreFailsTheCommand(array $report, string $line): void
    {
        [$code, $display] = $this->execute($report);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString($line, $display);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function failures(): array
    {
        return [
            'events' => [['events' => null, 'runs' => 1, 'bucketConfigured' => false, 'bucketObjects' => null], 'events: failed (see the log)'],
            'runs' => [['events' => 1, 'runs' => null, 'bucketConfigured' => false, 'bucketObjects' => null], 'runs: failed (see the log)'],
            'bucket' => [['events' => 1, 'runs' => 1, 'bucketConfigured' => true, 'bucketObjects' => null], 'bucket objects: failed (see the log)'],
        ];
    }
}
