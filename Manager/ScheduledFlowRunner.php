<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Cron\CronExpression;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the schedule-triggered flows that are DUE — the minutely cron command's engine.
 *
 * Candidates come from a plain column select (enabled + type=flow + trigger_type=cron, the
 * denormalized trigger column), then each flow's schedule config decides:
 *  - interval mode: due when `value unit` has elapsed since last_executed — or since
 *    last_modified when the flow never ran (its creation date until the first edit);
 *  - cron mode (or the legacy expression-only config): due when the CURRENT minute matches the
 *    cron expression (the command runs every minute; a same-minute guard stops double runs).
 *
 * Due flows execute through the SAME engine as the editor's Run Now ({@see FlowDebugExecutor}),
 * rebuilt from the flow's saved `design` (the only persisted shape carrying step ids + links) —
 * so last_executed stamping, flowUuid minting, synchronous writes and event rows all behave
 * identically. One flow's failure is logged and never blocks the others.
 */
class ScheduledFlowRunner
{
    use FlowDesignParserTrait;

    /**
     * How long a flow may stay "running" before the scheduler assumes the run died and starts a new
     * one. Without this, a process killed mid-run (fatal error, container restart, deploy) never
     * stamps `last_finished` and the flow would be blocked forever.
     */
    public const int RUN_STALE_AFTER_SECONDS = 6 * 3600;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly FlowDebugExecutor $executor,
        private readonly LoggerInterface $logger,
        private readonly ConfigManager $config,
        private readonly OntologyFlowEventEmitter $flowEvents,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<int, array{flow: string, status: string, detail?: string}>
     *         status: executed | failed | not-due | skipped (unrunnable design/config)
     */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->sweepStaleDebugSessions($now);

        /** @var OntologyFlow[] $flows */
        $flows = $this->doctrine->getRepository(OntologyFlow::class)->findBy([
            'type' => OntologyFlow::TYPE_FLOW,
            'enabled' => true,
            'triggerType' => 'cron', // the Schedule trigger's step type
        ]);

        return array_map(fn (OntologyFlow $flow): array => $this->runIfDue($flow, $now), $flows);
    }

    /**
     * @return array{flow: string, status: string, detail?: string}
     */
    private function runIfDue(OntologyFlow $flow, \DateTimeImmutable $now): array
    {
        $name = (string) $flow->getName();
        $parsed = $this->parseDesign($flow->getDesign(), 'cron');
        if ($parsed === null) {
            return ['flow' => $name, 'status' => 'skipped', 'detail' => 'no runnable design with a schedule trigger'];
        }
        [$steps, $links, $trigger] = $parsed;

        $config = $trigger['config'];
        if (!\is_array($config)) {
            return ['flow' => $name, 'status' => 'skipped', 'detail' => 'the schedule trigger is not configured'];
        }
        if (!$this->isDue($flow, $config, $now)) {
            return ['flow' => $name, 'status' => 'not-due'];
        }
        // One instance at a time: a run that takes longer than its interval must not be doubled up
        // by the next tick.
        if ($this->isStillRunning($flow, $now)) {
            return ['flow' => $name, 'status' => 'running', 'detail' => 'a previous run is still in progress'];
        }

        try {
            $this->executor->execute($steps, $links, [], $flow, null, null, ['trigger' => 'schedule']);

            return ['flow' => $name, 'status' => 'executed'];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Scheduled flow "%s" failed: %s', $name, $e->getMessage()), ['exception' => $e]);

            return ['flow' => $name, 'status' => 'failed', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Terminates ABANDONED stepwise debug sessions (this command runs every minute): a run whose
     * flow-start says trigger "debug", that never finished, and whose LAST event is older than
     * the configured inactivity timeout gets a flow-exception "debug-timeout" event — and its
     * server-side walk blob is marked terminated, so a late "next step" is refused. Run Now uses
     * the same trigger but finishes synchronously, so only stale stepwise sessions (and crashed
     * runs, which are equally dead) match.
     */
    private function sweepStaleDebugSessions(\DateTimeImmutable $now): void
    {
        $minutes = max(0, (int) $this->config->get('aaxis_ontology.flow_debug_timeout_minutes'));
        if ($minutes === 0) {
            return;
        }
        try {
            $stale = $this->doctrine->getConnection()->fetchAllAssociative(
                "SELECT flow_uuid,
                        (array_agg(flow_id ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS flow_id,
                        (array_agg(flow_name ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS flow_name
                 FROM aaxis_ontology_flow_events
                 WHERE flow_uuid IS NOT NULL
                 GROUP BY flow_uuid
                 HAVING bool_or(event = 'flow-start' AND payload->>'trigger' = 'debug')
                    AND NOT bool_or(event IN ('flow-finish', 'flow-exception'))
                    AND max(datetime) < :cutoff",
                ['cutoff' => $now->modify(sprintf('-%d minutes', $minutes))->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Stale debug sweep failed.', ['exception' => $e]);

            return;
        }
        foreach ($stale as $run) {
            $this->flowEvents->emitRaw(
                isset($run['flow_id']) ? (int) $run['flow_id'] : null,
                $run['flow_name'] ?? null,
                (string) $run['flow_uuid'],
                'flow-exception',
                ['message' => 'debug-timeout']
            );
            // Mark the walk blob dead so a late "next step" is refused with the timeout message.
            $item = $this->cache->getItem('aaxis_ontology_debug_ctx.' . strtolower((string) $run['flow_uuid']));
            if ($item->isHit() && \is_array($item->get())) {
                $blob = $item->get();
                $blob['terminated'] = 'debug-timeout';
                $item->set($blob);
                $this->cache->save($item);
            }
        }
    }

    /**
     * Whether an instance of this flow is still in flight — `last_executed` set with no matching
     * `last_finished` yet ({@see OntologyFlow::isRunning()}).
     *
     * A run older than {@see RUN_STALE_AFTER_SECONDS} is treated as dead rather than running: a
     * process killed mid-run never gets to stamp `last_finished`, and without this cut-off that
     * flow would never be scheduled again.
     */
    private function isStillRunning(OntologyFlow $flow, \DateTimeImmutable $now): bool
    {
        if (!$flow->isRunning()) {
            return false;
        }
        $started = $flow->getLastExecuted();
        if ($started === null) {
            return false;
        }
        // The datetime columns hold UTC wall-clock — re-read as UTC so the age is timezone-proof.
        $startedUtc = new \DateTimeImmutable($started->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

        return ($now->getTimestamp() - $startedUtc->getTimestamp()) < self::RUN_STALE_AFTER_SECONDS;
    }

    /**
     * @param array<string, mixed> $config the schedule trigger's config
     */
    private function isDue(OntologyFlow $flow, array $config, \DateTimeImmutable $now): bool
    {
        if (($config['mode'] ?? 'cron') === 'interval') {
            $value = (int) ($config['value'] ?? 0);
            $unit = (string) ($config['unit'] ?? '');
            if ($value < 1 || !\in_array($unit, ['minute', 'hour', 'day', 'week', 'month', 'year'], true)) {
                return false;
            }
            // The datetime columns hold UTC wall-clock: re-read the hydrated value as UTC so the
            // comparison is timezone-proof regardless of the PHP default timezone.
            $baseline = $flow->getLastExecuted() ?? $flow->getLastModified();
            if ($baseline === null) {
                return true;
            }
            $baselineUtc = new \DateTimeImmutable($baseline->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

            return $baselineUtc->modify(sprintf('+%d %s', $value, $unit)) <= $now;
        }

        $expression = trim((string) ($config['expression'] ?? ''));
        if ($expression === '' || !CronExpression::isValidExpression($expression)) {
            return false;
        }
        // A cron expression matches a whole MINUTE — never run the same flow twice within it.
        $last = $flow->getLastExecuted();
        if ($last !== null && $last->format('YmdHi') === $now->format('YmdHi')) {
            return false;
        }

        return (new CronExpression($expression))->isDue(\DateTime::createFromImmutable($now));
    }
}
