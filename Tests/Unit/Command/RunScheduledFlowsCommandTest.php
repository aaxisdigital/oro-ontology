<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Command;

use Aaxis\Bundle\OntologyBundle\Command\RunScheduledFlowsCommand;
use Aaxis\Bundle\OntologyBundle\Manager\ScheduledFlowRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunScheduledFlowsCommandTest extends TestCase
{
    public function testRunsEveryMinute(): void
    {
        $command = new RunScheduledFlowsCommand($this->createMock(ScheduledFlowRunner::class));

        self::assertSame('* * * * *', $command->getDefaultDefinition());
        self::assertSame('aaxis:ontology:flows:run-due', $command->getName());
    }

    public function testReportsWhenThereIsNothingToRun(): void
    {
        $runner = $this->createMock(ScheduledFlowRunner::class);
        $runner->expects(self::once())->method('runDue')->willReturn([]);
        $tester = new CommandTester(new RunScheduledFlowsCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("No enabled schedule-triggered flows.\n", $tester->getDisplay());
    }

    public function testPrintsOneLinePerFlow(): void
    {
        $runner = $this->createMock(ScheduledFlowRunner::class);
        $runner->method('runDue')->willReturn([
            ['flow' => 'Nightly', 'status' => 'executed'],
            ['flow' => 'Broken', 'status' => 'failed', 'detail' => 'boom'],
        ]);
        $tester = new CommandTester(new RunScheduledFlowsCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("Nightly: executed\nBroken: failed — boom\n", $tester->getDisplay());
    }
}
