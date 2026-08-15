<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Cron\CronExpression;
use Doctrine\Persistence\ManagerRegistry;
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
    ) {
    }

    /**
     * @return array<int, array{flow: string, status: string, detail?: string}>
     *         status: executed | failed | not-due | skipped (unrunnable design/config)
     */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

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
        $parsed = $this->parseDesign($flow->getDesign());
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
            $this->executor->execute($steps, $links, [], $flow);

            return ['flow' => $name, 'status' => 'executed'];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Scheduled flow "%s" failed: %s', $name, $e->getMessage()), ['exception' => $e]);

            return ['flow' => $name, 'status' => 'failed', 'detail' => $e->getMessage()];
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

    /**
     * Rebuilds the executor inputs from the flow's saved design (version 2): the normalized steps
     * [{id, type, name, config}], the links, and the schedule trigger step. Null when the design
     * is missing/unreadable or carries no schedule trigger.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>}|null
     */
    private function parseDesign(mixed $design): ?array
    {
        if (!\is_array($design) || !\is_array($design['steps'] ?? null)) {
            return null;
        }
        $steps = [];
        $trigger = null;
        foreach ($design['steps'] as $step) {
            if (!\is_array($step) || !\is_string($step['id'] ?? null) || !\is_string($step['type'] ?? null)) {
                return null;
            }
            $config = $step['config'] ?? null;
            $normalized = [
                'id' => $step['id'],
                'type' => $step['type'],
                'name' => \is_string($step['name'] ?? null) ? $step['name'] : $step['id'],
                'config' => \is_array($config) ? $config : null,
            ];
            $steps[] = $normalized;
            if ($trigger === null && $step['type'] === 'cron') {
                $trigger = $normalized;
            }
        }
        if ($trigger === null) {
            return null;
        }
        $links = [];
        foreach (\is_array($design['links'] ?? null) ? $design['links'] : [] as $link) {
            if (\is_array($link) && \is_string($link['from'] ?? null) && \is_string($link['to'] ?? null)) {
                $links[] = ['from' => $link['from'], 'fromPort' => (int) ($link['fromPort'] ?? 0), 'to' => $link['to']];
            }
        }

        return [$steps, $links, $trigger];
    }
}
