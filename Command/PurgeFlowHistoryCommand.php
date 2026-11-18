<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Command;

use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Bundle\CronBundle\Command\CronCommandScheduleDefinitionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Daily RETENTION of the flow-execution history: removes what is older than "Flow execution
 * history days" from the events table, the run index and the bucket's event folders
 * ({@see FlowRunMaintenance::purge()}). Oro cron runs it every day at 02:15; register it with
 * `oro:cron:definitions:load`. Runnable by hand too.
 */
#[AsCommand(
    name: 'aaxis:ontology:flow-history:purge',
    description: 'Removes Ontology flow-execution history older than the configured history days.'
)]
class PurgeFlowHistoryCommand extends Command implements CronCommandScheduleDefinitionInterface
{
    public function __construct(private readonly FlowRunMaintenance $maintenance)
    {
        parent::__construct();
    }

    #[\Override]
    public function getDefaultDefinition(): string
    {
        return '15 2 * * *';
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->maintenance->purge();
        $count = static fn (?int $n): string => $n === null ? 'failed (see the log)' : (string) $n;
        $output->writeln(sprintf('Removed flow-execution history older than %s (UTC):', $report['cutoff']));
        $output->writeln(sprintf('  events: %s', $count($report['events'])));
        $output->writeln(sprintf('  runs: %s', $count($report['runs'])));
        $output->writeln(sprintf(
            '  bucket objects: %s',
            $report['bucketConfigured'] ? $count($report['bucketObjects']) : 'skipped (no bucket configured)'
        ));
        $output->writeln(sprintf('  vacuumed: %s', $report['vacuumed'] ? implode(', ', $report['vacuumed']) : 'none'));
        $failed = $report['events'] === null || $report['runs'] === null
            || ($report['bucketConfigured'] && $report['bucketObjects'] === null);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
