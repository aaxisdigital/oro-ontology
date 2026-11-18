<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Command;

use Aaxis\Bundle\OntologyBundle\Command\RebuildFlowRunsCommand;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RebuildFlowRunsCommandTest extends TestCase
{
    private FlowRunMaintenance&MockObject $maintenance;

    protected function setUp(): void
    {
        $this->maintenance = $this->createMock(FlowRunMaintenance::class);
    }

    private function execute(array $input = []): string
    {
        $tester = new CommandTester(new RebuildFlowRunsCommand($this->maintenance));
        self::assertSame(Command::SUCCESS, $tester->execute($input));

        return $tester->getDisplay();
    }

    public function testItReportsATableRebuild(): void
    {
        $this->maintenance->expects(self::once())->method('rebuild')->with(null)->willReturn(['storage' => 'db', 'runs' => 42, 'days' => null]);

        self::assertStringContainsString('Indexed 42 table-stored runs.', $this->execute());
    }

    public function testItPassesTheDaysOptionAndReportsABucketRebuild(): void
    {
        $this->maintenance->expects(self::once())->method('rebuild')->with(90)->willReturn(['storage' => 'bucket', 'runs' => 5, 'days' => 90]);

        self::assertStringContainsString('Indexed 5 bucket-stored runs of the last 90 days.', $this->execute(['--days' => '90']));
    }
}
