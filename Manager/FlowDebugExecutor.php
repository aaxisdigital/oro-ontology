<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyHttpRequestTopic;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorRequestFailure;
use Aaxis\Bundle\OntologyBundle\Exception\FlowExecutionFailure;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes a flow definition in DEBUG mode: walks the links breadth-first from the trigger and
 * accumulates an output context keyed by the steps' destinations, which the editor shows as JSON.
 *
 * Per-type behaviour (grows as step types gain execution semantics):
 *  - entity_change trigger: seeds the context with the payload the user provided (`payload` key);
 *  - choice: evaluates its DWL expression against the context — truthy continues on the GREEN
 *    output (port 0), falsy on the RED one (port 1; optional — no link just ends the flow). The
 *    verdict is recorded in the context under the reserved `choiceResults` key (step id => bool),
 *    which is also what re-derives the already-walked path in step-through debug;
 *  - entity_read: real data via {@see OntologyDataApiManager} (`all` = EVERY record — flow reads
 *    are not page-capped — optionally ordered by one attribute and/or limited by the step's own
 *    config; `by_id` = one record's payload — a MISSING record yields null, not an error;
 *    `by_attribute` = the LIST of records whose attribute equals the value, [] when none match).
 *    Internal-system entities read from the OroCommerce entity itself ({@see OroEntityReader});
 *  - invoke ("HTTP Request"): the rest_api connector call executes for real through
 *    {@see RestConnectorClient} (URL from the connector's server/port + the step's path;
 *    connector headers + auth headers; auth=oauth attaches an access_token from the token path,
 *    REUSED across calls while valid; the step's operation/body are honoured). With `async: true`
 *    the step has NO destination: the request is queued ({@see OntologyHttpRequestTopic}) and the
 *    flow moves on at once — the consumer sends it and discards the response;
 *  - dwl_transform: runs the step's DataWeave script with the whole context as variables (also
 *    reachable as one `context` object, e.g. context["flowUuid"]);
 *  - entity_write: performs the real upsert SYNCHRONOUSLY (same validation and PG function as
 *    the Data View "Add Data", but no queue), stamped with the flow being debugged (Manual only
 *    when the flow was never saved) and the execution uuid — the receipt carries the actual
 *    outcome ({uuid, count, upsert, changedIds}) and the event row completes on the spot;
 *  - file_read/file_write/file_list/file_delete/file_rename: the file-based connector operations,
 *    all real via {@see FileConnectorTransfer} (DWL-capable paths; I/O failures come back as
 *    `{isError: true, ...}` payloads instead of aborting the run);
 *  - everything else: no-op pass-through for now. (The legacy generic reader/writer types were
 *    REMOVED — v1_10's ConvertLegacyReaderWriterSteps rewrote stored flows to the types above.)
 *
 * Every TOP-LEVEL execution mints ONE uuid up front, seeded into the context as `flowUuid`: all
 * writes of the run share it, so their events/records group under a single identity. Sub-flows
 * never mint their own — their caller passes its uuid down (execute()'s $executionUuid).
 */
class FlowDebugExecutor
{
    /** @var array<int, string> step ids the LAST run executed successfully, in order */
    private array $lastExecutedIds = [];

    /** @var array<int, int> flow ids currently on the CALL SUBFLOW stack (cycle/depth guard) */
    private array $subflowStack = [];

    /**
     * The nested subflow trails of the CURRENT top-level run: [{flowId, flowName, executedIds}],
     * merged per flow (a foreach union-merges its iterations). The debug endpoints ship these so
     * the editor can VISIT the subflow tabs and paint what ran inside them.
     */
    private array $subflowTrails = [];

    private const int TIMEOUT = 30;

    /** The step types with execution semantics (triggers and placeholders are no-ops). */
    private const array EXECUTABLE_STEP_TYPES = [
        'dwl_transform', 'entity_read', 'entity_write', 'invoke', 'sql_query', 'sub_flow', 'foreach', 'logger',
        'event', 'ms_teams', 'invoke_php', 'file_read', 'file_write', 'file_list', 'file_delete', 'file_rename',
    ];

    /** The executable types WITHOUT a destination: they act on the context or the outside world. */
    private const array DESTINATIONLESS_STEP_TYPES = ['sub_flow', 'foreach', 'logger', 'event', 'ms_teams'];

    private const string STEP_DETAIL = 'Step "%s": %s';
    private const string NOT_CONFIGURED = 'Step "%s" is not configured.';
    private const string VARIABLE_UNDEFINED = 'Step "%s": the variable "%s" is not defined.';
    private const string TOO_DEEP = 'Step "%s": subflow calls nested deeper than 10 levels.';
    private const string CIRCULAR = 'Step "%s": circular subflow call — "%s" is already running in this execution.';

    public function __construct(
        private readonly OntologyDataApiManager $dataApi,
        private readonly ManagerRegistry $doctrine,
        private readonly HttpClientInterface $httpClient,
        private readonly DwlTransformer $dwl,
        private readonly FileConnectorTransfer $files,
        private readonly FlowStepValidator $stepValidator,
        private readonly DatabaseQueryRunner $database,
        private readonly LoggerInterface $logger,
        private readonly PhpMethodInvoker $phpInvoker,
        private readonly OntologyFlowEventEmitter $flowEvents,
        private readonly RestConnectorClient $rest,
        private readonly MessageProducerInterface $producer,
    ) {
    }

    /**
     * The RUN gate: incomplete/invalid steps are SAVEABLE (the editor marks them red), but a flow
     * carrying any must not execute — not from the UI, not from a trigger. Sitting in the executor,
     * the gate covers every entry point (debug, Run Now, the scheduler, future triggers) at once.
     *
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     *
     * @throws FlowExecutionFailure naming the first problem
     */
    private function assertRunnable(array $steps): void
    {
        $problems = $this->stepValidator->validate($steps);
        if ($problems !== []) {
            throw new FlowExecutionFailure($problems[0]);
        }
    }

    /**
     * The flow-start payload: how the run was triggered (debug | schedule | endpoint | subflow | …)
     * and — for debug/endpoint runs with credentials — who.
     *
     * @param array<string, mixed> $runInfo
     *
     * @return array<string, mixed>
     */
    private static function startPayload(array $runInfo): array
    {
        return array_filter([
            'trigger' => (string) ($runInfo['trigger'] ?? 'debug'),
            'user' => \is_array($runInfo['user'] ?? null) ? $runInfo['user'] : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     * @param array<string, mixed> $input trigger input (entity_change: system/entity/payload)
     * @param OntologyFlow|null    $flow  the flow being debugged — writers stamp their upserts with
     *                                    it; null (a never-saved flow) falls back to the Manual flow
     * @param string|null          $executionUuid the CALLER's execution uuid — sub-flows never mint
     *                                            their own, they inherit it; null (a top-level run)
     *                                            mints a fresh one
     *
     * @return array<string, mixed> the accumulated output context
     *
     * @throws \RuntimeException with a user-readable message when a step cannot execute
     *                           ({@see FlowStepFailure} for a failing step)
     */
    public function execute(array $steps, array $links, array $input, ?OntologyFlow $flow = null, ?string $executionUuid = null, ?array $seedContext = null, array $runInfo = []): array
    {
        $this->assertRunnable($steps);
        $order = $this->executionOrder($steps, $links);
        $this->touchLastExecuted($flow);

        // One uuid identifies the WHOLE execution: every writer stamps its upsert with it (all
        // events/records of a run group under it), and steps can read it from the context as
        // the flowUuid variable (a valid DWL identifier).
        // Minted only for a top-level run: a sub-flow inherits its caller's uuid via the param.
        $executionUuid ??= $this->dataApi->generateUuid();

        if ($this->subflowStack === []) {
            $this->subflowTrails = []; // a new TOP-LEVEL run starts a fresh visit list
        }
        // flow-start opens the event trail (subflow-start for a NESTED run): how the run was
        // triggered (debug | schedule | endpoint | subflow | …) and — for debug/endpoint runs
        // with credentials — who.
        $isNested = ($runInfo['trigger'] ?? '') === 'subflow';
        // A QUIET run was invoked by a sub_flow/foreach whose "Log event" switch is off: its
        // subflow-start/-finish and its trigger's step row are dropped (failures still record).
        $quiet = $isNested && ($runInfo['quiet'] ?? false) === true;
        if (!$quiet) {
            $this->flowEvents->emit($flow, $executionUuid, $isNested ? 'subflow-start' : 'flow-start', self::startPayload($runInfo));
        }

        // A CALL SUBFLOW run seeds the CALLER's context — the subflow reads and extends it, and
        // the caller continues with the result (shared-context semantics).
        $context = $seedContext ?? $this->initialContext($order[0], $input, $executionUuid);
        $this->lastExecutedIds = [];
        try {
            // The order is re-derived after every choice: its verdict (just recorded in the
            // context) extends the walk with the branch it picked — or ends it, when the picked
            // port has no link.
            for ($cursor = 0; $cursor < \count($order); $cursor++) {
                $step = $order[$cursor];
                $this->executeStepTracked($step, $context, $flow, $executionUuid, $quiet);
                if ($step['type'] === 'choice') {
                    $order = $this->executionOrder($steps, $links, self::choiceResults($context));
                }
            }
        } catch (\Throwable $e) {
            // The run failed: the trail closes with flow-exception instead of flow-finish.
            $this->flowEvents->emit($flow, $executionUuid, 'flow-exception', ['message' => $e->getMessage()]);
            throw $e;
        } finally {
            // finally, not a happy-path call: a failed run must still release the "running" state
            // or the flow would be blocked from ever being scheduled again.
            $this->touchLastFinished($flow);
        }
        if (!$quiet) {
            $this->flowEvents->emit($flow, $executionUuid, $isNested ? 'subflow-finish' : 'flow-finish');
        }

        return $context;
    }

    /**
     * The ids of the steps the LAST execute()/executeFrom() call ran successfully, in order — what
     * the editor paints amber. On failure the trail travels inside {@see FlowStepFailure} too.
     *
     * @return array<int, string>
     */
    public function lastExecutedIds(): array
    {
        return $this->lastExecutedIds;
    }

    /**
     * Runs one step, keeping the executed trail: a success appends the step's id, a failure wraps
     * the error into a {@see FlowStepFailure} carrying the failing id + the trail so far.
     *
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $context
     */
    private function executeStepTracked(array $step, array &$context, ?OntologyFlow $flow, string $executionUuid, bool $quietRun = false): void
    {
        try {
            $this->executeStep($step, $context, $flow, $executionUuid);
        } catch (\RuntimeException $e) {
            throw new FlowStepFailure($e->getMessage(), $step['id'], $this->lastExecutedIds, $e);
        }
        $this->lastExecutedIds[] = $step['id'];
        // Every executed step leaves one event — the run's step-by-step trail — unless its
        // "Log event" switch is off.
        if (self::logsEvents($step, $quietRun)) {
            $this->flowEvents->emit($flow, $executionUuid, 'step', ['name' => $step['name'], 'type' => $step['type']]);
        }
    }

    /**
     * Whether an executed step records its events: the settings popup's "Log event" switch
     * (config `log_event`, absent = on), offered on every non-trigger type for stable flows that
     * would otherwise flood the Events page. It covers the step's own `step` row, an Entity
     * Write's `data-upsert` and — for sub_flow/foreach — the subflow-start/-finish of the runs it
     * invokes. Failures (flow-exception) are ALWAYS recorded, and an "Event" step's log-message is
     * its purpose, not a trail row, so it stays. Triggers have no switch: theirs follows the run
     * (dropped only in a QUIET nested run).
     *
     * @param array<string, mixed> $step
     */
    private static function logsEvents(array $step, bool $quietRun = false): bool
    {
        if (\in_array($step['type'] ?? null, OntologyFlow::TRIGGER_STEP_TYPES, true)) {
            return !$quietRun;
        }

        return self::configLogsEvents($step['config'] ?? null);
    }

    /** The `log_event` switch of a step config (absent or not a config = on). */
    private static function configLogsEvents(mixed $config): bool
    {
        return !\is_array($config) || ($config['log_event'] ?? true) !== false;
    }

    /**
     * STEP-INTO debug: executes exactly ONE meaningful tick of the flow, descending INTO invoked
     * subflows frame by frame — the editor's Next button drives it. The whole cursor lives in the
     * $blob (stored server-side between calls): a STACK of frames (root flow at the bottom;
     * sub_flow pushes one frame, foreach pushes one frame per run and re-enters per iteration)
     * plus the SHARED context.
     *
     * One tick is ONE of:
     *  - executing the current frame's next step (transition null);
     *  - ENTERING a subflow ('entered': the target's trigger is the executed step, nothing ran);
     *  - RE-ENTERING a foreach subflow for the next iteration ('reentered': the editor clears the
     *    subflow canvas and marks the trigger again);
     *  - RETURNING to the caller ('returned': the caller's sub_flow/foreach step is the executed
     *    step — it is only now complete).
     *
     * With $runAll the ticks loop to the end in one call (per-subflow trails are aggregated for
     * the editor's visit animation instead of transitions).
     *
     * @return array{blob: array<string, mixed>, response: array<string, mixed>}
     *
     * @throws FlowStepFailure when a step fails (the response fields ride on the exception via
     *                         its public properties; flow-exception events are emitted here)
     */
    public function debugWalk(array $steps, array $links, array $input, ?array $blob, ?OntologyFlow $flow = null, bool $runAll = false, array $runInfo = [], bool $stepInto = true, bool $stepOut = false): array
    {
        $this->touchLastExecuted($flow);
        try {
            if ($blob === null || !\is_array($blob['frames'] ?? null)) {
                $blob = $this->startDebugWalk($steps, $links, $input, $flow, $runInfo);
            }
            if (($blob['done'] ?? false) === true) {
                throw new FlowExecutionFailure('There is no step left to execute.');
            }

            $response = $stepOut && \count($blob['frames']) > 1
                ? $this->debugStepOut($blob)
                : $this->debugStep($blob, $runAll, $stepInto);
            unset($response['rootExecuted'], $response['_overTrails']);
            $response['context'] = $blob['context'];
            // What the NEXT tick would execute — the editor shows "Step into" when it is a
            // subflow invoker (null when the next tick is a return/re-enter or the run is done).
            $response['next'] = $this->upcomingStep($blob);

            return ['blob' => $blob, 'response' => $response];
        } finally {
            // Each call returns control to the user — stamping every call keeps a paused debug
            // session from looking like a run in progress and blocking the scheduler.
            $this->touchLastFinished($flow);
        }
    }

    /**
     * A fresh debug session: the root frame over the whole flow, the seeded context, flow-start.
     *
     * @return array<string, mixed> the new blob
     */
    private function startDebugWalk(array $steps, array $links, array $input, ?OntologyFlow $flow, array $runInfo): array
    {
        $this->assertRunnable($steps);
        $order = $this->executionOrder($steps, $links);
        $executionUuid = $this->dataApi->generateUuid();
        $blob = [
            'context' => $this->initialContext($order[0], $input, $executionUuid),
            'frames' => [[
                'kind' => 'flow',
                'flowId' => $flow?->getId(),
                'flowName' => $flow?->getName(),
                'steps' => $steps,
                'links' => $links,
                'index' => 0,
                'callerStepId' => null,
            ]],
            'done' => false,
        ];
        $this->flowEvents->emit($flow, $executionUuid, 'flow-start', self::startPayload($runInfo));

        return $blob;
    }

    /**
     * STEP OUT: run the current frame to completion — remaining foreach iterations included —
     * stopping the moment it pops back to the caller ('returned').
     *
     * @param array<string, mixed> $blob
     *
     * @return array<string, mixed>
     */
    private function debugStepOut(array &$blob): array
    {
        $trails = [];
        $startFrames = \count($blob['frames']);
        do {
            $response = $this->debugTick($blob, $trails, false);
        } while (!$blob['done'] && \count($blob['frames']) >= $startFrames);
        $response['subflowTrails'] = array_values($trails);

        return $response;
    }

    /**
     * One tick — or, with $runAll, ticks to the end of the run.
     *
     * @param array<string, mixed> $blob
     *
     * @return array<string, mixed>
     */
    private function debugStep(array &$blob, bool $runAll, bool $stepInto): array
    {
        $trails = [];
        do {
            // Run all steps OVER subflows (atomic — fewer ticks, identical events/trails);
            // a single tick honors the caller's choice: step INTO or OVER the invoker.
            $response = $this->debugTick($blob, $trails, !$runAll && $stepInto);
        } while ($runAll && !$blob['done'] && \count($blob['frames'] ?? []) > 0);

        if ($runAll) {
            $response['subflowTrails'] = array_values($trails);
            $response['executedIds'] = $response['rootExecuted'] ?? [];
        } elseif (($response['_overTrails'] ?? []) !== []) {
            // A stepped-OVER invoker: its nested trails feed the editor's visit animation.
            $response['subflowTrails'] = $response['_overTrails'];
        }

        return $response;
    }

    /**
     * The step the next tick would execute in the innermost frame — null when the next tick is a
     * return/re-enter or the run is done.
     *
     * @param array<string, mixed> $blob
     *
     * @return array{id: string, name: string, type: string}|null
     */
    private function upcomingStep(array $blob): ?array
    {
        if ($blob['done'] || \count($blob['frames'] ?? []) === 0) {
            return null;
        }
        $top = $blob['frames'][\count($blob['frames']) - 1];
        $upcoming = $this->executionOrder($top['steps'], $top['links'], self::choiceResults($blob['context']))[$top['index']] ?? null;

        return $upcoming === null ? null : ['id' => $upcoming['id'], 'name' => $upcoming['name'], 'type' => $upcoming['type']];
    }

    /**
     * One tick of {@see debugWalk}. Mutates $blob (frames/context/done) and appends per-subflow
     * executed ids into $trails (keyed by flow id — the runAll visit animation).
     *
     * @param array<string, mixed>            $blob
     * @param array<int, array<string,mixed>> $trails
     *
     * @return array<string, mixed> the tick's response fields
     */
    private function debugTick(array &$blob, array &$trails, bool $stepInto): array
    {
        $context = &$blob['context'];
        $frames = &$blob['frames'];
        $frame = &$frames[\count($frames) - 1];
        $executionUuid = (string) ($context['flowUuid'] ?? '');
        $frameFlow = $frame['flowId'] !== null
            ? $this->doctrine->getRepository(OntologyFlow::class)->find((int) $frame['flowId'])
            : null;

        $order = $this->executionOrder($frame['steps'], $frame['links'], self::choiceResults($context));
        $total = \count($order);

        // Frame exhausted: return to the caller (or finish an iteration / the whole run).
        if ($frame['index'] >= $total) {
            return $this->debugLeaveFrame($blob, $trails, $order);
        }

        $step = $order[$frame['index']];

        // A subflow invoker DESCENDS when stepping INTO; stepping OVER falls through to the
        // atomic arm below (callSubflow/foreachSubflow — events and trails as a full run).
        if ($stepInto && \in_array($step['type'], ['sub_flow', 'foreach'], true)) {
            return $this->debugEnterFrame($blob, $step, $executionUuid);
        }

        // A plain step (or a stepped-OVER invoker): execute it against the shared context.
        $this->lastExecutedIds = [];
        if ($this->subflowStack === []) {
            $this->subflowTrails = []; // per-tick: a stepped-over invoker reports ITS trails
        }
        try {
            $this->executeStep($step, $context, $frameFlow, $executionUuid);
        } catch (\RuntimeException $e) {
            $this->emitDebugException($blob, $e->getMessage(), $executionUuid);
            throw new FlowStepFailure($e->getMessage(), $step['id'], [], $e);
        }
        $tickOverTrails = array_values($this->subflowTrails);
        foreach ($this->subflowTrails as $trail) {
            if (!isset($trails[$trail['flowId']])) {
                $trails[$trail['flowId']] = $trail;
            } else {
                $trails[$trail['flowId']]['executedIds'] = array_values(array_unique(array_merge(
                    $trails[$trail['flowId']]['executedIds'],
                    $trail['executedIds']
                )));
            }
        }
        if (self::logsEvents($step, (bool) ($frame['quiet'] ?? false))) {
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'step', [
                'name' => $step['name'],
                'type' => $step['type'],
            ]);
        }
        $frame['index']++;
        if ($frame['kind'] !== 'flow') {
            $this->recordDebugTrail($trails, $frame, [$step['id']]);
        }

        // A CHOICE extends the walk with the branch its verdict just picked (the pre-verdict
        // order ENDED at the choice) — re-derive before judging whether the frame is done, or a
        // decided choice would read as the end of the run.
        if ($step['type'] === 'choice') {
            $order = $this->executionOrder($frame['steps'], $frame['links'], self::choiceResults($context));
            $total = \count($order);
        }

        $done = \count($frames) === 1 && $frame['index'] >= $total;
        if ($done) {
            $blob['done'] = true;
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'flow-finish');
        }

        return $this->debugResponse($blob, $step, $order, null) + [
            'rootExecuted' => $frame['kind'] === 'flow' ? [$step['id']] : [],
            // Only a stepped-OVER invoker carries trails on ITS OWN tick (the visit animation);
            // in-frame walk bookkeeping must not — it made the editor bounce home every step.
            '_overTrails' => $tickOverTrails,
        ];
    }

    /**
     * Push a frame for the sub_flow/foreach step the cursor stands on; the tick "executes" the
     * subflow's TRIGGER (transition 'entered').
     *
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $step
     */
    private function debugEnterFrame(array &$blob, array $step, string $executionUuid): array
    {
        $config = \is_array($step['config']) ? $step['config'] : [];

        try {
            [$frame, $subOrder] = $this->openSubflowFrame($blob['frames'], $step, $config);
            if ($step['type'] === 'foreach') {
                [$list, $itemVar] = $this->loopItems($step['name'], $config, $blob['context']);
                if ($list === []) {
                    // Zero iterations: the loop step simply completes — no frame to enter.
                    return $this->debugCompleteEmptyLoop($blob, $step, $executionUuid);
                }
                $frame['list'] = array_values($list);
                $frame['iteration'] = 0;
                $frame += self::loopScope($itemVar, $blob['context']);
                $blob['context'][$itemVar] = $frame['list'][0];
                $blob['context']['index'] = 0;
            }
        } catch (\RuntimeException $e) {
            $this->emitDebugException($blob, $e->getMessage(), $executionUuid);
            throw new FlowStepFailure($e->getMessage(), $step['id'], [], $e);
        }

        $blob['frames'][] = $frame;
        $trigger = $subOrder[0];
        if (!$frame['quiet']) {
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'subflow-start', ['trigger' => 'subflow']);
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'step', [
                'name' => $trigger['name'],
                'type' => $trigger['type'],
            ]);
        }

        return $this->debugResponse($blob, $trigger, $subOrder, 'entered') + ['rootExecuted' => []];
    }

    /**
     * The frame a sub_flow/foreach step opens (its trigger already consumed) and the subflow's
     * order — refused when too deep, circular, or the subflow is unusable/unrunnable.
     *
     * @param array<int, array<string, mixed>> $frames the open frames
     * @param array<string, mixed>             $step
     * @param array<string, mixed>             $config
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function openSubflowFrame(array $frames, array $step, array $config): array
    {
        if (\count($frames) >= 10) {
            throw new FlowExecutionFailure(sprintf(self::TOO_DEEP, $step['name']));
        }
        [$id, $target, $subStepsRaw, $subLinksRaw] = $this->resolveSubflowTarget($step['name'], $config);
        foreach ($frames as $open) {
            if ((int) ($open['flowId'] ?? 0) === $id && $open['kind'] !== 'flow') {
                throw new FlowExecutionFailure(sprintf(self::CIRCULAR, $step['name'], (string) $target->getName()));
            }
        }
        $subSteps = $this->normalizeDesignSteps($subStepsRaw, $step['name']);
        $subLinks = $this->normalizeDesignLinks($subLinksRaw);
        $this->assertRunnable($subSteps);
        $subOrder = $this->executionOrder($subSteps, $subLinks);

        return [[
            'kind' => $step['type'] === 'foreach' ? 'each' : 'sub',
            'flowId' => $id,
            'flowName' => (string) $target->getName(),
            'steps' => $subSteps,
            'links' => $subLinks,
            'index' => 1, // the trigger is consumed by the ENTER tick itself
            'callerStepId' => $step['id'],
            'callerStepName' => $step['name'],
            // The invoker's "Log event" switch off = the frame's boundary events are dropped.
            'quiet' => !self::configLogsEvents($config),
        ], $subOrder];
    }

    /**
     * A foreach over an empty list: the loop step completes in the caller's frame at once.
     *
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $step
     */
    private function debugCompleteEmptyLoop(array &$blob, array $step, string $executionUuid): array
    {
        $frames = &$blob['frames'];
        $caller = &$frames[\count($frames) - 1];
        $caller['index']++;
        if (self::logsEvents($step)) {
            $this->flowEvents->emitRaw($caller['flowId'], $caller['flowName'], $executionUuid, 'step', [
                'name' => $step['name'],
                'type' => $step['type'],
            ]);
        }
        $callerOrder = $this->executionOrder($caller['steps'], $caller['links'], self::choiceResults($blob['context']));
        if (\count($frames) === 1 && $caller['index'] >= \count($callerOrder)) {
            $blob['done'] = true;
            $this->flowEvents->emitRaw($caller['flowId'], $caller['flowName'], $executionUuid, 'flow-finish');
        }
        unset($caller);

        return $this->debugResponse($blob, $step, $callerOrder, null)
            + ['rootExecuted' => \count($frames) === 1 ? [$step['id']] : []];
    }

    /**
     * The current frame ran out of steps: finish the run, advance a foreach iteration
     * (transition 'reentered') or pop back to the caller (transition 'returned').
     *
     * @param array<string, mixed>             $blob
     * @param array<int, array<string,mixed>>  $trails
     * @param array<int, array<string, mixed>> $order the exhausted frame's order
     */
    private function debugLeaveFrame(array &$blob, array &$trails, array $order): array
    {
        $frame = $blob['frames'][\count($blob['frames']) - 1];
        $executionUuid = (string) ($blob['context']['flowUuid'] ?? '');

        if ($frame['kind'] === 'flow') {
            // Root exhausted (only reachable when the last tick did not already flag it).
            $blob['done'] = true;
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'flow-finish');
            $last = $order[\count($order) - 1];

            return $this->debugResponse($blob, $last, $order, null) + ['rootExecuted' => []];
        }

        if ($frame['kind'] === 'each' && $frame['iteration'] + 1 < \count($frame['list'])) {
            return $this->debugNextIteration($blob, $executionUuid);
        }

        return $this->debugReturnToCaller($blob, $trails, $executionUuid);
    }

    /**
     * Next foreach iteration: same frame, back to the trigger — the editor clears the canvas.
     *
     * @param array<string, mixed> $blob
     */
    private function debugNextIteration(array &$blob, string $executionUuid): array
    {
        $context = &$blob['context'];
        $frame = &$blob['frames'][\count($blob['frames']) - 1];
        $quiet = (bool) ($frame['quiet'] ?? false);
        if (!$quiet) {
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'subflow-finish');
        }
        $frame['iteration']++;
        $frame['index'] = 1;
        $context[$frame['itemVar']] = $frame['list'][$frame['iteration']];
        $context['index'] = $frame['iteration'];
        $subOrder = $this->executionOrder($frame['steps'], $frame['links'], self::choiceResults($context));
        $trigger = $subOrder[0];
        if (!$quiet) {
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'subflow-start', ['trigger' => 'subflow']);
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'step', [
                'name' => $trigger['name'],
                'type' => $trigger['type'],
            ]);
        }
        unset($frame, $context);

        return $this->debugResponse($blob, $trigger, $subOrder, 'reentered') + ['rootExecuted' => []];
    }

    /**
     * Frame complete: restore loop-scoped variables, pop, and complete the caller's step.
     *
     * @param array<string, mixed>            $blob
     * @param array<int, array<string,mixed>> $trails
     */
    private function debugReturnToCaller(array &$blob, array &$trails, string $executionUuid): array
    {
        $frames = &$blob['frames'];
        $frame = array_pop($frames);
        if (!($frame['quiet'] ?? false)) {
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'subflow-finish');
        }
        if ($frame['kind'] === 'each') {
            self::restoreLoopScope($frame, $blob['context']);
        }
        $callerStepId = (string) $frame['callerStepId'];

        $caller = &$frames[\count($frames) - 1];
        $callerOrder = $this->executionOrder($caller['steps'], $caller['links'], self::choiceResults($blob['context']));
        $callerStep = null;
        foreach ($callerOrder as $candidate) {
            if ($candidate['id'] === $callerStepId) {
                $callerStep = $candidate;
                break;
            }
        }
        $callerStep ??= ['id' => $callerStepId, 'name' => $callerStepId, 'type' => 'sub_flow', 'config' => null];
        if (!($frame['quiet'] ?? false) && self::logsEvents($callerStep)) {
            $this->flowEvents->emitRaw($caller['flowId'], $caller['flowName'], $executionUuid, 'step', [
                'name' => $callerStep['name'],
                'type' => $callerStep['type'],
            ]);
        }
        $caller['index']++;

        if (\count($frames) === 1 && $caller['index'] >= \count($callerOrder)) {
            $blob['done'] = true;
            $this->flowEvents->emitRaw($caller['flowId'], $caller['flowName'], $executionUuid, 'flow-finish');
        }
        $rootExecuted = $caller['kind'] === 'flow' ? [$callerStepId] : [];
        if ($caller['kind'] !== 'flow') {
            $this->recordDebugTrail($trails, $caller, [$callerStepId]);
        }
        unset($caller);

        return $this->debugResponse($blob, $callerStep, $callerOrder, 'returned') + ['rootExecuted' => $rootExecuted];
    }

    /**
     * @param array<string, mixed>             $blob
     * @param array<string, mixed>             $step
     * @param array<int, array<string,mixed>>  $order the CURRENT frame's order
     */
    private function debugResponse(array $blob, array $step, array $order, ?string $transition): array
    {
        $frames = $blob['frames'];
        $frame = $frames[\count($frames) - 1];

        return [
            'step' => ['id' => $step['id'], 'name' => $step['name'], 'type' => $step['type']],
            'index' => max(0, (int) $frame['index'] - 1),
            'total' => \count($order),
            'done' => (bool) $blob['done'],
            'transition' => $transition,
            'iteration' => $frame['kind'] === 'each' ? (int) $frame['iteration'] : null,
            'frame' => [
                'flowId' => $frame['flowId'],
                'flowName' => $frame['flowName'],
                'depth' => \count($frames) - 1,
            ],
            'executedIds' => [$step['id']],
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $trails
     * @param array<string, mixed>            $frame
     * @param array<int, string>              $ids
     */
    private function recordDebugTrail(array &$trails, array $frame, array $ids): void
    {
        $key = (int) $frame['flowId'];
        if (!isset($trails[$key])) {
            $trails[$key] = ['flowId' => $key, 'flowName' => $frame['flowName'], 'executedIds' => []];
        }
        $trails[$key]['executedIds'] = array_values(array_unique(array_merge($trails[$key]['executedIds'], $ids)));
    }

    /** flow-exception for the failing frame AND every enclosing one up to the root. */
    private function emitDebugException(array $blob, string $message, string $executionUuid): void
    {
        foreach (array_reverse($blob['frames']) as $frame) {
            $this->flowEvents->emitRaw($frame['flowId'], $frame['flowName'], $executionUuid, 'flow-exception', ['message' => $message]);
        }
    }

    /**
     * Design steps as the executor needs them ({id, type, name, config}) — the stored design is
     * editor state and may carry extras.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDesignSteps(array $raw, string $stepName): array
    {
        $steps = [];
        foreach ($raw as $step) {
            if (!\is_array($step) || !\is_string($step['id'] ?? null) || !\is_string($step['type'] ?? null)) {
                throw new FlowExecutionFailure(sprintf('Step "%s": the subflow design is unreadable.', $stepName));
            }
            $steps[] = [
                'id' => $step['id'],
                'type' => $step['type'],
                'name' => \is_string($step['name'] ?? null) ? $step['name'] : $step['id'],
                'config' => \is_array($step['config'] ?? null) ? $step['config'] : null,
            ];
        }

        return $steps;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDesignLinks(array $raw): array
    {
        $links = [];
        foreach ($raw as $link) {
            if (\is_array($link) && \is_string($link['from'] ?? null) && \is_string($link['to'] ?? null)) {
                $links[] = ['from' => $link['from'], 'fromPort' => (int) ($link['fromPort'] ?? 0), 'to' => $link['to']];
            }
        }

        return $links;
    }

    /**
     * The execution order: breadth-first along the links from the trigger — reachable steps only,
     * the trigger itself first.
     *
     * A CHOICE step only continues on the branch its recorded verdict picked ($choiceResults,
     * step id => bool: true = the green port 0, false = the red port 1). A choice with NO verdict
     * yet ends the walk there — what follows is unknowable until the choice runs, so the order
     * grows as verdicts land (see the recompute in {@see execute()} / {@see executeFrom()}); a
     * picked branch with no link simply ends the flow. Since every port drives at most one link
     * and every step accepts at most one incoming link, already-walked prefixes never reorder.
     *
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     * @param array<string, bool> $choiceResults verdicts of the choice steps executed so far
     *
     * @return array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}>
     */
    private function executionOrder(array $steps, array $links, array $choiceResults = []): array
    {
        [$byId, $trigger] = self::indexSteps($steps);
        $outgoing = self::outgoingLinks($links);

        $visited = [$trigger['id'] => true];
        $queue = [$trigger['id']];
        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $step = $byId[$id];
            $order[] = $step;
            foreach (self::linksToFollow($step, $outgoing[$id] ?? [], $choiceResults) as $link) {
                if (isset($byId[$link['to']]) && !isset($visited[$link['to']])) {
                    $visited[$link['to']] = true;
                    $queue[] = $link['to'];
                }
            }
        }

        return $order;
    }

    /**
     * The steps by id, and the first trigger step (the walk's start).
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>}
     */
    private static function indexSteps(array $steps): array
    {
        $byId = [];
        $trigger = null;
        foreach ($steps as $step) {
            $byId[$step['id']] = $step;
            if ($trigger === null && \in_array($step['type'], OntologyFlow::TRIGGER_STEP_TYPES, true)) {
                $trigger = $step;
            }
        }
        if ($trigger === null) {
            throw new FlowExecutionFailure('The flow has no trigger to debug.');
        }

        return [$byId, $trigger];
    }

    /**
     * Each step's outgoing links, ordered by port.
     *
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     *
     * @return array<string, array<int, array{from: string, fromPort: int, to: string}>>
     */
    private static function outgoingLinks(array $links): array
    {
        $outgoing = [];
        foreach ($links as $link) {
            $outgoing[$link['from']][] = $link;
        }
        foreach ($outgoing as &$list) {
            usort($list, static fn (array $a, array $b) => $a['fromPort'] <=> $b['fromPort']);
        }
        unset($list);

        return $outgoing;
    }

    /**
     * The links the walk follows out of a step: all of them, except for a CHOICE — only the port
     * its verdict picked, or none while the verdict is pending (the path beyond is not known yet).
     *
     * @param array<string, mixed>                                      $step
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     * @param array<string, bool>                                       $choiceResults
     *
     * @return array<int, array{from: string, fromPort: int, to: string}>
     */
    private static function linksToFollow(array $step, array $links, array $choiceResults): array
    {
        if ($step['type'] !== 'choice') {
            return $links;
        }
        if (!\array_key_exists($step['id'], $choiceResults)) {
            return [];
        }
        $port = $choiceResults[$step['id']] ? 0 : 1;

        return array_values(array_filter($links, static fn (array $l) => $l['fromPort'] === $port));
    }

    /**
     * The choice verdicts a context has accumulated (the reserved `choiceResults` key).
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, bool>
     */
    private static function choiceResults(array $context): array
    {
        $results = $context['choiceResults'] ?? null;

        return \is_array($results) ? array_map(static fn ($v) => (bool) $v, $results) : [];
    }

    /**
     * Stamps the flow's last_executed with "now" — called whenever a run starts (debug, Run Now,
     * later the real triggers), failed runs included: the attempt DID execute. Unsaved flows
     * (null / no id) have nothing to stamp.
     */
    private function touchLastExecuted(?OntologyFlow $flow): void
    {
        if ($flow === null || $flow->getId() === null) {
            return;
        }
        $flow->setLastExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
        $em = $this->doctrine->getManagerForClass(OntologyFlow::class);
        $em->persist($flow);
        $em->flush();
    }

    /**
     * Stamps the flow's last_finished with "now" — called when a run ENDS, successfully or not
     * (see the `finally` blocks). While last_finished trails last_executed the flow counts as
     * running, which is what stops a second instance from being scheduled on top of it.
     */
    private function touchLastFinished(?OntologyFlow $flow): void
    {
        if ($flow === null || $flow->getId() === null) {
            return;
        }
        $flow->setLastFinished(new \DateTime('now', new \DateTimeZone('UTC')));
        $em = $this->doctrine->getManagerForClass(OntologyFlow::class);
        $em->persist($flow);
        $em->flush();
    }

    /**
     * The run's starting context: the execution uuid plus the trigger's event payload.
     *
     * @param array{type: string} $trigger
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function initialContext(array $trigger, array $input, string $executionUuid): array
    {
        $context = ['flowUuid' => $executionUuid];
        if ($trigger['type'] === 'entity_change' && \array_key_exists('payload', $input)) {
            $context['payload'] = $input['payload'];
        }
        // An Endpoint-triggered run sees its HTTP request as variables: every {param} captured
        // from the path under its own name (the validator forbids reserved names there), the
        // request body as `body` and the headers as `headers`.
        if ($trigger['type'] === 'endpoint') {
            foreach (\is_array($input['params'] ?? null) ? $input['params'] : [] as $name => $value) {
                $context[(string) $name] = $value;
            }
            if (\array_key_exists('body', $input)) {
                $context['body'] = $input['body'];
            }
            if (\array_key_exists('headers', $input)) {
                $context['headers'] = $input['headers'];
            }
            if (\array_key_exists('queryParams', $input)) {
                $context['queryParams'] = $input['queryParams'];
            }
            // Authenticated calls only (public or not): the OAuth application that made the
            // call (null when the caller authenticated some other way, e.g. a session).
            if (\array_key_exists('OAuthApplication', $input)) {
                $context['OAuthApplication'] = $input['OAuthApplication'];
            }
        }

        return $context;
    }

    /**
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $context
     */
    private function executeStep(array $step, array &$context, ?OntologyFlow $flow, string $executionUuid): void
    {
        // Choice: evaluates its DWL expression against the current context and records the verdict
        // under the RESERVED context key `choiceResults` (step id => bool) — the walk reads it to
        // pick the branch: truthy = the green output (port 0), falsy = the red one (port 1).
        if ($step['type'] === 'choice') {
            $verdict = $this->choiceVerdict($step, $context);
            $results = \is_array($context['choiceResults'] ?? null) ? $context['choiceResults'] : [];
            $results[$step['id']] = $verdict;
            $context['choiceResults'] = $results;

            return;
        }
        if (!\in_array($step['type'], self::EXECUTABLE_STEP_TYPES, true)) {
            return; // triggers seed the context in execute(); other types have no debug behaviour yet
        }

        $config = $step['config'];
        if (!\is_array($config)) {
            throw new FlowExecutionFailure(sprintf(self::NOT_CONFIGURED, $step['name']));
        }
        if (\in_array($step['type'], self::DESTINATIONLESS_STEP_TYPES, true) || self::isAsyncRequest($step['type'], $config)) {
            $this->executeDestinationlessStep($step, $config, $context, $flow, $executionUuid);
        } else {
            $destination = trim((string) ($config['destination'] ?? ''));
            if ($destination === '') {
                throw new FlowExecutionFailure(sprintf('Step "%s" has no destination.', $step['name']));
            }
            $context[$destination] = $this->stepResult($step, $config, $context, $flow, $executionUuid);
        }
    }

    /**
     * An ASYNC "HTTP Request" (fire and forget): it has no destination — nothing of its response
     * is kept — so it runs among the destinationless steps.
     *
     * @param array<string, mixed> $config
     */
    private static function isAsyncRequest(string $type, array $config): bool
    {
        return $type === 'invoke' && ($config['async'] ?? false) === true;
    }

    /**
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $context
     */
    private function choiceVerdict(array $step, array $context): bool
    {
        $expression = \is_array($step['config']) && \is_string($step['config']['expression'] ?? null)
            ? trim($step['config']['expression'])
            : '';
        if ($expression === '') {
            throw new FlowExecutionFailure(sprintf(self::NOT_CONFIGURED, $step['name']));
        }

        return (bool) $this->transformForStep($step['name'], $expression, $context);
    }

    /**
     * The steps without a destination of their own.
     *
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $config
     * @param array<string, mixed>                                                             $context
     */
    private function executeDestinationlessStep(array $step, array $config, array &$context, ?OntologyFlow $flow, string $executionUuid): void
    {
        if ($step['type'] === 'sub_flow') {
            // Call Subflow: the subflow runs over the caller's context and whatever it wrote there
            // is what the next main-flow element sees.
            $context = $this->callSubflow($step['name'], $config, $context, $executionUuid);
        } elseif ($step['type'] === 'foreach') {
            // Foreach Loop shares the context the same way — one subflow run per array element.
            $context = $this->foreachSubflow($step['name'], $config, $context, $executionUuid);
        } elseif ($step['type'] === 'logger') {
            // Logger emits ONE line into the PHP application log, prefixed with the flow's name.
            $text = $this->messageText($step['name'], $config, 'message', $context);
            $this->logger->info(sprintf('[Aaxis Flow - %s] %s', $flow?->getName() ?? 'unsaved', $text));
        } elseif ($step['type'] === 'event') {
            // Event queues a log-message flow event with the resolved value as the message.
            $text = $this->messageText($step['name'], $config, 'value', $context);
            $this->flowEvents->emit($flow, $executionUuid, 'log-message', ['message' => $text]);
        } elseif ($step['type'] === 'invoke') {
            // An ASYNC HTTP Request: queued for the HTTP-request consumer, response discarded.
            $this->queueRestRequest($step['name'], $config, $context, $flow, $executionUuid);
        } else {
            // MS Teams fires the configured Power Automate webhook with the current payload as a
            // Teams message and the flow simply continues.
            $this->msTeamsNotify($step['name'], $config, $context);
        }
    }

    /**
     * The text of a Logger message / Event value: hardcoded text, or — via its `<key>_dwl` toggle —
     * an expression resolved against the current context (strings verbatim; anything else —
     * objects, lists, numbers, booleans — as JSON).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function messageText(string $stepName, array $config, string $key, array $context): string
    {
        $raw = trim((string) ($config[$key] ?? ''));
        if ($raw === '') {
            throw new FlowExecutionFailure(sprintf(self::NOT_CONFIGURED, $stepName));
        }
        if (($config[$key . '_dwl'] ?? false) !== true) {
            return $raw;
        }
        $result = $this->transformForStep($stepName, $raw, $context);

        return \is_string($result)
            ? $result
            : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * What a step with a destination produces.
     *
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $config
     * @param array<string, mixed>                                                             $context
     */
    private function stepResult(array $step, array $config, array $context, ?OntologyFlow $flow, string $executionUuid): mixed
    {
        return match ($step['type']) {
            'file_read', 'file_write', 'file_list', 'file_delete', 'file_rename'
                => $this->fileOperation($step['type'], $step['name'], $config, $context),
            // "Invoke PHP": one public method of an app-namespace service, parameters bound by
            // name from the step's DWL object (see PhpMethodInvoker).
            'invoke_php' => $this->phpInvoker->invoke($step['name'], $config, $context),
            // The typed successors of the generic reader/writer (same config, no discriminator),
            // and "HTTP Request" (`invoke`) — a rest_api connector call.
            'entity_read' => $this->readEntity($step['name'], $config),
            'entity_write' => $this->writeEntity($step['name'], $config, $context, $flow, $executionUuid),
            'invoke' => $this->readConnector($step['name'], $config, $context),
            'sql_query' => $this->sqlQuery($step['name'], $config, $context),
            // dwl_transform: the WHOLE current context is visible to the script (payload, prior
            // destinations…).
            default => $this->transformForStep($step['name'], (string) ($config['code'] ?? ''), $context),
        };
    }

    /**
     * Runs a step's DWL against the context; an engine error becomes the step's failure.
     *
     * @param array<string, mixed> $context
     */
    private function transformForStep(string $stepName, string $code, array $context): mixed
    {
        try {
            return $this->dwl->transform($code, $context);
        } catch (\Throwable $e) {
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        }
    }

    /** A failure naming its step: 'Step "<name>": <detail>'. */
    private static function stepFailure(string $stepName, string $detail, ?\Throwable $previous = null): FlowExecutionFailure
    {
        return new FlowExecutionFailure(sprintf(self::STEP_DETAIL, $stepName, $detail), 0, $previous);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readEntity(string $stepName, array $config): mixed
    {
        $system = (string) ($config['system'] ?? '');
        $entityName = (string) ($config['entity'] ?? '');
        $limit = $config['limit'] ?? null;
        try {
            return match ($config['mode'] ?? 'all') {
                'by_id' => $this->dataApi->read($system, $entityName, (string) ($config['record_id'] ?? '')),
                // "By attribute" yields the LIST of matches ([] when none) — an attribute, unlike
                // the unique id, may match any number of records.
                'by_attribute' => $this->dataApi->queryForFlowByAttribute(
                    $system,
                    $entityName,
                    (string) ($config['attribute'] ?? ''),
                    (string) ($config['attr_value'] ?? '')
                ),
                // Flow reads are NOT page-capped (the API's caps are for outside callers): "all"
                // really is every record, unless the step configures its own limit / ordering.
                default => $this->dataApi->queryForFlow(
                    $system,
                    $entityName,
                    trim((string) ($config['order_by'] ?? '')) ?: null,
                    strtolower((string) ($config['order_dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC',
                    \is_int($limit) && $limit > 0 ? $limit : null
                ),
            };
        } catch (OntologyApiException $e) {
            // A missing record is a legitimate outcome for "by id" — the step yields null.
            if ($e->getErrorCode() === 'record_not_found') {
                return null;
            }
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        }
    }

    /**
     * Writes the context value named by `content` (or produced by its DWL expression) into the
     * configured entity: a single object or an array of objects, unique id inferred from the
     * entity's unique_attribute. INTERNAL-system entities are written to the OroCommerce entity
     * itself (updates of existing rows only — {@see OroEntityWriter}); external ones upsert the
     * ontology store. An EMPTY value (null / [] / "") is not an error — the write is
     * skipped and the receipt reads {uuid: <run uuid>, count: 0, upsert: 0, changedIds: []}.
     * Otherwise the write is SYNCHRONOUS ({@see OntologyDataApiManager::upsertRecordsSync})
     * so the receipt reports the real outcome — {uuid, count, upsert: <created+changed>,
     * changedIds: [...]} — and the event row (stamped with the flow being debugged; Manual only
     * for a never-saved flow) completes immediately with its changed_ids/finished_at.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> the upsert receipt stored under the step's destination
     */
    private function writeEntity(string $stepName, array $config, array $context, ?OntologyFlow $flow, string $executionUuid): array
    {
        // The DWL toggle turns the content into an expression over the context; its result is the
        // record(s) to write. Same resolution as the connector writer's.
        $value = $this->resolveContent($stepName, $config, $context);
        // Nothing to write is a legitimate outcome (an upstream filter left no records): skip the
        // upsert entirely and report an empty-but-successful receipt under the run's uuid.
        if ($value === null || $value === [] || $value === '') {
            return ['uuid' => $executionUuid, 'count' => 0, 'upsert' => 0, 'changedIds' => []];
        }
        if (\is_array($value) && !array_is_list($value)) {
            $records = [$value]; // a single record object
        } elseif (\is_array($value)) {
            $records = $value; // an array of record objects (validated downstream)
        } else {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": the content must resolve to an object or an array of objects.',
                $stepName
            ));
        }

        $system = $this->doctrine->getRepository(OntologySystem::class)
            ->findOneBy(['name' => (string) ($config['system'] ?? '')]);
        $entity = $system === null ? null : $this->doctrine->getRepository(OntologyEntity::class)
            ->findOneBy(['system' => $system, 'name' => (string) ($config['entity'] ?? '')]);
        if ($entity === null) {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": unknown entity "%s" in system "%s".',
                $stepName,
                (string) ($config['entity'] ?? ''),
                (string) ($config['system'] ?? '')
            ));
        }

        try {
            $stampFlow = $flow ?? $this->dataApi->requireEnabledFlow(OntologyFlow::NAME_MANUAL);
            // Synchronous, stamped with the execution uuid (not a fresh batch one): the receipt
            // reports the write's REAL outcome and the event row completes on the spot.
            $result = $this->dataApi->upsertRecordsSync($entity, $records, $stampFlow, $executionUuid, self::configLogsEvents($config));
        } catch (OntologyApiException $e) {
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        }

        return [
            'uuid' => $result['uuid'],
            'count' => \count($records),
            'upsert' => \count($result['changed']),
            'changedIds' => $result['changed'],
        ];
    }


    /**
     * The File Operations steps: one file-based connector (file_system/sftp/bucket), a DWL-capable
     * path, and per-type extras — file_write's Content (context key or DWL, like every writer) and
     * file_rename's New name. The transfer's result contract applies: I/O failures come back as
     * `{isError: true, ...}` payloads the flow can branch on, never as aborts.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function fileOperation(string $type, string $stepName, array $config, array $context): mixed
    {
        $connector = $this->connectorOf($stepName, $config);
        if (!$this->files->supports((string) $connector->getType())) {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": a "%s" connector is not file-based.',
                $stepName,
                (string) $connector->getType()
            ));
        }

        $path = $this->resolveDwlText($stepName, 'path', (string) ($config['path'] ?? ''), ($config['path_dwl'] ?? false) === true, $context);

        try {
            return match ($type) {
                'file_read', 'file_list' => $this->files->read($connector, $path),
                'file_write' => $this->files->write($connector, $path, $this->contentText($stepName, $config, $context)),
                'file_delete' => $this->files->delete($connector, $path),
                default => $this->files->rename($connector, $path, $this->resolveDwlText( // file_rename
                    $stepName,
                    'new name',
                    (string) ($config['new_name'] ?? ''),
                    ($config['new_name_dwl'] ?? false) === true,
                    $context
                )),
            };
        } catch (\RuntimeException $e) {
            throw self::stepFailure($stepName, $e->getMessage() . '.', $e);
        }
    }

    /**
     * file_write's content as text: strings verbatim, anything else JSON-encoded.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function contentText(string $stepName, array $config, array $context): string
    {
        $value = $this->resolveContent($stepName, $config, $context);

        return \is_string($value) ? $value : (string) json_encode($value);
    }

    /**
     * The connector a step is configured with — gone connectors fail the step.
     *
     * @param array<string, mixed> $config
     */
    private function connectorOf(string $stepName, array $config): OntologyConnector
    {
        /** @var OntologyConnector|null $connector */
        $connector = $this->doctrine->getRepository(OntologyConnector::class)->find((int) ($config['connector'] ?? 0));
        if ($connector === null) {
            throw new FlowExecutionFailure(sprintf('Step "%s": the configured connector no longer exists.', $stepName));
        }

        return $connector;
    }

    /**
     * A text config value that may be a DWL expression: with the toggle ON the expression runs
     * against the context and must yield a non-empty scalar; OFF is the literal text (unlike a
     * writer's Content, which names a context key — a path/name is typed in place).
     *
     * @param array<string, mixed> $context
     */
    /**
     * "MS Teams" notification: POSTs the standard Teams-workflow envelope (an Adaptive Card with
     * one wrapped TextBlock) to the step's Power Automate webhook — the shape the stock
     * "post a card to a chat or channel when a webhook request is received" template consumes.
     * The message text comes from the CONFIGURED context variable (`config.message`): strings go
     * verbatim, any other value is stringified (scalars via cast, structures as pretty JSON); an
     * undefined variable fails the step. Power Automate answers 202 when the automation starts;
     * any HTTP >= 400 or transport error fails the step too.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function msTeamsNotify(string $stepName, array $config, array $context): void
    {
        $webhook = trim((string) ($config['webhook'] ?? ''));
        $variable = trim((string) ($config['message'] ?? ''));
        if ($webhook === '' || $variable === '' || !str_starts_with(strtolower($webhook), 'https://')) {
            throw new FlowExecutionFailure(sprintf(self::NOT_CONFIGURED, $stepName));
        }
        if (!\array_key_exists($variable, $context)) {
            throw new FlowExecutionFailure(sprintf(self::VARIABLE_UNDEFINED, $stepName, $variable));
        }
        $value = $context[$variable];
        $text = match (true) {
            \is_string($value) => $value,
            $value === null => 'null',
            \is_scalar($value) => var_export($value, true),
            default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        try {
            $response = $this->httpClient->request('POST', $webhook, [
                'json' => [
                    'type' => 'message',
                    'attachments' => [[
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'content' => [
                            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                            'type' => 'AdaptiveCard',
                            'version' => '1.4',
                            'body' => [['type' => 'TextBlock', 'text' => $text, 'wrap' => true]],
                        ],
                    ]],
                ],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $response->getContent(false);
        } catch (\Throwable $e) {
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        }
        if ($status >= 400) {
            throw new FlowExecutionFailure(sprintf('Step "%s": the webhook responded HTTP %d.', $stepName, $status));
        }
    }

    private function resolveDwlText(string $stepName, string $what, string $raw, bool $isDwl, array $context): string
    {
        if (!$isDwl) {
            return trim($raw);
        }
        $value = $this->transformForStep($stepName, $raw, $context);
        if (!\is_scalar($value) || trim((string) $value) === '') {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": the %s expression must produce a non-empty text, got %s.',
                $stepName,
                $what,
                get_debug_type($value)
            ));
        }

        return trim((string) $value);
    }

    /**
     * The value a writer step is asked to write: the named context key, or the result of its DWL
     * expression when `content_dwl` is on. Shared by the entity and connector writers so both read
     * the step config the same way.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function resolveContent(string $stepName, array $config, array $context, string $key = 'content'): mixed
    {
        $contentSource = trim((string) ($config[$key] ?? ''));
        if (($config[$key . '_dwl'] ?? false) === true) {
            return $this->transformForStep($stepName, $contentSource, $context);
        }
        if (!\array_key_exists($contentSource, $context)) {
            throw new FlowExecutionFailure(sprintf('Step "%s": %s "%s" is not available in the context.', $stepName, $key, $contentSource));
        }

        return $context[$contentSource];
    }

    /**
     * The $runInfo of a run invoked by a sub_flow/foreach step: QUIET when the invoker's
     * "Log event" switch is off ({@see logsEvents()}).
     *
     * @param array<string, mixed> $config the invoker's config
     *
     * @return array{trigger: string, quiet: bool}
     */
    private static function nestedRunInfo(array $config): array
    {
        return ['trigger' => 'subflow', 'quiet' => !self::configLogsEvents($config)];
    }

    /**
     * The "Call Subflow" step: runs the invoked subflow's WHOLE graph (from its Subflow trigger,
     * breadth-first, choices and all) over the CALLER's context, then hands the resulting context
     * back so the main flow continues at its next element. The callee inherits the caller's
     * flowUuid (its writers group under the same run identity) and stamps its own
     * last_executed/last_finished. FAILS when the target is missing, is not a subflow, has no
     * design, is DISABLED, or would recurse (a flow already on the call stack / depth > 10).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> the context after the subflow ran
     */
    private function callSubflow(string $stepName, array $config, array $context, string $executionUuid): array
    {
        [$id, $target, $subSteps, $subLinks] = $this->resolveSubflowTarget($stepName, $config);

        // The nested run resets the executed-ids trail (its step ids belong to ANOTHER canvas) —
        // preserve the caller's so the editor keeps painting the right tiles.
        $outerTrail = $this->lastExecutedIds;
        $this->subflowStack[] = $id;
        try {
            return $this->execute($subSteps, $subLinks, [], $target, $executionUuid, $context, self::nestedRunInfo($config));
        } catch (\RuntimeException $e) {
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        } finally {
            array_pop($this->subflowStack);
            // The nested trail (partial on failure) feeds the editor's subflow visit.
            $this->recordSubflowTrail($id, (string) $target->getName(), $this->lastExecutedIds);
            $this->lastExecutedIds = $outerTrail;
        }
    }

    /**
     * "Foreach Loop": runs the configured subflow ONCE PER ELEMENT of the named array variable —
     * sequentially, over the SHARED context (iteration N sees what N-1 wrote, and everything the
     * subflow writes stays for the caller). Each iteration sees the current element under the
     * configured flow-variable name and its 0-based position under `index`; both are LOOP-SCOPED:
     * whatever the caller held under those names is restored (or removed) after the loop.
     *
     * @param array<string, mixed> $config  {subflow, array, item}
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> the context after the last iteration
     */
    private function foreachSubflow(string $stepName, array $config, array $context, string $executionUuid): array
    {
        [$list, $itemVar] = $this->loopItems($stepName, $config, $context);
        [$id, $target, $subSteps, $subLinks] = $this->resolveSubflowTarget($stepName, $config);
        $scope = self::loopScope($itemVar, $context);

        $outerTrail = $this->lastExecutedIds;
        $this->subflowStack[] = $id;
        try {
            foreach ($list as $index => $element) {
                $context[$itemVar] = $element;
                $context['index'] = $index;
                try {
                    $context = $this->execute($subSteps, $subLinks, [], $target, $executionUuid, $context, self::nestedRunInfo($config));
                } catch (\RuntimeException $e) {
                    throw new FlowExecutionFailure(sprintf('Step "%s" (iteration %d): %s', $stepName, $index, $e->getMessage()), 0, $e);
                } finally {
                    $this->recordSubflowTrail($id, (string) $target->getName(), $this->lastExecutedIds);
                }
            }
        } finally {
            array_pop($this->subflowStack);
            $this->lastExecutedIds = $outerTrail;
        }
        self::restoreLoopScope($scope, $context);

        return $context;
    }

    /**
     * The array a Foreach Loop iterates and its per-element variable name: both configured, the
     * array variable defined and holding a LIST.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array{0: array<int, mixed>, 1: string}
     */
    private function loopItems(string $stepName, array $config, array $context): array
    {
        $arrayVar = trim((string) ($config['array'] ?? ''));
        $itemVar = trim((string) ($config['item'] ?? ''));
        if ($arrayVar === '' || $itemVar === '') {
            throw new FlowExecutionFailure(sprintf(self::NOT_CONFIGURED, $stepName));
        }
        if (!\array_key_exists($arrayVar, $context)) {
            throw new FlowExecutionFailure(sprintf(self::VARIABLE_UNDEFINED, $stepName, $arrayVar));
        }
        $list = $context[$arrayVar];
        if (!\is_array($list) || ($list !== [] && !array_is_list($list))) {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": the variable "%s" must hold an array, got %s.',
                $stepName,
                $arrayVar,
                get_debug_type($list)
            ));
        }

        return [$list, $itemVar];
    }

    /**
     * What the caller held under the loop-scoped names (the item variable and `index`), so the
     * loop can put it back when it ends.
     *
     * @param array<string, mixed> $context
     *
     * @return array{itemVar: string, hadItem: bool, prevItem: mixed, hadIndex: bool, prevIndex: mixed}
     */
    private static function loopScope(string $itemVar, array $context): array
    {
        return [
            'itemVar' => $itemVar,
            'hadItem' => \array_key_exists($itemVar, $context),
            'prevItem' => $context[$itemVar] ?? null,
            'hadIndex' => \array_key_exists('index', $context),
            'prevIndex' => $context['index'] ?? null,
        ];
    }

    /**
     * Restores (or removes) the loop-scoped names recorded by {@see loopScope()}.
     *
     * @param array{itemVar: string, hadItem: bool, prevItem: mixed, hadIndex: bool, prevIndex: mixed} $scope
     * @param array<string, mixed>                                                                     $context
     */
    private static function restoreLoopScope(array $scope, array &$context): void
    {
        if ($scope['hadItem']) {
            $context[$scope['itemVar']] = $scope['prevItem'];
        } else {
            unset($context[$scope['itemVar']]);
        }
        if ($scope['hadIndex']) {
            $context['index'] = $scope['prevIndex'];
        } else {
            unset($context['index']);
        }
    }

    /**
     * @return array<int, array{flowId: int, flowName: string, executedIds: array<int, string>}>
     */
    public function subflowTrails(): array
    {
        return $this->subflowTrails;
    }

    /**
     * @param array<int, string> $ids
     */
    private function recordSubflowTrail(int $flowId, string $flowName, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        foreach ($this->subflowTrails as &$trail) {
            if ($trail['flowId'] === $flowId) {
                $trail['executedIds'] = array_values(array_unique(array_merge($trail['executedIds'], $ids)));

                return;
            }
        }
        unset($trail);
        $this->subflowTrails[] = ['flowId' => $flowId, 'flowName' => $flowName, 'executedIds' => array_values($ids)];
    }

    /**
     * Loads + guards the subflow a sub_flow/foreach step points at: must exist, be of type
     * subflow, be ENABLED, carry design steps, not already run in this execution (circular) and
     * not nest deeper than 10 levels.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: int, 1: OntologyFlow, 2: array<int, mixed>, 3: array<int, mixed>}
     */
    private function resolveSubflowTarget(string $stepName, array $config): array
    {
        $id = (int) ($config['subflow'] ?? 0);
        /** @var OntologyFlow|null $target */
        $target = $id > 0 ? $this->doctrine->getRepository(OntologyFlow::class)->find($id) : null;
        if ($target === null) {
            throw new FlowExecutionFailure(sprintf('Step "%s": the configured subflow no longer exists.', $stepName));
        }
        if ($target->getType() !== OntologyFlow::TYPE_SUBFLOW) {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": "%s" is not a subflow (its trigger makes it a regular flow).',
                $stepName,
                (string) $target->getName()
            ));
        }
        if (!$target->isEnabled()) {
            throw new FlowExecutionFailure(sprintf(
                'Step "%s": the subflow "%s" is disabled.',
                $stepName,
                (string) $target->getName()
            ));
        }
        $design = $target->getDesign();
        $subSteps = \is_array($design['steps'] ?? null) ? $design['steps'] : null;
        $subLinks = \is_array($design['links'] ?? null) ? $design['links'] : [];
        if ($subSteps === null || $subSteps === []) {
            throw new FlowExecutionFailure(sprintf('Step "%s": the subflow "%s" has no steps.', $stepName, (string) $target->getName()));
        }
        if (\in_array($id, $this->subflowStack, true)) {
            throw new FlowExecutionFailure(sprintf(self::CIRCULAR, $stepName, (string) $target->getName()));
        }
        if (\count($this->subflowStack) >= 10) {
            throw new FlowExecutionFailure(sprintf(self::TOO_DEEP, $stepName));
        }

        return [$id, $target, $subSteps, $subLinks];
    }

    /**
     * The "SQL Query" step: runs the (DWL-capable) SQL against a DATABASE connector via
     * {@see DatabaseQueryRunner}. `:name` placeholders are fed by the Bindings result (a context
     * key, or a DWL expression when its toggle is on): an object = one run, a LIST = one run per
     * element with the destination holding the per-run results in order.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function sqlQuery(string $stepName, array $config, array $context): mixed
    {
        $connector = $this->connectorOf($stepName, $config);

        $sql = $this->resolveDwlText(
            $stepName,
            'SQL',
            (string) ($config['sql'] ?? ''),
            ($config['sql_dwl'] ?? false) === true,
            $context
        );
        $bindings = trim((string) ($config['binding'] ?? '')) === ''
            ? null
            : $this->resolveContent($stepName, $config, $context, 'binding');

        try {
            return $this->database->run($connector, $sql, $bindings);
        } catch (\RuntimeException $e) {
            throw self::stepFailure($stepName, $e->getMessage() . '.', $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function readConnector(string $stepName, array $config, array $context): mixed
    {
        $connector = $this->connectorOf($stepName, $config);
        $path = (string) ($config['path'] ?? '');
        if ($this->files->supports((string) $connector->getType())) {
            // Folder → a list of items; file → its content; failure → an isError payload.
            try {
                return $this->files->read($connector, $path);
            } catch (\RuntimeException $e) {
                throw self::stepFailure($stepName, $e->getMessage() . '.', $e);
            }
        }
        if ($connector->getType() !== OntologyConnector::TYPE_REST_API) {
            return ['_debug' => sprintf('"%s" connectors are not executed in debug yet.', (string) $connector->getType())];
        }

        return $this->executeRestRead($stepName, $connector, $config, $context);
    }

    /**
     * Performs the REST call a synchronous HTTP Request step describes (the transport, auth and
     * OAuth-token reuse live in {@see RestConnectorClient}); the response is the step's result.
     *
     * @param array<string, mixed> $stepConfig
     * @param array<string, mixed> $context    available to a DWL-toggled body (body_dwl)
     */
    private function executeRestRead(string $stepName, OntologyConnector $connector, array $stepConfig, array $context): mixed
    {
        $request = $this->restRequest($stepName, $stepConfig, $context);

        try {
            return $this->rest->send($connector, $request['method'], $request['path'], $request['body'], $request['body_type'], self::runId($context));
        } catch (ConnectorRequestFailure $e) {
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        }
    }

    /**
     * An ASYNC HTTP Request step: everything that needs the flow is resolved NOW — the connector
     * (must exist, be rest_api and have a server, so a broken definition fails the run instead of
     * every queued call) and the body (a DWL body is evaluated against the current context) — then
     * the request is queued and the flow moves on. The consumer
     * ({@see \Aaxis\Bundle\OntologyBundle\Async\OntologyHttpRequestProcessor}) sends it and
     * DISCARDS the response; its failures surface as log-message events of this run.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function queueRestRequest(string $stepName, array $config, array $context, ?OntologyFlow $flow, string $executionUuid): void
    {
        $connector = $this->connectorOf($stepName, $config);
        if ($connector->getType() !== OntologyConnector::TYPE_REST_API) {
            throw self::stepFailure($stepName, sprintf('async requests need a rest_api connector, not "%s".', (string) $connector->getType()));
        }
        try {
            $this->rest->baseUrl($connector);
        } catch (ConnectorRequestFailure $e) {
            throw self::stepFailure($stepName, $e->getMessage(), $e);
        }
        $request = $this->restRequest($stepName, $config, $context);

        try {
            $this->producer->send(OntologyHttpRequestTopic::getName(), [
                'connector_id' => $connector->getId(),
                'method' => $request['method'],
                'path' => $request['path'],
                'body' => $request['body'],
                'body_type' => $request['body_type'],
                'step' => $stepName,
                'flow_id' => $flow?->getId(),
                'flow_name' => $flow?->getName(),
                'flow_uuid' => $executionUuid,
            ]);
        } catch (\Throwable $e) {
            throw self::stepFailure($stepName, 'the request could not be queued — ' . $e->getMessage(), $e);
        }
    }

    /**
     * The request an HTTP Request step's config describes. The body is present only when the Body
     * type is not "empty" and the content is filled; the DWL toggle turns it into an expression
     * over the context — a string result is sent verbatim, anything else as its JSON form.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array{method: string, path: string, body: string|null, body_type: string}
     */
    private function restRequest(string $stepName, array $config, array $context): array
    {
        $bodyType = (string) ($config['body'] ?? 'empty');
        $body = (string) ($config['body_content'] ?? '');
        if ($bodyType === 'empty' || $body === '') {
            $body = null;
        } elseif (($config['body_dwl'] ?? false) === true) {
            $body = $this->renderDwl($stepName, $body, $context);
        }

        // The path is literal text, or — `path_dwl` on — a DWL expression over the context that
        // must yield non-empty text (e.g. "orders/" ++ payload.id).
        $path = (string) ($config['path'] ?? '');
        if (($config['path_dwl'] ?? false) === true) {
            $path = $this->resolveDwlText($stepName, 'path', $path, true, $context);
        }

        return [
            'method' => strtoupper((string) ($config['operation'] ?? 'get')) ?: 'GET',
            'path' => $path,
            'body' => $body,
            'body_type' => $bodyType,
        ];
    }

    /**
     * The run's flowUuid — scopes OAuth tokens of unknown lifetime to the run.
     *
     * @param array<string, mixed> $context
     */
    private static function runId(array $context): ?string
    {
        return \is_string($context['flowUuid'] ?? null) && $context['flowUuid'] !== '' ? $context['flowUuid'] : null;
    }

    /**
     * Evaluates a DWL-toggled text field against the context and renders the result as the text
     * to send: strings verbatim, everything else JSON-encoded.
     *
     * @param array<string, mixed> $context
     */
    private function renderDwl(string $stepName, string $code, array $context): string
    {
        $result = $this->transformForStep($stepName, $code, $context);

        return \is_string($result) ? $result : (string) json_encode($result);
    }
}
