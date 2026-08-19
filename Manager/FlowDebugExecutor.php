<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\Persistence\ManagerRegistry;
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
 *  - invoke ("HTTP Request"): the rest_api connector call executes for real (URL from the
 *    connector's server/port + the step's path; connector headers + auth headers; auth=oauth
 *    first POSTs the token path and attaches the returned access_token; the step's
 *    operation/body are honoured);
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

    private const int TIMEOUT = 30;

    public function __construct(
        private readonly OntologyDataApiManager $dataApi,
        private readonly ManagerRegistry $doctrine,
        private readonly HttpClientInterface $httpClient,
        private readonly DwlTransformer $dwl,
        private readonly FileConnectorTransfer $files,
        private readonly FlowStepValidator $stepValidator,
        private readonly DatabaseQueryRunner $database,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The RUN gate: incomplete/invalid steps are SAVEABLE (the editor marks them red), but a flow
     * carrying any must not execute — not from the UI, not from a trigger. Sitting in the executor,
     * the gate covers every entry point (debug, Run Now, the scheduler, future triggers) at once.
     *
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     *
     * @throws \RuntimeException naming the first problem
     */
    private function assertRunnable(array $steps): void
    {
        $problems = $this->stepValidator->validate($steps);
        if ($problems !== []) {
            throw new \RuntimeException($problems[0]);
        }
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
     */
    public function execute(array $steps, array $links, array $input, ?OntologyFlow $flow = null, ?string $executionUuid = null, ?array $seedContext = null): array
    {
        $this->assertRunnable($steps);
        $order = $this->executionOrder($steps, $links);
        $this->touchLastExecuted($flow);

        // One uuid identifies the WHOLE execution: every writer stamps its upsert with it (all
        // events/records of a run group under it), and steps can read it from the context as
        // the flowUuid variable (a valid DWL identifier).
        // Minted only for a top-level run: a sub-flow inherits its caller's uuid via the param.
        $executionUuid ??= $this->dataApi->generateUuid();

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
                $this->executeStepTracked($step, $context, $flow, $executionUuid);
                if ($step['type'] === 'choice') {
                    $order = $this->executionOrder($steps, $links, self::choiceResults($context));
                }
            }
        } finally {
            // finally, not a happy-path call: a failed run must still release the "running" state
            // or the flow would be blocked from ever being scheduled again.
            $this->touchLastFinished($flow);
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
    private function executeStepTracked(array $step, array &$context, ?OntologyFlow $flow, string $executionUuid): void
    {
        try {
            $this->executeStep($step, $context, $flow, $executionUuid);
        } catch (\RuntimeException $e) {
            throw new FlowStepFailure($e->getMessage(), $step['id'], $this->lastExecutedIds, $e);
        }
        $this->lastExecutedIds[] = $step['id'];
    }

    /**
     * Step-by-step debug: executes the step at $index of the execution order (or from it to the
     * end when $runToEnd), starting from the CLIENT-HELD context accumulated by the previous
     * steps. Index 0 seeds a fresh context — minting the run's flowUuid — and ignores $context;
     * later indexes require the passed context to still carry that uuid (writers keep stamping
     * it). Returns the new context plus progress metadata for the stepper UI.
     *
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $context
     *
     * @return array{context: array<string, mixed>, step: array{name: string, type: string}, index: int, total: int, done: bool}
     *
     * @throws \RuntimeException with a user-readable message when a step cannot execute
     */
    public function executeFrom(array $steps, array $links, array $input, int $index, ?array $context, ?OntologyFlow $flow = null, bool $runToEnd = false): array
    {
        // Branch-aware: the verdicts recorded by the choice steps ALREADY executed (carried by the
        // debug context) rebuild exactly the path walked so far, so the client's plain index stays
        // a valid cursor into it. `total` grows as later choices resolve their branch.
        $this->assertRunnable($steps);
        $order = $this->executionOrder($steps, $links, self::choiceResults($context ?? []));
        $total = \count($order);
        if ($index < 0 || $index >= $total) {
            throw new \RuntimeException('There is no step left to execute.');
        }
        $this->touchLastExecuted($flow);

        if ($index === 0) {
            $executionUuid = $this->dataApi->generateUuid();
            $context = $this->initialContext($order[0], $input, $executionUuid);
        } else {
            $context = \is_array($context) ? $context : [];
            $executionUuid = (string) ($context['flowUuid'] ?? '');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $executionUuid)) {
                throw new \RuntimeException('The debug context lost its flowUuid — restart the debug session.');
            }
        }

        $cursor = $index;
        $this->lastExecutedIds = [];
        try {
            do {
                $executed = $order[$cursor];
                $this->executeStepTracked($executed, $context, $flow, $executionUuid);
                if ($executed['type'] === 'choice') {
                    $order = $this->executionOrder($steps, $links, self::choiceResults($context));
                    $total = \count($order);
                }
                $cursor++;
            } while ($runToEnd && $cursor < $total);
        } finally {
            // Each call returns control to the user, so nothing is in flight BETWEEN debug steps —
            // stamping every call (not just the last) keeps a paused debug session from looking
            // like a run in progress and blocking the scheduler.
            $this->touchLastFinished($flow);
        }

        return [
            'context' => $context,
            // The id lets the editor highlight the tile that just executed.
            'step' => ['id' => $executed['id'], 'name' => $executed['name'], 'type' => $executed['type']],
            'index' => $cursor - 1,
            'total' => $total,
            'done' => $cursor >= $total,
        ];
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
        $byId = [];
        $trigger = null;
        foreach ($steps as $step) {
            $byId[$step['id']] = $step;
            if ($trigger === null && \in_array($step['type'], OntologyFlow::TRIGGER_STEP_TYPES, true)) {
                $trigger = $step;
            }
        }
        if ($trigger === null) {
            throw new \RuntimeException('The flow has no trigger to debug.');
        }

        $outgoing = [];
        foreach ($links as $link) {
            $outgoing[$link['from']][] = $link;
        }
        foreach ($outgoing as &$list) {
            usort($list, static fn (array $a, array $b) => $a['fromPort'] <=> $b['fromPort']);
        }
        unset($list);

        $visited = [$trigger['id'] => true];
        $queue = [$trigger['id']];
        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $step = $byId[$id];
            $order[] = $step;
            $follow = $outgoing[$id] ?? [];
            if ($step['type'] === 'choice') {
                if (!\array_key_exists($id, $choiceResults)) {
                    continue; // verdict pending — the path beyond is not known yet
                }
                $port = $choiceResults[$id] ? 0 : 1;
                $follow = array_values(array_filter($follow, static fn (array $l) => $l['fromPort'] === $port));
            }
            foreach ($follow as $link) {
                if (isset($byId[$link['to']]) && !isset($visited[$link['to']])) {
                    $visited[$link['to']] = true;
                    $queue[] = $link['to'];
                }
            }
        }

        return $order;
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
            $expression = \is_array($step['config']) && \is_string($step['config']['expression'] ?? null)
                ? trim($step['config']['expression'])
                : '';
            if ($expression === '') {
                throw new \RuntimeException(sprintf('Step "%s" is not configured.', $step['name']));
            }
            try {
                $result = $this->dwl->transform($expression, $context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('Step "%s": %s', $step['name'], $e->getMessage()), 0, $e);
            }
            $results = \is_array($context['choiceResults'] ?? null) ? $context['choiceResults'] : [];
            $results[$step['id']] = (bool) $result;
            $context['choiceResults'] = $results;

            return;
        }

        $fileOps = ['file_read', 'file_write', 'file_list', 'file_delete', 'file_rename'];
        if (!\in_array(
            $step['type'],
            array_merge(['dwl_transform', 'entity_read', 'entity_write', 'invoke', 'sql_query', 'sub_flow', 'logger', 'ms_teams'], $fileOps),
            true
        )) {
            return; // triggers seed the context in execute(); other types have no debug behaviour yet
        }

        $config = $step['config'];
        if (!\is_array($config)) {
            throw new \RuntimeException(sprintf('Step "%s" is not configured.', $step['name']));
        }

        // Call Subflow has no destination of its own: the subflow runs over the caller's context
        // and whatever it wrote there is what the next main-flow element sees.
        if ($step['type'] === 'sub_flow') {
            $context = $this->callSubflow($step['name'], $config, $context, $executionUuid);

            return;
        }

        // Logger has no destination either: it emits ONE line into the PHP application log,
        // prefixed with the flow's name. The message is hardcoded text, or — via its DWL
        // toggle — an expression resolved against the current context.
        if ($step['type'] === 'logger') {
            $raw = trim((string) ($config['message'] ?? ''));
            if ($raw === '') {
                throw new \RuntimeException(sprintf('Step "%s" is not configured.', $step['name']));
            }
            $text = $raw;
            if (($config['message_dwl'] ?? false) === true) {
                try {
                    $result = $this->dwl->transform($raw, $context);
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf('Step "%s": %s', $step['name'], $e->getMessage()), 0, $e);
                }
                // Strings go verbatim; anything else (objects, lists, numbers, booleans) as JSON.
                $text = \is_string($result)
                    ? $result
                    : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $this->logger->info(sprintf('[Aaxis Flow - %s] %s', $flow?->getName() ?? 'unsaved', $text));

            return;
        }

        // MS Teams has no destination either: it fires the configured Power Automate webhook
        // with the current payload as a Teams message and the flow simply continues.
        if ($step['type'] === 'ms_teams') {
            $this->msTeamsNotify($step['name'], $config, $context);

            return;
        }

        $destination = trim((string) ($config['destination'] ?? ''));
        if ($destination === '') {
            throw new \RuntimeException(sprintf('Step "%s" has no destination.', $step['name']));
        }

        if (\in_array($step['type'], $fileOps, true)) {
            $context[$destination] = $this->fileOperation($step['type'], $step['name'], $config, $context);

            return;
        }

        // The typed successors of the generic reader/writer (same config, no discriminator), and
        // "HTTP Request" (`invoke`) — a rest_api connector call, the response under the destination.
        if ($step['type'] === 'entity_read') {
            $context[$destination] = $this->readEntity($step['name'], $config);

            return;
        }
        if ($step['type'] === 'entity_write') {
            $context[$destination] = $this->writeEntity($step['name'], $config, $context, $flow, $executionUuid);

            return;
        }
        if ($step['type'] === 'invoke') {
            $context[$destination] = $this->readConnector($step['name'], $config, $context);

            return;
        }
        if ($step['type'] === 'sql_query') {
            $context[$destination] = $this->sqlQuery($step['name'], $config, $context);

            return;
        }

        // dwl_transform: the WHOLE current context is visible to the script (payload, prior
        // destinations…).
        try {
            $context[$destination] = $this->dwl->transform((string) ($config['code'] ?? ''), $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $step['name'], $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readEntity(string $stepName, array $config): mixed
    {
        $system = (string) ($config['system'] ?? '');
        $entityName = (string) ($config['entity'] ?? '');
        $mode = $config['mode'] ?? 'all';
        try {
            if ($mode === 'by_id') {
                return $this->dataApi->read($system, $entityName, (string) ($config['record_id'] ?? ''));
            }
            // "By attribute" yields the LIST of matches ([] when none) — an attribute, unlike the
            // unique id, may match any number of records.
            if ($mode === 'by_attribute') {
                return $this->dataApi->queryForFlowByAttribute(
                    $system,
                    $entityName,
                    (string) ($config['attribute'] ?? ''),
                    (string) ($config['attr_value'] ?? '')
                );
            }

            // Flow reads are NOT page-capped (the API's caps are for outside callers): "all"
            // really is every record, unless the step configures its own limit / ordering.
            $limit = $config['limit'] ?? null;

            return $this->dataApi->queryForFlow(
                $system,
                $entityName,
                trim((string) ($config['order_by'] ?? '')) ?: null,
                strtolower((string) ($config['order_dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC',
                \is_int($limit) && $limit > 0 ? $limit : null
            );
        } catch (OntologyApiException $e) {
            // A missing record is a legitimate outcome for "by id" — the step yields null.
            if ($e->getErrorCode() === 'record_not_found') {
                return null;
            }
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
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
            throw new \RuntimeException(sprintf(
                'Step "%s": the content must resolve to an object or an array of objects.',
                $stepName
            ));
        }

        $system = $this->doctrine->getRepository(OntologySystem::class)
            ->findOneBy(['name' => (string) ($config['system'] ?? '')]);
        $entity = $system === null ? null : $this->doctrine->getRepository(OntologyEntity::class)
            ->findOneBy(['system' => $system, 'name' => (string) ($config['entity'] ?? '')]);
        if ($entity === null) {
            throw new \RuntimeException(sprintf(
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
            $result = $this->dataApi->upsertRecordsSync($entity, $records, $stampFlow, $executionUuid);
        } catch (OntologyApiException $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
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
        /** @var OntologyConnector|null $connector */
        $connector = $this->doctrine->getRepository(OntologyConnector::class)->find((int) ($config['connector'] ?? 0));
        if ($connector === null) {
            throw new \RuntimeException(sprintf('Step "%s": the configured connector no longer exists.', $stepName));
        }
        if (!$this->files->supports((string) $connector->getType())) {
            throw new \RuntimeException(sprintf(
                'Step "%s": a "%s" connector is not file-based.',
                $stepName,
                (string) $connector->getType()
            ));
        }

        $path = $this->resolveDwlText($stepName, 'path', (string) ($config['path'] ?? ''), ($config['path_dwl'] ?? false) === true, $context);

        try {
            switch ($type) {
                case 'file_read':
                case 'file_list':
                    return $this->files->read($connector, $path);
                case 'file_write':
                    $value = $this->resolveContent($stepName, $config, $context);

                    return $this->files->write($connector, $path, \is_string($value) ? $value : (string) json_encode($value));
                case 'file_delete':
                    return $this->files->delete($connector, $path);
                default: // file_rename
                    $newName = $this->resolveDwlText(
                        $stepName,
                        'new name',
                        (string) ($config['new_name'] ?? ''),
                        ($config['new_name_dwl'] ?? false) === true,
                        $context
                    );

                    return $this->files->rename($connector, $path, $newName);
            }
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s.', $stepName, $e->getMessage()), 0, $e);
        }
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
            throw new \RuntimeException(sprintf('Step "%s" is not configured.', $stepName));
        }
        if (!\array_key_exists($variable, $context)) {
            throw new \RuntimeException(sprintf('Step "%s": the variable "%s" is not defined.', $stepName, $variable));
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
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Step "%s": the webhook responded HTTP %d.', $stepName, $status));
        }
    }

    private function resolveDwlText(string $stepName, string $what, string $raw, bool $isDwl, array $context): string
    {
        if (!$isDwl) {
            return trim($raw);
        }
        try {
            $value = $this->dwl->transform($raw, $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        }
        if (!\is_scalar($value) || trim((string) $value) === '') {
            throw new \RuntimeException(sprintf(
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
            try {
                return $this->dwl->transform($contentSource, $context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
            }
        }
        if (!\array_key_exists($contentSource, $context)) {
            throw new \RuntimeException(sprintf('Step "%s": %s "%s" is not available in the context.', $stepName, $key, $contentSource));
        }

        return $context[$contentSource];
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
        $id = (int) ($config['subflow'] ?? 0);
        /** @var OntologyFlow|null $target */
        $target = $id > 0 ? $this->doctrine->getRepository(OntologyFlow::class)->find($id) : null;
        if ($target === null) {
            throw new \RuntimeException(sprintf('Step "%s": the configured subflow no longer exists.', $stepName));
        }
        if ($target->getType() !== OntologyFlow::TYPE_SUBFLOW) {
            throw new \RuntimeException(sprintf(
                'Step "%s": "%s" is not a subflow (its trigger makes it a regular flow).',
                $stepName,
                (string) $target->getName()
            ));
        }
        if (!$target->isEnabled()) {
            throw new \RuntimeException(sprintf(
                'Step "%s": the subflow "%s" is disabled.',
                $stepName,
                (string) $target->getName()
            ));
        }
        $design = $target->getDesign();
        $subSteps = \is_array($design['steps'] ?? null) ? $design['steps'] : null;
        $subLinks = \is_array($design['links'] ?? null) ? $design['links'] : [];
        if ($subSteps === null || $subSteps === []) {
            throw new \RuntimeException(sprintf('Step "%s": the subflow "%s" has no steps.', $stepName, (string) $target->getName()));
        }
        if (\in_array($id, $this->subflowStack, true)) {
            throw new \RuntimeException(sprintf(
                'Step "%s": circular subflow call — "%s" is already running in this execution.',
                $stepName,
                (string) $target->getName()
            ));
        }
        if (\count($this->subflowStack) >= 10) {
            throw new \RuntimeException(sprintf('Step "%s": subflow calls nested deeper than 10 levels.', $stepName));
        }

        // The nested run resets the executed-ids trail (its step ids belong to ANOTHER canvas) —
        // preserve the caller's so the editor keeps painting the right tiles.
        $outerTrail = $this->lastExecutedIds;
        $this->subflowStack[] = $id;
        try {
            return $this->execute($subSteps, $subLinks, [], $target, $executionUuid, $context);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        } finally {
            array_pop($this->subflowStack);
            $this->lastExecutedIds = $outerTrail;
        }
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
        /** @var OntologyConnector|null $connector */
        $connector = $this->doctrine->getRepository(OntologyConnector::class)->find((int) ($config['connector'] ?? 0));
        if ($connector === null) {
            throw new \RuntimeException(sprintf('Step "%s": the configured connector no longer exists.', $stepName));
        }

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
            throw new \RuntimeException(sprintf('Step "%s": %s.', $stepName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function readConnector(string $stepName, array $config, array $context): mixed
    {
        /** @var OntologyConnector|null $connector */
        $connector = $this->doctrine->getRepository(OntologyConnector::class)->find((int) ($config['connector'] ?? 0));
        if ($connector === null) {
            throw new \RuntimeException(sprintf('Step "%s": the configured connector no longer exists.', $stepName));
        }
        $path = (string) ($config['path'] ?? '');
        if ($this->files->supports((string) $connector->getType())) {
            // Folder → a list of items; file → its content; failure → an isError payload.
            try {
                return $this->files->read($connector, $path);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(sprintf('Step "%s": %s.', $stepName, $e->getMessage()), 0, $e);
            }
        }
        if ($connector->getType() !== OntologyConnector::TYPE_REST_API) {
            return ['_debug' => sprintf('"%s" connectors are not executed in debug yet.', (string) $connector->getType())];
        }

        return $this->executeRestRead($stepName, $connector->getConfig() ?? [], $config, $context);
    }

    /**
     * Performs the REST call a rest_api connector reader describes. Secrets are stored in clear
     * in the connector config (masking is a render-time concern), so they can be used directly.
     *
     * @param array<string, mixed> $connectorConfig
     * @param array<string, mixed> $stepConfig
     * @param array<string, mixed> $context available to a DWL-toggled body (body_dwl)
     */
    private function executeRestRead(string $stepName, array $connectorConfig, array $stepConfig, array $context): mixed
    {
        $base = trim((string) ($connectorConfig['server'] ?? ''));
        if ($base === '') {
            throw new \RuntimeException(sprintf('Step "%s": the connector has no server configured.', $stepName));
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $base = rtrim($base, '/');
        $port = (int) ($connectorConfig['port'] ?? 0);
        if ($port > 0 && parse_url($base, PHP_URL_PORT) === null) {
            $base .= ':' . $port;
        }
        $url = $base . '/' . ltrim((string) ($stepConfig['path'] ?? ''), '/');

        $headers = $this->stringMap($connectorConfig['headers'] ?? null);
        $auth = (string) ($connectorConfig['auth'] ?? 'none');
        if ($auth === 'headers') {
            $headers = array_merge($headers, $this->stringMap($connectorConfig['auth_headers'] ?? null));
        } elseif ($auth === 'oauth') {
            $headers['Authorization'] = $this->fetchOAuthToken($stepName, $connectorConfig, $base);
        }

        $options = [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
            'max_redirects' => 5,
            // Internal endpoints commonly run self-signed certs — same policy as the toolbox proxy.
            'verify_peer' => false,
            'verify_host' => false,
        ];
        $bodyType = (string) ($stepConfig['body'] ?? 'empty');
        $bodyContent = (string) ($stepConfig['body_content'] ?? '');
        if ($bodyType !== 'empty' && $bodyContent !== '') {
            // The DWL toggle turns the body into an expression over the context; a string result
            // is sent verbatim, anything else as its JSON representation.
            if (($stepConfig['body_dwl'] ?? false) === true) {
                $bodyContent = $this->renderDwl($stepName, $bodyContent, $context);
            }
            $options['body'] = $bodyContent;
            $options['headers']['Content-Type'] = match ($bodyType) {
                'json' => 'application/json',
                'xml' => 'application/xml',
                default => 'text/plain',
            };
        }
        $method = strtoupper((string) ($stepConfig['operation'] ?? 'get')) ?: 'GET';

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Step "%s": the connector responded HTTP %d.', $stepName, $status));
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
    }

    /**
     * Evaluates a DWL-toggled text field against the context and renders the result as the text
     * to send: strings verbatim, everything else JSON-encoded.
     *
     * @param array<string, mixed> $context
     */
    private function renderDwl(string $stepName, string $code, array $context): string
    {
        try {
            $result = $this->dwl->transform($code, $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        }

        return \is_string($result) ? $result : (string) json_encode($result);
    }

    /**
     * OAuth (as modelled by the connector config): POST the token path with the configured
     * headers + form-encoded body and use the returned access_token as a bearer credential.
     *
     * @param array<string, mixed> $connectorConfig
     */
    private function fetchOAuthToken(string $stepName, array $connectorConfig, string $base): string
    {
        $oauth = \is_array($connectorConfig['oauth'] ?? null) ? $connectorConfig['oauth'] : [];
        $path = trim((string) ($oauth['path'] ?? ''));
        if ($path === '') {
            throw new \RuntimeException(sprintf('Step "%s": the connector has no OAuth token path configured.', $stepName));
        }

        try {
            $response = $this->httpClient->request('POST', $base . '/' . ltrim($path, '/'), [
                'headers' => $this->stringMap($oauth['headers'] ?? null),
                'body' => $this->stringMap($oauth['body'] ?? null),
                'timeout' => self::TIMEOUT,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": OAuth token request failed — %s', $stepName, $e->getMessage()), 0, $e);
        }

        $token = \is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException(sprintf('Step "%s": the OAuth response carries no access_token.', $stepName));
        }
        $type = \is_array($data) && \is_string($data['token_type'] ?? null) && $data['token_type'] !== ''
            ? $data['token_type'] : 'Bearer';

        return $type . ' ' . $token;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && $key !== '' && is_scalar($item)) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }
}
