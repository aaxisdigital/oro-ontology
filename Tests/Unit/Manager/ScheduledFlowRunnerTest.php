<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunIndex;
use Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\ScheduledFlowRunner;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class ScheduledFlowRunnerTest extends TestCase
{
    private ManagerRegistry&MockObject $doctrine;
    private ObjectRepository&MockObject $flows;
    private FlowDebugExecutor&MockObject $executor;
    private LoggerInterface&MockObject $logger;
    private ConfigManager&MockObject $config;
    private OntologyFlowEventEmitter&MockObject $events;
    private CacheItemPoolInterface&MockObject $cache;
    private FlowRunIndex&MockObject $runs;
    private ScheduledFlowRunner $runner;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->flows = $this->createMock(ObjectRepository::class);
        $this->doctrine->method('getRepository')->with(OntologyFlow::class)->willReturn($this->flows);
        $this->executor = $this->createMock(FlowDebugExecutor::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(ConfigManager::class);
        $this->events = $this->createMock(OntologyFlowEventEmitter::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->runs = $this->createMock(FlowRunIndex::class);
        $this->runner = new ScheduledFlowRunner(
            $this->doctrine,
            $this->executor,
            $this->logger,
            $this->config,
            $this->events,
            $this->cache,
            $this->runs
        );
        $this->now = new \DateTimeImmutable('2026-05-10 12:30:00', new \DateTimeZone('UTC'));
    }

    private static function flow(string $name, ?array $triggerConfig, bool $withDesign = true): OntologyFlow
    {
        $flow = (new OntologyFlow())->setName($name)->setLastModified(new \DateTime('2026-05-01 00:00:00'));
        if ($withDesign) {
            $flow->setDesign([
                'steps' => [
                    ['id' => 't', 'type' => 'cron', 'name' => $name, 'config' => $triggerConfig],
                    ['id' => 'l', 'type' => 'logger', 'name' => 'Log', 'config' => ['message' => 'hi']],
                ],
                'links' => [['from' => 't', 'fromPort' => 0, 'to' => 'l']],
            ]);
        }

        return $flow;
    }

    /**
     * @param OntologyFlow[] $flows
     */
    private function withFlows(array $flows): void
    {
        $this->flows->expects(self::once())->method('findBy')
            ->with(['type' => OntologyFlow::TYPE_FLOW, 'enabled' => true, 'triggerType' => 'cron'])
            ->willReturn($flows);
    }

    public function testReportsEveryCandidateFlow(): void
    {
        $unconfigured = self::flow('Unconfigured', null);
        $badInterval = self::flow('Bad interval', ['mode' => 'interval', 'value' => 0, 'unit' => 'minute']);
        $badUnit = self::flow('Bad unit', ['mode' => 'interval', 'value' => 1, 'unit' => 'fortnight']);
        $notElapsed = self::flow('Not elapsed', ['mode' => 'interval', 'value' => 1, 'unit' => 'hour'])
            ->setLastExecuted(new \DateTime('2026-05-10 12:00:00'))->setLastFinished(new \DateTime('2026-05-10 12:01:00'));
        $badCron = self::flow('Bad cron', ['expression' => 'not cron']);
        $emptyCron = self::flow('Empty cron', ['mode' => 'cron', 'expression' => ' ']);
        $otherMinute = self::flow('Other minute', ['expression' => '0 3 * * *']);
        $sameMinute = self::flow('Same minute', ['expression' => '* * * * *'])
            ->setLastExecuted(new \DateTime('2026-05-10 12:30:05'))->setLastFinished(new \DateTime('2026-05-10 12:30:06'));
        $this->withFlows([
            self::flow('No design', null, false),
            $unconfigured,
            $badInterval,
            $badUnit,
            $notElapsed,
            $badCron,
            $emptyCron,
            $otherMinute,
            $sameMinute,
        ]);
        $this->executor->expects(self::never())->method('execute');

        self::assertSame([
            ['flow' => 'No design', 'status' => 'skipped', 'detail' => 'no runnable design with a schedule trigger'],
            ['flow' => 'Unconfigured', 'status' => 'skipped', 'detail' => 'the schedule trigger is not configured'],
            ['flow' => 'Bad interval', 'status' => 'not-due'],
            ['flow' => 'Bad unit', 'status' => 'not-due'],
            ['flow' => 'Not elapsed', 'status' => 'not-due'],
            ['flow' => 'Bad cron', 'status' => 'not-due'],
            ['flow' => 'Empty cron', 'status' => 'not-due'],
            ['flow' => 'Other minute', 'status' => 'not-due'],
            ['flow' => 'Same minute', 'status' => 'not-due'],
        ], $this->runner->runDue($this->now));
    }

    public function testRunsDueFlowsThroughTheExecutor(): void
    {
        $cron = self::flow('Every minute', ['expression' => '* * * * *']);
        $interval = self::flow('Hourly', ['mode' => 'interval', 'value' => 1, 'unit' => 'hour'])
            ->setLastExecuted(new \DateTime('2026-05-10 11:30:00'))->setLastFinished(new \DateTime('2026-05-10 11:31:00'));
        $neverRan = self::flow('Fresh', ['mode' => 'interval', 'value' => 1, 'unit' => 'day']);
        $this->withFlows([$cron, $interval, $neverRan]);
        $calls = [];
        $this->executor->expects(self::exactly(3))->method('execute')
            ->willReturnCallback(static function (array $steps, array $links, array $input, OntologyFlow $flow, ?string $uuid, ?array $seed, array $runInfo) use (&$calls): array {
                $calls[] = [$flow->getName(), \count($steps), $links, $input, $uuid, $seed, $runInfo];

                return [];
            });

        self::assertSame([
            ['flow' => 'Every minute', 'status' => 'executed'],
            ['flow' => 'Hourly', 'status' => 'executed'],
            ['flow' => 'Fresh', 'status' => 'executed'],
        ], $this->runner->runDue($this->now));
        self::assertSame(
            ['Every minute', 2, [['from' => 't', 'fromPort' => 0, 'to' => 'l']], [], null, null, ['trigger' => 'schedule']],
            $calls[0]
        );
    }

    public function testAFlowWithoutAnyBaselineIsDue(): void
    {
        $flow = self::flow('No baseline', ['mode' => 'interval', 'value' => 5, 'unit' => 'minute']);
        (new \ReflectionProperty(OntologyFlow::class, 'lastModified'))->setValue($flow, null);
        $this->withFlows([$flow]);
        $this->executor->expects(self::once())->method('execute');

        self::assertSame([['flow' => 'No baseline', 'status' => 'executed']], $this->runner->runDue($this->now));
    }

    public function testARunStillInFlightIsNotDoubledUp(): void
    {
        $running = self::flow('Busy', ['expression' => '* * * * *'])->setLastExecuted(new \DateTime('2026-05-10 12:00:00'));
        $this->withFlows([$running]);
        $this->executor->expects(self::never())->method('execute');

        self::assertSame(
            [['flow' => 'Busy', 'status' => 'running', 'detail' => 'a previous run is still in progress']],
            $this->runner->runDue($this->now)
        );
    }

    public function testARunOlderThanTheStaleCutOffIsPresumedDead(): void
    {
        $stuck = self::flow('Stuck', ['expression' => '* * * * *'])
            ->setLastExecuted(new \DateTime('2026-05-10 06:29:59'))
            ->setLastFinished(new \DateTime('2026-05-09 00:00:00'));
        $this->withFlows([$stuck]);
        $this->executor->expects(self::once())->method('execute');

        self::assertSame([['flow' => 'Stuck', 'status' => 'executed']], $this->runner->runDue($this->now));
    }

    public function testAFailingFlowIsLoggedAndDoesNotStopTheOthers(): void
    {
        $this->withFlows([
            self::flow('Broken', ['expression' => '* * * * *']),
            self::flow('Fine', ['expression' => '* * * * *']),
        ]);
        $failure = new \RuntimeException('step exploded');
        $this->executor->method('execute')->willReturnCallback(static function (array $s, array $l, array $i, OntologyFlow $flow) use ($failure): array {
            if ($flow->getName() === 'Broken') {
                throw $failure;
            }

            return [];
        });
        $this->logger->expects(self::once())->method('error')
            ->with('Scheduled flow "Broken" failed: step exploded', ['exception' => $failure]);

        self::assertSame([
            ['flow' => 'Broken', 'status' => 'failed', 'detail' => 'step exploded'],
            ['flow' => 'Fine', 'status' => 'executed'],
        ], $this->runner->runDue($this->now));
    }

    public function testTheDebugSweepIsOffWithoutATimeout(): void
    {
        $this->config->method('get')->with('aaxis_ontology.flow_debug_timeout_minutes')->willReturn(null);
        $this->runs->expects(self::never())->method('staleDebugRuns');
        $this->withFlows([]);

        self::assertSame([], $this->runner->runDue($this->now));
    }

    public function testTheSweepTerminatesTheStaleDebugSessionsTheRunIndexReports(): void
    {
        $this->config->method('get')->willReturn('15');
        $this->doctrine->expects(self::never())->method('getConnection');
        $this->runs->expects(self::once())->method('staleDebugRuns')
            ->with(self::callback(fn (\DateTimeImmutable $cutoff): bool => $cutoff == $this->now->modify('-15 minutes')))
            ->willReturn([
                ['flow_uuid' => 'ABC', 'flow_id' => '4', 'flow_name' => 'Debugged'],
                ['flow_uuid' => 'def', 'flow_id' => null, 'flow_name' => null],
            ]);
        $emitted = [];
        $this->events->expects(self::exactly(2))->method('emitRaw')
            ->willReturnCallback(static function (...$args) use (&$emitted): void {
                $emitted[] = $args;
            });
        $hit = $this->createMock(CacheItemInterface::class);
        $hit->method('isHit')->willReturn(true);
        $hit->method('get')->willReturn(['frames' => []]);
        $hit->expects(self::once())->method('set')->with(['frames' => [], 'terminated' => 'debug-timeout']);
        $miss = $this->createMock(CacheItemInterface::class);
        $miss->method('isHit')->willReturn(false);
        $miss->expects(self::never())->method('set');
        $this->cache->method('getItem')->willReturnMap([
            ['aaxis_ontology_debug_ctx.abc', $hit],
            ['aaxis_ontology_debug_ctx.def', $miss],
        ]);
        $this->cache->expects(self::once())->method('save')->with($hit);
        $this->withFlows([]);

        $this->runner->runDue($this->now);

        self::assertSame([
            [4, 'Debugged', 'ABC', 'flow-exception', ['message' => 'debug-timeout']],
            [null, null, 'def', 'flow-exception', ['message' => 'debug-timeout']],
        ], $emitted);
    }

    public function testAStaleSessionWhoseCachedWalkIsUnreadableIsOnlyClosed(): void
    {
        $this->config->method('get')->willReturn(30);
        $this->runs->method('staleDebugRuns')->willReturn([['flow_uuid' => 'u1', 'flow_id' => 2, 'flow_name' => 'F']]);
        $this->events->expects(self::once())->method('emitRaw')->with(2, 'F', 'u1', 'flow-exception', ['message' => 'debug-timeout']);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn('not a blob');
        $item->expects(self::never())->method('set');
        $this->cache->method('getItem')->willReturn($item);
        $this->withFlows([]);

        $this->runner->runDue($this->now);
    }

    public function testASweepFailureIsLoggedAndSchedulingGoesOn(): void
    {
        $this->config->method('get')->willReturn(5);
        $this->runs->method('staleDebugRuns')->willThrowException(new \RuntimeException('database down'));
        $this->logger->expects(self::once())->method('error')->with('Stale debug sweep failed.', self::anything());
        $this->events->expects(self::never())->method('emitRaw');
        $this->withFlows([self::flow('Fine', ['expression' => '* * * * *'])]);

        self::assertSame([['flow' => 'Fine', 'status' => 'executed']], $this->runner->runDue($this->now));
    }

    public function testDefaultsToTheCurrentTime(): void
    {
        $this->withFlows([self::flow('Every minute', ['expression' => '* * * * *'])]);

        self::assertSame([['flow' => 'Every minute', 'status' => 'executed']], $this->runner->runDue());
    }
}
