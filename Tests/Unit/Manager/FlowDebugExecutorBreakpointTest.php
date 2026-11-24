<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;

/**
 * FlowDebugExecutor::debugWalk() with DEBUG BREAKPOINTS: Continue (runAll) pauses BEFORE the next
 * step carrying one on its canvas — the debugged flow ('root', its inner flows included) or a
 * subflow (by id) — descending into invokers to reach them.
 */
class FlowDebugExecutorBreakpointTest extends FlowDebugExecutorTestCase
{
    private OntologyFlow $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->savedFlow(1, 'Main');
        $this->subflow(20, 'Enrich', [
            self::step('st', 'subflow', null, 'Enrich'),
            self::step('sa', 'dwl_transform', ['code' => 'n + 1', 'destination' => 'n'], 'Add'),
        ], [self::link('st', 'sa')]);
    }

    /** @return array<int, array<string, mixed>> */
    private static function steps(array $extra = []): array
    {
        return array_merge([
            self::step('t', 'cron', null, 'Main'),
            self::step('a', 'dwl_transform', ['code' => '1', 'destination' => 'n'], 'Init'),
            self::step('call', 'sub_flow', ['subflow' => 20], 'Call'),
            self::step('b', 'dwl_transform', ['code' => 'n * 10', 'destination' => 'result'], 'Finish'),
        ], $extra);
    }

    /** @return array{blob: array<string, mixed>, response: array<string, mixed>} */
    private function continueTo(?array $blob, array $breakpoints, array $steps = []): array
    {
        return $this->executor->debugWalk(
            $steps ?: self::steps(),
            [self::link('t', 'a'), self::link('a', 'call'), self::link('call', 'b')],
            [],
            $blob,
            $this->root,
            true,
            [],
            false,
            false,
            $breakpoints
        );
    }

    public function testContinuePausesBeforeABreakpointOfTheDebuggedFlow(): void
    {
        $paused = $this->continueTo(null, ['root' => ['b']]);

        self::assertFalse($paused['response']['done']);
        self::assertSame(['id' => 'b', 'name' => 'Finish', 'type' => 'dwl_transform'], $paused['response']['breakpoint']);
        self::assertSame($paused['response']['breakpoint'], $paused['response']['next']);
        self::assertSame(['t', 'a', 'call'], $paused['response']['executedIds']);
        self::assertSame(2, $paused['response']['context']['n']);
        self::assertArrayNotHasKey('result', $paused['response']['context']);

        // Continuing from ON the breakpoint runs it (and here, to the end).
        $end = $this->continueTo($paused['blob'], ['root' => ['b']]);
        self::assertTrue($end['response']['done']);
        self::assertSame(['b'], $end['response']['executedIds']);
        self::assertArrayNotHasKey('breakpoint', $end['response']);
        self::assertSame(20, $end['response']['context']['result']);
    }

    public function testContinueDescendsIntoASubflowToReachItsBreakpoint(): void
    {
        $paused = $this->continueTo(null, ['20' => ['sa']]);

        self::assertSame('sa', $paused['response']['breakpoint']['id']);
        self::assertSame(['flowId' => 20, 'flowName' => 'Enrich', 'depth' => 1, 'canvasDepth' => 1, 'innerId' => null], $paused['response']['frame']);
        // The subflow canvas shows its entry; the debugged flow's own ids travel apart.
        self::assertSame(['st'], $paused['response']['executedIds']);
        self::assertSame(['t', 'a'], $paused['response']['rootExecutedIds']);
        self::assertSame([['flowId' => 20, 'flowName' => 'Enrich', 'executedIds' => ['st']]], $paused['response']['subflowTrails']);

        $end = $this->continueTo($paused['blob'], ['20' => ['sa']]);
        self::assertTrue($end['response']['done']);
        self::assertSame(20, $end['response']['context']['result']);
        self::assertSame(['call', 'b'], $end['response']['executedIds']);
    }

    public function testABreakpointInsideAnInnerFlowPausesOnTheSameCanvas(): void
    {
        $steps = self::steps([
            self::step('box', 'inner_flow', ['children' => ['x1', 'x2']], 'Box'),
            self::step('x1', 'dwl_transform', ['code' => 'n + 5', 'destination' => 'n'], 'Five'),
            self::step('x2', 'dwl_transform', ['code' => 'n * 2', 'destination' => 'n'], 'Double'),
        ]);
        $steps[2] = self::step('call', 'sub_flow', ['inner' => 'box'], 'Call');

        $paused = $this->continueTo(null, ['root' => ['x2']], $steps);

        self::assertSame('x2', $paused['response']['breakpoint']['id']);
        self::assertSame(0, $paused['response']['frame']['canvasDepth']);
        self::assertSame(['t', 'a', 'box', 'x1'], $paused['response']['executedIds']);
        self::assertSame(6, $paused['response']['context']['n']);
    }

    public function testBreakpointsOffTheWayLetContinueRunToTheEnd(): void
    {
        $end = $this->continueTo(null, ['root' => ['nowhere'], '99' => ['x']]);

        self::assertTrue($end['response']['done']);
        self::assertSame(20, $end['response']['context']['result']);
        self::assertArrayNotHasKey('breakpoint', $end['response']);
    }

    public function testASingleTickIgnoresBreakpoints(): void
    {
        $tick = $this->executor->debugWalk(
            self::steps(),
            [self::link('t', 'a'), self::link('a', 'call'), self::link('call', 'b')],
            [],
            null,
            $this->root,
            false,
            [],
            true,
            false,
            ['root' => ['a']]
        );

        self::assertSame('t', $tick['response']['step']['id']);
        self::assertArrayNotHasKey('breakpoint', $tick['response']);
    }
}
