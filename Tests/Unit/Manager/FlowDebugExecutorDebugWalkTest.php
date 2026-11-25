<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;

/**
 * FlowDebugExecutor::debugWalk() — the step-into debugger: one tick per call, frames for invoked
 * subflows, foreach iterations, step over/out and run-all.
 */
class FlowDebugExecutorDebugWalkTest extends FlowDebugExecutorTestCase
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
    private static function mainSteps(string $invoker = 'sub_flow', array $invokerConfig = ['subflow' => 20]): array
    {
        return [
            self::step('t', 'cron', null, 'Main'),
            self::step('a', 'dwl_transform', ['code' => '1', 'destination' => 'n'], 'Init'),
            self::step('call', $invoker, $invokerConfig, 'Call'),
            self::step('b', 'dwl_transform', ['code' => 'n * 10', 'destination' => 'result'], 'Finish'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function mainLinks(): array
    {
        return [self::link('t', 'a'), self::link('a', 'call'), self::link('call', 'b')];
    }

    /**
     * @return array{blob: array<string, mixed>, response: array<string, mixed>}
     */
    private function tick(?array $blob, bool $stepInto = true, bool $runAll = false, bool $stepOut = false, array $steps = [], array $runInfo = []): array
    {
        return $this->executor->debugWalk(
            $steps ?: self::mainSteps(),
            self::mainLinks(),
            [],
            $blob,
            $this->root,
            $runAll,
            $runInfo,
            $stepInto,
            $stepOut
        );
    }

    public function testStepsIntoASubflowNamedByAnExpression(): void
    {
        $steps = self::mainSteps('sub_flow', ['subflow' => "'En' ++ 'rich'", 'subflow_dwl' => true]);
        $blob = $this->tick(null, steps: $steps)['blob'];
        $blob = $this->tick($blob, steps: $steps)['blob'];

        $entered = $this->tick($blob, steps: $steps);

        self::assertSame('entered', $entered['response']['transition']);
        self::assertSame(['flowId' => 20, 'flowName' => 'Enrich', 'depth' => 1, 'canvasDepth' => 1, 'innerId' => null], $entered['response']['frame']);
    }

    public function testStepsIntoASubflowFrameByFrame(): void
    {
        $this->em->expects(self::exactly(12))->method('flush'); // start + finish stamp on every call

        $first = $this->tick(null, runInfo: ['trigger' => 'debug', 'user' => ['id' => 2]]);
        self::assertSame([
            'step' => ['id' => 't', 'name' => 'Main', 'type' => 'cron'],
            'index' => 0,
            'total' => 4,
            'done' => false,
            'transition' => null,
            'iteration' => null,
            'frame' => ['flowId' => 1, 'flowName' => 'Main', 'depth' => 0, 'canvasDepth' => 0, 'innerId' => null],
            'executedIds' => ['t'],
            'context' => ['flowUuid' => 'run-uuid'],
            'next' => ['id' => 'a', 'name' => 'Init', 'type' => 'dwl_transform'],
        ], $first['response']);
        self::assertSame(['flow-start', 'Main', ['trigger' => 'debug', 'user' => ['id' => 2]]], $this->events[0]);

        $second = $this->tick($first['blob']);
        self::assertSame(['id' => 'a', 'name' => 'Init', 'type' => 'dwl_transform'], $second['response']['step']);
        self::assertSame(['id' => 'call', 'name' => 'Call', 'type' => 'sub_flow'], $second['response']['next']);

        $entered = $this->tick($second['blob']);
        self::assertSame('entered', $entered['response']['transition']);
        self::assertSame(['id' => 'st', 'name' => 'Enrich', 'type' => 'subflow'], $entered['response']['step']);
        self::assertSame(['flowId' => 20, 'flowName' => 'Enrich', 'depth' => 1, 'canvasDepth' => 1, 'innerId' => null], $entered['response']['frame']);
        self::assertSame(0, $entered['response']['index']);
        self::assertSame(2, $entered['response']['total']);
        self::assertSame(['id' => 'sa', 'name' => 'Add', 'type' => 'dwl_transform'], $entered['response']['next']);

        $inside = $this->tick($entered['blob']);
        self::assertSame(['id' => 'sa', 'name' => 'Add', 'type' => 'dwl_transform'], $inside['response']['step']);
        self::assertSame(2, $inside['response']['context']['n']);
        self::assertNull($inside['response']['next']);

        $returned = $this->tick($inside['blob']);
        self::assertSame('returned', $returned['response']['transition']);
        self::assertSame(['id' => 'call', 'name' => 'Call', 'type' => 'sub_flow'], $returned['response']['step']);
        self::assertSame(0, $returned['response']['frame']['depth']);

        $last = $this->tick($returned['blob']);
        self::assertTrue($last['response']['done']);
        self::assertSame(20, $last['response']['context']['result']);
        self::assertNull($last['response']['next']);
        self::assertTrue($last['blob']['done']);

        self::assertSame([
            'flow-start', 'step', 'step', 'subflow-start', 'step', 'step', 'subflow-finish', 'step', 'step', 'flow-finish',
        ], $this->eventKinds());
        self::assertSame(['step', 'Main', ['name' => 'Call', 'type' => 'sub_flow']], $this->events[7]);
    }

    public function testSteppingIntoAQuietInvokerRecordsOnlyTheSubflowsOwnSteps(): void
    {
        $steps = self::mainSteps('sub_flow', ['subflow' => 20, 'log_event' => false]);
        $blob = null;
        $transitions = [];
        do {
            $result = $this->tick($blob, steps: $steps);
            $blob = $result['blob'];
            $transitions[] = $result['response']['transition'];
        } while (!$result['response']['done']);

        self::assertSame([null, null, 'entered', null, 'returned', null], $transitions);
        self::assertSame(['flow-start', 'step', 'step', 'step', 'step', 'flow-finish'], $this->eventKinds());
        self::assertSame(
            ['Main', 'Init', 'Add', 'Finish'],
            array_map(static fn (array $e): string => $e[2]['name'], \array_slice($this->events, 1, 4))
        );
    }

    public function testAQuietForeachDropsItsIterationBoundaries(): void
    {
        $steps = [
            self::step('t', 'cron', null, 'Main'),
            self::step('call', 'foreach', ['subflow' => 20, 'array' => 'items', 'item' => 'item', 'log_event' => false], 'Loop'),
        ];
        $blob = ['context' => ['flowUuid' => 'u', 'items' => [5, 6], 'n' => 0], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => [self::link('t', 'call')], 'index' => 1, 'callerStepId' => null,
        ]], 'done' => false];

        do {
            $result = $this->executor->debugWalk($steps, [], [], $blob, $this->root);
            $blob = $result['blob'];
        } while (!$result['response']['done']);

        self::assertSame(2, $blob['context']['n']);
        self::assertSame(['step', 'step', 'flow-finish'], $this->eventKinds());
    }

    public function testNothingIsLeftAfterTheRunIsDone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('There is no step left to execute.');

        $this->tick(['frames' => [], 'context' => [], 'done' => true]);
    }

    public function testAnInvalidFlowCannotStartADebugSession(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The flow has no trigger to debug.');

        $this->tick(null, steps: [self::step('a', 'logger', ['message' => 'x'])]);
    }

    public function testRunAllStepsOverSubflowsToTheEnd(): void
    {
        $result = $this->tick(null, runAll: true);

        self::assertTrue($result['response']['done']);
        self::assertSame(['flowUuid' => 'run-uuid', 'n' => 2, 'result' => 20], $result['response']['context']);
        // Everything the run did on the debugged flow's canvas (it used to report the last tick only).
        self::assertSame(['t', 'a', 'call', 'b'], $result['response']['executedIds']);
        self::assertSame(['t', 'a', 'call', 'b'], $result['response']['rootExecutedIds']);
        self::assertArrayNotHasKey('breakpoint', $result['response']);
        self::assertSame([['flowId' => 20, 'flowName' => 'Enrich', 'executedIds' => ['st', 'sa']]], $result['response']['subflowTrails']);
        self::assertSame([
            'flow-start', 'step', 'step', 'subflow-start', 'step', 'step', 'subflow-finish', 'step', 'step', 'flow-finish',
        ], $this->eventKinds());
    }

    public function testStepOverRunsTheInvokerAtomicallyAndReportsItsTrail(): void
    {
        $blob = $this->tick($this->tick(null)['blob'])['blob'];

        $over = $this->tick($blob, stepInto: false);

        self::assertNull($over['response']['transition']);
        self::assertSame(['id' => 'call', 'name' => 'Call', 'type' => 'sub_flow'], $over['response']['step']);
        self::assertSame([['flowId' => 20, 'flowName' => 'Enrich', 'executedIds' => ['st', 'sa']]], $over['response']['subflowTrails']);
        self::assertSame(2, $over['response']['context']['n']);
    }

    public function testStepOutFinishesTheCurrentFrame(): void
    {
        $blob = $this->tick($this->tick($this->tick(null)['blob'])['blob'])['blob']; // entered the subflow

        $out = $this->tick($blob, stepOut: true);

        self::assertSame('returned', $out['response']['transition']);
        self::assertSame(2, $out['response']['context']['n']);
        self::assertSame([20 => ['flowId' => 20, 'flowName' => 'Enrich', 'executedIds' => ['sa']]], array_column($out['response']['subflowTrails'], null, 'flowId'));
    }

    public function testStepOutAtTheRootIsAnOrdinaryTick(): void
    {
        $out = $this->tick(null, stepOut: true);

        self::assertSame('t', $out['response']['step']['id']);
        self::assertArrayNotHasKey('subflowTrails', $out['response']);
    }

    public function testForeachIteratesFrameByFrameAndRestoresLoopVariables(): void
    {
        $steps = [
            self::step('t', 'cron', null, 'Main'),
            self::step('call', 'foreach', ['subflow' => 20, 'array' => 'items', 'item' => 'item'], 'Loop'),
        ];
        $blob = ['context' => ['flowUuid' => 'u', 'items' => [5, 6], 'n' => 0, 'index' => 'outer'], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => [self::link('t', 'call')], 'index' => 1, 'callerStepId' => null,
        ]], 'done' => false];

        $entered = $this->executor->debugWalk($steps, [], [], $blob, $this->root);
        self::assertSame('entered', $entered['response']['transition']);
        self::assertSame(0, $entered['response']['iteration']);
        self::assertSame(5, $entered['response']['context']['item']);
        self::assertSame(0, $entered['response']['context']['index']);

        $body = $this->executor->debugWalk($steps, [], [], $entered['blob'], $this->root);
        $reentered = $this->executor->debugWalk($steps, [], [], $body['blob'], $this->root);
        self::assertSame('reentered', $reentered['response']['transition']);
        self::assertSame(1, $reentered['response']['iteration']);
        self::assertSame(6, $reentered['response']['context']['item']);

        $body = $this->executor->debugWalk($steps, [], [], $reentered['blob'], $this->root);
        $returned = $this->executor->debugWalk($steps, [], [], $body['blob'], $this->root);
        self::assertSame('returned', $returned['response']['transition']);
        self::assertTrue($returned['response']['done']);
        self::assertSame(['flowUuid' => 'u', 'items' => [5, 6], 'n' => 2, 'index' => 'outer'], $returned['response']['context']);
        self::assertSame([
            'subflow-start', 'step', 'step',
            'subflow-finish', 'subflow-start', 'step', 'step',
            'subflow-finish', 'step', 'flow-finish',
        ], $this->eventKinds());
        self::assertSame(['step', 'Main', ['name' => 'Loop', 'type' => 'foreach']], $this->events[\count($this->events) - 2]);
    }

    public function testANestedInvokerReturnsIntoItsCallersFrame(): void
    {
        $this->subflow(21, 'Outer', [
            self::step('ot', 'subflow', null, 'Outer'),
            self::step('inner', 'foreach', ['subflow' => 20, 'array' => 'items', 'item' => 'it'], 'Inner'),
            self::step('ob', 'dwl_transform', ['code' => 'n', 'destination' => 'seen'], 'After'),
        ], [self::link('ot', 'inner'), self::link('inner', 'ob')]);
        $steps = [self::step('t', 'cron', null, 'Main'), self::step('call', 'sub_flow', ['subflow' => 21], 'Call')];
        $blob = ['context' => ['flowUuid' => 'u', 'items' => [1], 'n' => 0, 'it' => 'kept'], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => [self::link('t', 'call')], 'index' => 1, 'callerStepId' => null,
        ]], 'done' => false];

        $transitions = [];
        do {
            $result = $this->executor->debugWalk($steps, [], [], $blob, $this->root);
            $blob = $result['blob'];
            $transitions[] = [$result['response']['transition'], $result['response']['step']['id'], $result['response']['frame']['depth']];
        } while (!$result['response']['done']);

        self::assertSame([
            ['entered', 'ot', 1],
            ['entered', 'st', 2],
            [null, 'sa', 2],
            ['returned', 'inner', 1],
            [null, 'ob', 1],
            ['returned', 'call', 0],
        ], $transitions);
        self::assertSame(['flowUuid' => 'u', 'items' => [1], 'n' => 1, 'it' => 'kept', 'seen' => 1], $blob['context']);
    }

    public function testAForeachOverNothingCompletesAtOnce(): void
    {
        $steps = [
            self::step('t', 'cron', null, 'Main'),
            self::step('call', 'foreach', ['subflow' => 20, 'array' => 'items', 'item' => 'item'], 'Loop'),
        ];
        $blob = ['context' => ['flowUuid' => 'u', 'items' => []], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => [self::link('t', 'call')], 'index' => 1, 'callerStepId' => null,
        ]], 'done' => false];

        $result = $this->executor->debugWalk($steps, [], [], $blob, $this->root);

        self::assertNull($result['response']['transition']);
        self::assertSame('call', $result['response']['step']['id']);
        self::assertTrue($result['response']['done']);
        self::assertSame(['step', 'flow-finish'], $this->eventKinds());
    }

    public function testAForeachOverNothingInsideASubflowKeepsGoing(): void
    {
        $this->subflow(22, 'Empty loop', [
            self::step('lt', 'subflow', null, 'Entry'),
            self::step('loop', 'foreach', ['subflow' => 20, 'array' => 'none', 'item' => 'x'], 'Loop'),
        ], [self::link('lt', 'loop')]);
        $steps = [self::step('t', 'cron', null, 'Main'), self::step('call', 'sub_flow', ['subflow' => 22], 'Call')];
        $blob = ['context' => ['flowUuid' => 'u', 'none' => []], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => [self::link('t', 'call')], 'index' => 1, 'callerStepId' => null,
        ]], 'done' => false];

        $entered = $this->executor->debugWalk($steps, [], [], $blob, $this->root);
        $loop = $this->executor->debugWalk($steps, [], [], $entered['blob'], $this->root);

        self::assertSame('loop', $loop['response']['step']['id']);
        self::assertFalse($loop['response']['done']);
        self::assertSame(1, $loop['response']['frame']['depth']);
    }

    public function testARootFrameRunOutWithoutADoneFlagFinishesTheRun(): void
    {
        $steps = [self::step('t', 'cron', null, 'Main')];
        $blob = ['context' => ['flowUuid' => 'u'], 'frames' => [[
            'kind' => 'flow', 'flowId' => null, 'flowName' => null, 'steps' => $steps,
            'links' => [], 'index' => 1, 'callerStepId' => null,
        ]], 'done' => false];

        $result = $this->executor->debugWalk($steps, [], [], $blob);

        self::assertTrue($result['response']['done']);
        self::assertSame('t', $result['response']['step']['id']);
        self::assertSame(['flow-finish'], $this->eventKinds());
    }

    public function testAChoiceExtendsTheWalkInsteadOfEndingIt(): void
    {
        $steps = [
            self::step('t', 'cron', null, 'Main'),
            self::step('c', 'choice', ['expression' => 'true'], 'Choose'),
            self::step('y', 'dwl_transform', ['code' => '1', 'destination' => 'y'], 'Yes'),
        ];
        $links = [self::link('t', 'c'), self::link('c', 'y')];

        $first = $this->executor->debugWalk($steps, $links, [], null);
        $choice = $this->executor->debugWalk($steps, $links, [], $first['blob']);

        self::assertFalse($choice['response']['done']);
        self::assertSame(['c' => true], $choice['response']['context']['choiceResults']);
        self::assertSame(['id' => 'y', 'name' => 'Yes', 'type' => 'dwl_transform'], $choice['response']['next']);
    }

    public function testAFailingTickClosesEveryOpenFrame(): void
    {
        $this->subflow(23, 'Broken', [
            self::step('bt', 'subflow', null, 'Entry'),
            self::step('bad', 'dwl_transform', ['code' => 'nope', 'destination' => 'x'], 'Bad'),
        ], [self::link('bt', 'bad')]);
        $steps = self::mainSteps('sub_flow', ['subflow' => 23]);
        $blob = ['context' => ['flowUuid' => 'u'], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => self::mainLinks(), 'index' => 2, 'callerStepId' => null,
        ]], 'done' => false];
        $entered = $this->tick($blob, steps: $steps);
        $this->events = [];

        try {
            $this->tick($entered['blob'], steps: $steps);
            self::fail('Expected the step to fail.');
        } catch (FlowStepFailure $e) {
            self::assertSame("Step \"Bad\": Undefined variable: 'nope' at line 1", $e->getMessage());
            self::assertSame('bad', $e->stepId);
            self::assertSame([], $e->executedIds);
        }
        self::assertSame([
            ['flow-exception', 'Broken', ['message' => "Step \"Bad\": Undefined variable: 'nope' at line 1"]],
            ['flow-exception', 'Main', ['message' => "Step \"Bad\": Undefined variable: 'nope' at line 1"]],
        ], $this->events);
    }

    public function testANonRuntimeStepErrorPaintsTheFailingStepToo(): void
    {
        $this->logger->method('info')->willThrowException(new \TypeError('bad argument'));
        // Ids of the main design, so its links (t → a) reach the logger.
        $steps = [self::step('t', 'cron', null, 'Main'), self::step('a', 'logger', ['message' => 'hi'], 'Log')];
        $started = $this->tick(null, steps: $steps);
        $this->events = [];

        try {
            $this->tick($started['blob'], steps: $steps);
            self::fail('Expected the step to fail.');
        } catch (FlowStepFailure $e) {
            self::assertSame('Step "Log": bad argument', $e->getMessage());
            self::assertSame('a', $e->stepId);
        }
        self::assertSame([['flow-exception', 'Main', ['message' => 'Step "Log": bad argument']]], $this->events);
    }

    public function testAnInvokerFailingToEnterOnANonRuntimeErrorNamesItself(): void
    {
        $broken = $this->createMock(OntologyFlow::class);
        $broken->method('getType')->willReturn(OntologyFlow::TYPE_SUBFLOW);
        $broken->method('isEnabled')->willReturn(true);
        $broken->method('getDesign')->willThrowException(new \TypeError('unreadable design'));
        $this->flows[20] = $broken;
        $steps = self::mainSteps();
        $blob = ['context' => ['flowUuid' => 'u'], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => self::mainLinks(), 'index' => 2, 'callerStepId' => null,
        ]], 'done' => false];

        try {
            $this->tick($blob, steps: $steps);
            self::fail('Expected the invoker to fail.');
        } catch (FlowStepFailure $e) {
            self::assertSame('Step "Call": unreadable design', $e->getMessage());
            self::assertSame('call', $e->stepId);
        }
        self::assertSame([['flow-exception', 'Main', ['message' => 'Step "Call": unreadable design']]], $this->events);
    }

    /**
     * @dataProvider unenterableInvokers
     */
    public function testInvokersThatCannotBeEntered(string $type, array $config, string $message, array $context = [], int $openFrames = 1): void
    {
        $this->subflow(24, 'Bad design', [['id' => 'x']]);
        $steps = self::mainSteps($type, $config);
        $frames = [];
        for ($i = 0; $i < $openFrames; $i++) {
            $frames[] = [
                'kind' => $i === 0 ? 'flow' : 'sub', 'flowId' => $i === 0 ? 1 : 20, 'flowName' => 'F' . $i, 'steps' => $steps,
                'links' => self::mainLinks(), 'index' => 2, 'callerStepId' => null,
            ];
        }
        $blob = ['context' => ['flowUuid' => 'u'] + $context, 'frames' => $frames, 'done' => false];

        try {
            $this->tick($blob, steps: $steps);
            self::fail('Expected the invoker to be refused.');
        } catch (FlowStepFailure $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame('call', $e->stepId);
        }
        self::assertCount($openFrames, $this->events);
        self::assertSame('flow-exception', $this->events[0][0]);
    }

    public static function unenterableInvokers(): array
    {
        return [
            'too deep' => ['sub_flow', ['subflow' => 20], 'Step "Call": subflow calls nested deeper than 10 levels.', [], 10],
            'circular' => ['sub_flow', ['subflow' => 20], 'Step "Call": circular subflow call — "Enrich" is already running in this execution.', [], 2],
            'missing subflow' => ['sub_flow', ['subflow' => 99], 'Step "Call": the configured subflow no longer exists.'],
            'unreadable design' => ['sub_flow', ['subflow' => 24], 'Step "Call": the subflow design is unreadable.'],
            'foreach unconfigured' => ['foreach', ['subflow' => 20, 'array' => ' '], 'Step "Call" is not configured.'],
            'foreach undefined' => ['foreach', ['subflow' => 20, 'array' => 'rows', 'item' => 'r'], 'Step "Call": the variable "rows" is not defined.'],
            'foreach over an object' => ['foreach', ['subflow' => 20, 'array' => 'rows', 'item' => 'r'], 'Step "Call": the variable "rows" must hold an array, got array.', ['rows' => ['a' => 1]]],
            'foreach over text' => ['foreach', ['subflow' => 20, 'array' => 'rows', 'item' => 'r'], 'Step "Call": the variable "rows" must hold an array, got string.', ['rows' => 'x']],
        ];
    }

    public function testEnteringASubflowNormalizesItsDesign(): void
    {
        $this->subflow(25, 'Sparse', [
            ['id' => 'sp', 'type' => 'subflow'],
            ['id' => 'sq', 'type' => 'dwl_transform', 'config' => ['code' => '7', 'destination' => 'seven']],
        ], [['from' => 'sp', 'to' => 'sq'], 'junk', ['from' => 'sp']]);
        $steps = self::mainSteps('sub_flow', ['subflow' => 25]);
        $blob = ['context' => ['flowUuid' => 'u'], 'frames' => [[
            'kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps,
            'links' => self::mainLinks(), 'index' => 2, 'callerStepId' => null,
        ]], 'done' => false];

        $entered = $this->tick($blob, steps: $steps);
        $inside = $this->tick($entered['blob'], steps: $steps);

        self::assertSame(['id' => 'sp', 'name' => 'sp', 'type' => 'subflow'], $entered['response']['step']);
        self::assertSame(['id' => 'sq', 'name' => 'sq', 'type' => 'dwl_transform'], $inside['response']['step']);
        self::assertSame(7, $inside['response']['context']['seven']);
    }

    public function testAnUnknownCallerStepStillReturns(): void
    {
        $steps = [self::step('t', 'cron', null, 'Main')];
        $blob = ['context' => ['flowUuid' => 'u'], 'frames' => [
            ['kind' => 'flow', 'flowId' => 1, 'flowName' => 'Main', 'steps' => $steps, 'links' => [], 'index' => 1, 'callerStepId' => null],
            ['kind' => 'sub', 'flowId' => 20, 'flowName' => 'Enrich', 'steps' => [self::step('st', 'subflow')], 'links' => [], 'index' => 1, 'callerStepId' => 'gone'],
        ], 'done' => false];

        $result = $this->executor->debugWalk($steps, [], [], $blob, $this->root);

        self::assertSame(['id' => 'gone', 'name' => 'gone', 'type' => 'sub_flow'], $result['response']['step']);
        self::assertSame('returned', $result['response']['transition']);
        self::assertTrue($result['response']['done']);
    }

    public function testTheStampsReleaseTheFlowEvenWhenATickFails(): void
    {
        $steps = [self::step('t', 'cron', null, 'Main'), self::step('x', 'dwl_transform', ['code' => 'nope', 'destination' => 'x'], 'X')];
        $first = $this->executor->debugWalk($steps, [self::link('t', 'x')], [], null, $this->root);
        $this->root->setLastFinished(null);

        try {
            $this->executor->debugWalk($steps, [self::link('t', 'x')], [], $first['blob'], $this->root);
        } catch (FlowStepFailure) {
            // expected
        }

        self::assertNotNull($this->root->getLastFinished());
    }
}
