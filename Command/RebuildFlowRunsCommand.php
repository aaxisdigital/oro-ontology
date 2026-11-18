<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Command;

use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-derives the run index of the ACTIVE event backend from the stored events — the same work as
 * the "Rebuild run index" button on System Configuration → Ontology → Settings, run in the
 * foreground ({@see FlowRunMaintenance::rebuild()}).
 */
#[AsCommand(
    name: 'aaxis:ontology:flow-runs:rebuild',
    description: 'Rebuilds the Ontology run index (Events page) from the stored flow events.'
)]
class RebuildFlowRunsCommand extends Command
{
    public function __construct(private readonly FlowRunMaintenance $maintenance)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Bucket backend: how many days back to index (default: flow_execution_history_days)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $result = $this->maintenance->rebuild($days !== null ? (int) $days : null);
        $output->writeln($result['days'] === null
            ? sprintf('Indexed %d table-stored runs.', $result['runs'])
            : sprintf('Indexed %d bucket-stored runs of the last %d days.', $result['runs'], $result['days']));

        return Command::SUCCESS;
    }
}
