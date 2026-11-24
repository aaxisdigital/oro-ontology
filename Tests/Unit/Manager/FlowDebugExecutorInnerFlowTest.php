<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\FlowExecutionFailure;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;

/**
 * Inner flows: a container step (`inner_flow`) on the flow's own canvas holding an ordered row of
 * elements (`config.children`), invoked by Call Subflow / Foreach Loop through `config.inner` —
 * atomically (execute) and frame by frame (debugWalk).
 */
class FlowDebugExecutorInnerFlowTest extends FlowDebugExecutorTestCase
{
    private OntologyFlow $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->savedFlow(1, 'Main');
    }

    /**
     * The canvas: cron → Init → [invoker] → Finish, plus the inner flow "Box" holding Add then Double.
     *
     * @param array<int, array<string, mixed>> $extra more canvas steps
     *
     * @return array<int, array<string, mixed>>
     */
    private static function canvas(string $invoker = 'sub_flow', array $invokerConfig = ['inner' => 'box'], array $children = ['add', 'dbl'], array $extra = []): array
    {
        return array_merge([
            self::step('t', 'cron', null, 'Main'),
            self::step('a', 'dwl_transform', ['code' => '1', 'destination' => 'n'], 'Init'),
            self::step('call', $invoker, $invokerConfig, 'Call'),
            self::step('b', 'dwl_transform', ['code' => 'n * 10', 'destination' => 'result'], 'Finish'),
            self::step('box', 'inner_flow', ['children' => $children], 'Box'),
            self::step('add', 'dwl_transform', ['code' => 'n + 1', 'destination' => 'n'], 'Add'),
            self::step('dbl', 'dwl_transform', ['code' => 'n * 2', 'destination' => 'n'], 'Double'),
        ], $extra);
    }

    /** @return array<int, array<string, mixed>> */
    private static function links(): array
    {
        return [self::link('t', 'a'), self::link('a', 'call'), self::link('call', 'b')];
    }

    /** @return array<string, mixed> */
    private function runCanvas(array $steps, array $seed = []): array
    {
        return $this->executor->execute($steps, self::links(), [], $this->root, null, $seed === [] ? null : ['flowUuid' => 'u'] + $seed);
    }

    // --- atomic runs -------------------------------------------------------------------------

    public function testCallSubflowRunsTheInnerFlowInOrderOnTheSameCanvas(): void
    {
        $context = $this->runCanvas(self::canvas());

        // Init n=1, Add → 2, Double → 4, Finish → 40; the elements are NOT walked by the main BFS.
        self::assertSame(['flowUuid' => 'run-uuid', 'n' => 4, 'result' => 40], $context);
        // Same canvas: the inner trail joins the caller's (box = its entry), no subflow visit.
        self::assertSame(['t', 'a', 'box', 'add', 'dbl', 'call', 'b'], $this->executor->lastExecutedIds());
        self::assertSame([], $this->executor->subflowTrails());
        self::assertSame(
            ['flow-start', 'step', 'step', 'inner-flow-start', 'step', 'step', 'step', 'inner-flow-finish', 'step', 'step', 'flow-finish'],
            $this->eventKinds()
        );
        // The inner run reports as an INNER FLOW of this flow (never as a subflow), naming the box.
        self::assertSame(['inner-flow-start', 'Main', ['inner' => 'Box']], $this->events[3]);
        self::assertNotContains('subflow-start', $this->eventKinds());
        self::assertSame(['step', 'Main', ['name' => 'Box', 'type' => 'inner_flow']], $this->events[4]);
    }

    public function testAnInnerRunLeavesTheFlowsRunningStampsToTheOuterRun(): void
    {
        // One last_executed + one last_finished for the whole run (the inner run adds none).
        $this->em->expects(self::exactly(2))->method('flush');

        $this->runCanvas(self::canvas());

        self::assertNotNull($this->root->getLastExecuted());
        self::assertNotNull($this->root->getLastFinished());
    }

    public function testAnEmptyInnerFlowRunsNothing(): void
    {
        $context = $this->runCanvas(self::canvas(children: []));

        self::assertSame(10, $context['result']);
        self::assertSame(['t', 'a', 'box', 'call', 'b'], $this->executor->lastExecutedIds());
    }

    public function testAChoiceInsideAnInnerFlowEndsItWhenItDoesNotMatch(): void
    {
        $steps = self::canvas(children: ['gate', 'add'], extra: [self::step('gate', 'choice', ['expression' => 'n > 5'], 'Gate')]);

        $context = $this->runCanvas($steps);

        self::assertSame(10, $context['result']); // Add never ran: the gate closed the row
        self::assertSame(['t', 'a', 'box', 'gate', 'call', 'b'], $this->executor->lastExecutedIds());
    }

    /**
     * @return array<int, array<string, mixed>> a box whose Choice "Gate" (n > $limit) continues with
     *                                          Add on a match and runs its FALSE lane (Minus, Tenfold) otherwise
     */
    private static function gatedCanvas(int $limit): array
    {
        return self::canvas(children: ['gate', 'add'], extra: [
            self::step('gate', 'choice', ['expression' => 'n > ' . $limit], 'Gate'),
            self::step('minus', 'dwl_transform', ['code' => 'n - 1', 'destination' => 'n'], 'Minus'),
            self::step('ten', 'dwl_transform', ['code' => 'n * 10', 'destination' => 'n'], 'Tenfold'),
        ]);
    }

    /** @param array<int, array<string, mixed>> $steps */
    private static function withBranches(array $steps, array $branches): array
    {
        foreach ($steps as &$step) {
            if ($step['id'] === 'box') {
                $step['config']['branches'] = $branches;
            }
        }

        return $steps;
    }

    public function testAChoiceRunsItsFalseLaneWhenItDoesNotMatch(): void
    {
        $context = $this->runCanvas(self::withBranches(self::gatedCanvas(5), ['gate' => ['minus', 'ten']]));

        // n = 1: no match → Minus (0) then Tenfold (0); Add never runs; the inner flow ends there.
        self::assertSame(0, $context['n']);
        self::assertSame(['t', 'a', 'box', 'gate', 'minus', 'ten', 'call', 'b'], $this->executor->lastExecutedIds());
    }

    public function testAChoiceContinuesOnTheMainRowWhenItMatches(): void
    {
        $context = $this->runCanvas(self::withBranches(self::gatedCanvas(0), ['gate' => ['minus', 'ten']]));

        self::assertSame(2, $context['n']);
        self::assertSame(['t', 'a', 'box', 'gate', 'add', 'call', 'b'], $this->executor->lastExecutedIds());
    }

    public function testFalseLanesNest(): void
    {
        $steps = self::withBranches(self::canvas(children: ['gate'], extra: [
            self::step('gate', 'choice', ['expression' => 'false'], 'Gate'),
            self::step('gate2', 'choice', ['expression' => 'false'], 'Gate 2'),
            self::step('minus', 'dwl_transform', ['code' => 'n - 1', 'destination' => 'n'], 'Minus'),
        ]), ['gate' => ['gate2'], 'gate2' => ['minus']]);

        self::assertSame(0, $this->runCanvas($steps)['n']);
    }

    /**
     * @return iterable<string, array{0: array<string, array<int, string>>}>
     */
    public static function brokenBranches(): iterable
    {
        yield 'lane under a step that is no Choice' => [['add' => ['minus']]];
        yield 'lane under a Choice of no inner flow' => [['elsewhere' => ['minus']]];
        yield 'element held twice' => [['gate' => ['add']]];
    }

    /**
     * @dataProvider brokenBranches
     */
    public function testTheRunGateRefusesFalseLanesThatCannotRun(array $branches): void
    {
        $this->expectException(FlowExecutionFailure::class);
        $this->expectExceptionMessage('Step "Box": this inner flow holds an element it cannot run');

        $this->runCanvas(self::withBranches(self::gatedCanvas(5), $branches));
    }

    public function testStepsThroughAFalseLaneFrameByFrame(): void
    {
        $steps = self::withBranches(self::gatedCanvas(5), ['gate' => ['minus']]);
        $r = $this->tick($steps, null);
        $r = $this->tick($steps, $r['blob']);
        $r = $this->tick($steps, $r['blob']); // enter the box
        $r = $this->tick($steps, $r['blob']); // gate: no match
        self::assertSame(['id' => 'minus', 'name' => 'Minus', 'type' => 'dwl_transform'], $r['response']['next']);
        $r = $this->tick($steps, $r['blob']); // minus
        self::assertSame(0, $r['response']['context']['n']);
        $r = $this->tick($steps, $r['blob']); // back to the caller
        self::assertSame('returned', $r['response']['transition']);
    }

    public function testForeachRunsTheInnerFlowPerElementWithLoopScopedVariables(): void
    {
        $steps = self::canvas('foreach', ['inner' => 'box', 'array' => 'items', 'item' => 'row'], ['acc'], [
            self::step('acc', 'dwl_transform', ['code' => 'total + row * (index + 1)', 'destination' => 'total'], 'Acc'),
        ]);

        $context = $this->executor->execute(
            $steps,
            self::links(),
            [],
            $this->root,
            null,
            ['flowUuid' => 'u', 'items' => [5, 7], 'total' => 0]
        );

        self::assertSame(19, $context['total']); // 5*1 + 7*2
        self::assertArrayNotHasKey('row', $context);
        self::assertArrayNotHasKey('index', $context);
        self::assertSame(['t', 'a', 'box', 'acc', 'call', 'b'], $this->executor->lastExecutedIds());
        self::assertSame(2, \count(array_keys($this->eventKinds(), 'inner-flow-start', true)));
        self::assertSame(2, \count(array_keys($this->eventKinds(), 'inner-flow-finish', true)));
    }

    public function testAnInnerFlowCanCallAnotherInnerFlowOfTheCanvas(): void
    {
        $steps = self::canvas(children: ['add', 'hop'], extra: [
            self::step('hop', 'sub_flow', ['inner' => 'box2'], 'Hop'),
            self::step('box2', 'inner_flow', ['children' => ['dbl']], 'Box 2'),
        ]);

        $context = $this->runCanvas($steps);

        self::assertSame(40, $context['result']); // 1 → +1 → (Box 2: ×2) = 4
        self::assertSame(['t', 'a', 'box', 'add', 'box2', 'dbl', 'hop', 'call', 'b'], $this->executor->lastExecutedIds());
    }

    public function testAFailingInnerElementIsTheStepReportedAsFailed(): void
    {
        $steps = self::canvas(children: ['add', 'bad'], extra: [
            self::step('bad', 'dwl_transform', ['code' => 'nope(', 'destination' => 'x'], 'Bad'),
        ]);

        try {
            $this->runCanvas($steps);
            self::fail('The failing inner element must abort the run.');
        } catch (FlowStepFailure $e) {
            self::assertSame('bad', $e->stepId);
            self::assertStringStartsWith('Step "Call": Step "Bad": ', $e->getMessage());
            self::assertSame(['t', 'a', 'box', 'add'], $e->executedIds);
        }
    }

    public function testAFailingForeachIterationNamesItAndTheInnerElement(): void
    {
        $steps = self::canvas('foreach', ['inner' => 'box', 'array' => 'items', 'item' => 'row'], ['bad'], [
            self::step('bad', 'dwl_transform', ['code' => 'nope(', 'destination' => 'x'], 'Bad'),
        ]);

        try {
            $this->executor->execute($steps, self::links(), [], $this->root, null, ['flowUuid' => 'u', 'items' => [1]]);
            self::fail('The failing iteration must abort the run.');
        } catch (FlowStepFailure $e) {
            self::assertSame('bad', $e->stepId);
            self::assertStringStartsWith('Step "Call" (iteration 0): Step "Bad": ', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: array<int, array<string, mixed>>, 1: string}>
     */
    public static function brokenCanvases(): iterable
    {
        yield 'missing inner flow' => [self::canvas(invokerConfig: ['inner' => 'nope']), 'Step "Call": the configured inner flow no longer exists.'];
        yield 'not an inner flow' => [self::canvas(invokerConfig: ['inner' => 'b']), 'Step "Call": the configured inner flow no longer exists.'];
        yield 'missing element' => [self::canvas(children: ['add', 'ghost']), 'Step "Box": this inner flow holds an element it cannot run (missing, a trigger, another inner flow or one held twice).'];
        yield 'a trigger inside' => [self::canvas(children: ['t']), 'Step "Box": this inner flow holds an element it cannot run (missing, a trigger, another inner flow or one held twice).'];
        yield 'an element held twice' => [
            self::canvas(extra: [self::step('box2', 'inner_flow', ['children' => ['add']], 'Box 2')]),
            'Step "Box 2": this inner flow holds an element it cannot run (missing, a trigger, another inner flow or one held twice).',
        ];
    }

    /**
     * @dataProvider brokenCanvases
     */
    public function testTheRunGateRefusesABrokenInnerFlowStructure(array $steps, string $message): void
    {
        $this->expectException(FlowExecutionFailure::class);
        $this->expectExceptionMessage($message);

        $this->runCanvas($steps);
    }

    public function testACircularInnerFlowCallIsRefused(): void
    {
        $steps = self::canvas(children: ['add', 'again'], extra: [self::step('again', 'sub_flow', ['inner' => 'box'], 'Again')]);

        try {
            $this->runCanvas($steps);
            self::fail('A box calling itself must fail.');
        } catch (FlowStepFailure $e) {
            self::assertSame('again', $e->stepId);
            self::assertStringContainsString('circular subflow call — "Box" is already running', $e->getMessage());
        }
    }

    public function testASubflowResolvesItsOwnInnerFlows(): void
    {
        $this->subflow(20, 'Enrich', [
            self::step('st', 'subflow', null, 'Enrich'),
            self::step('go', 'sub_flow', ['inner' => 'sbox'], 'Go'),
            self::step('sbox', 'inner_flow', ['children' => ['sadd']], 'Sub box'),
            self::step('sadd', 'dwl_transform', ['code' => 'n + 100', 'destination' => 'n'], 'Add 100'),
        ], [self::link('st', 'go')]);

        $context = $this->runCanvas(self::canvas(invokerConfig: ['subflow' => 20]));

        self::assertSame(1010, $context['result']);
        // The subflow's inner run lands in the SUBFLOW's trail (painted on its tab).
        self::assertSame([['flowId' => 20, 'flowName' => 'Enrich', 'executedIds' => ['st', 'sbox', 'sadd', 'go']]], $this->executor->subflowTrails());
    }

    // --- step-into debugging -----------------------------------------------------------------

    /**
     * @return array{blob: array<string, mixed>, response: array<string, mixed>}
     */
    private function tick(array $steps, ?array $blob, bool $stepInto = true, bool $runAll = false, bool $stepOut = false): array
    {
        return $this->executor->debugWalk($steps, self::links(), [], $blob, $this->root, $runAll, [], $stepInto, $stepOut);
    }

    public function testStepsIntoAnInnerFlowOnTheCallersCanvas(): void
    {
        $steps = self::canvas();
        $r = $this->tick($steps, null);           // t
        $r = $this->tick($steps, $r['blob']);     // a
        self::assertSame(['id' => 'call', 'name' => 'Call', 'type' => 'sub_flow'], $r['response']['next']);

        $r = $this->tick($steps, $r['blob']);     // enter the box
        self::assertSame('entered', $r['response']['transition']);
        self::assertSame(['id' => 'box', 'name' => 'Box', 'type' => 'inner_flow'], $r['response']['step']);
        self::assertSame(
            ['flowId' => 1, 'flowName' => 'Box', 'depth' => 1, 'canvasDepth' => 0, 'innerId' => 'box'],
            $r['response']['frame']
        );
        self::assertSame(['inner-flow-start', 'Main', ['inner' => 'Box']], $this->events[\count($this->events) - 2]);

        $r = $this->tick($steps, $r['blob']);     // add
        self::assertSame('add', $r['response']['step']['id']);
        self::assertSame(2, $r['response']['context']['n']);
        $r = $this->tick($steps, $r['blob']);     // dbl
        self::assertSame(4, $r['response']['context']['n']);

        $r = $this->tick($steps, $r['blob']);     // back to the caller
        self::assertSame('returned', $r['response']['transition']);
        self::assertContains(['inner-flow-finish', 'Main', []], $this->events);
        self::assertNotContains('subflow-finish', $this->eventKinds());
        self::assertSame('call', $r['response']['step']['id']);
        self::assertSame(['flowId' => 1, 'flowName' => 'Main', 'depth' => 0, 'canvasDepth' => 0, 'innerId' => null], $r['response']['frame']);

        $r = $this->tick($steps, $r['blob']);     // b
        self::assertTrue($r['response']['done']);
        self::assertSame(40, $r['response']['context']['result']);
    }

    public function testSteppingOverAnInnerFlowPaintsItsElementsInTheSameTick(): void
    {
        $steps = self::canvas();
        $r = $this->tick($steps, null);
        $r = $this->tick($steps, $r['blob']);

        $r = $this->tick($steps, $r['blob'], false); // step OVER the invoker

        self::assertNull($r['response']['transition']);
        self::assertSame(['box', 'add', 'dbl', 'call'], $r['response']['executedIds']);
        self::assertArrayNotHasKey('subflowTrails', $r['response']);
        self::assertSame(4, $r['response']['context']['n']);
    }

    public function testAForeachReentersTheInnerFrameAndRestoresLoopVariables(): void
    {
        $steps = self::canvas('foreach', ['inner' => 'box', 'array' => 'items', 'item' => 'row'], ['acc'], [
            self::step('acc', 'dwl_transform', ['code' => 'row * 10 + index', 'destination' => 'last'], 'Acc'),
        ]);
        $steps[1] = self::step('a', 'dwl_transform', ['code' => '[3, 4]', 'destination' => 'items'], 'Init');

        $r = $this->tick($steps, null);
        $r = $this->tick($steps, $r['blob']);
        $r = $this->tick($steps, $r['blob']);     // enter, iteration 0
        self::assertSame(0, $r['response']['iteration']);
        self::assertSame(3, $r['response']['context']['row']);
        $r = $this->tick($steps, $r['blob']);     // acc
        $r = $this->tick($steps, $r['blob']);     // re-enter, iteration 1
        self::assertSame('reentered', $r['response']['transition']);
        self::assertSame('box', $r['response']['frame']['innerId']);
        $r = $this->tick($steps, $r['blob']);     // acc
        $r = $this->tick($steps, $r['blob']);     // return
        self::assertSame('returned', $r['response']['transition']);
        self::assertSame(41, $r['response']['context']['last']);
        self::assertArrayNotHasKey('row', $r['response']['context']);
        self::assertArrayNotHasKey('index', $r['response']['context']);
    }

    public function testRunAllWalksInnerFlowsOverOnTheRootCanvas(): void
    {
        $steps = self::canvas();
        $r = $this->tick($steps, null);

        $r = $this->tick($steps, $r['blob'], true, true);

        self::assertTrue($r['response']['done']);
        self::assertSame(40, $r['response']['context']['result']);
        self::assertSame([], $r['response']['subflowTrails']);
    }

    public function testADebugStepFailingInsideAnInnerFrameIsPaintedThere(): void
    {
        $steps = self::canvas(children: ['bad'], extra: [
            self::step('bad', 'dwl_transform', ['code' => 'nope(', 'destination' => 'x'], 'Bad'),
        ]);
        $r = $this->tick($steps, null);
        $r = $this->tick($steps, $r['blob']);

        try {
            $this->tick($steps, $r['blob'], false); // stepping OVER the invoker runs the box atomically
            self::fail('The inner failure must surface.');
        } catch (FlowStepFailure $e) {
            self::assertSame('bad', $e->stepId);
            self::assertSame(['box'], $e->executedIds);
        }
    }

    public function testACircularInnerFrameIsRefused(): void
    {
        $steps = self::canvas(children: ['again'], extra: [self::step('again', 'sub_flow', ['inner' => 'box'], 'Again')]);
        $r = $this->tick($steps, null);
        $r = $this->tick($steps, $r['blob']);
        $r = $this->tick($steps, $r['blob']); // enter the box

        $this->expectException(FlowStepFailure::class);
        $this->expectExceptionMessage('circular subflow call — "Box" is already running');

        $this->tick($steps, $r['blob']); // "again" would enter the box a second time
    }
}
