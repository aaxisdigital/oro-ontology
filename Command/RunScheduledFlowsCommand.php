<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Command;

use Aaxis\Bundle\OntologyBundle\Manager\ScheduledFlowRunner;
use Oro\Bundle\CronBundle\Command\CronCommandScheduleDefinitionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Oro cron entry point for the flow scheduler: runs EVERY MINUTE (so cron expressions match at
 * their minute granularity) and executes whatever schedule-triggered flows are due — see
 * {@see ScheduledFlowRunner} for the due rules. Also runnable by hand for a one-off sweep.
 */
#[AsCommand(
    name: 'aaxis:ontology:flows:run-due',
    description: 'Runs every enabled schedule-triggered Ontology flow that is due.'
)]
class RunScheduledFlowsCommand extends Command implements CronCommandScheduleDefinitionInterface
{
    public function __construct(private readonly ScheduledFlowRunner $runner)
    {
        parent::__construct();
    }

    #[\Override]
    public function getDefaultDefinition(): string
    {
        return '* * * * *';
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->runner->runDue();
        if ($report === []) {
            $output->writeln('<comment>No enabled schedule-triggered flows.</comment>');

            return Command::SUCCESS;
        }
        foreach ($report as $row) {
            $output->writeln(sprintf(
                '%s: %s%s',
                $row['flow'],
                $row['status'],
                isset($row['detail']) ? ' — ' . $row['detail'] : ''
            ));
        }

        return Command::SUCCESS;
    }
}
