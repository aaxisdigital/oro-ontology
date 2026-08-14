<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Executes a flow definition in DEBUG mode: walks the links breadth-first from the trigger and
 * accumulates an output context keyed by the steps' destinations, which the editor shows as JSON.
 *
 * Per-type behaviour (grows as step types gain execution semantics):
 *  - entity_change trigger: seeds the context with the payload the user provided (`payload` key);
 *  - reader/entity: real data via {@see OntologyDataApiManager} (`all` = EVERY record — flow
 *    reads are not page-capped — optionally ordered by one attribute and/or limited by the step's
 *    own config; `by_id` = one record's payload — a MISSING record yields null, not an error);
 *  - reader/connector: rest_api connectors execute for real (URL from the connector's
 *    server/port + the step's path; connector headers + auth headers; auth=oauth first POSTs the
 *    token path and attaches the returned access_token; the step's operation/body are honoured);
 *    sftp/file_system connectors emit a placeholder note;
 *  - dwl_transform: runs the step's DataWeave script with the whole context as variables (also
 *    reachable as one `context` object, e.g. context["flow-uuid"]);
 *  - writer/entity: performs the real upsert SYNCHRONOUSLY (same validation and PG function as
 *    the Data View "Add Data", but no queue), stamped with the flow being debugged (Manual only
 *    when the flow was never saved) and the execution uuid — the receipt carries the actual
 *    outcome ({uuid, count, upsert, changedIds}) and the event row completes on the spot;
 *  - everything else: no-op pass-through for now.
 *
 * Every TOP-LEVEL execution mints ONE uuid up front, seeded into the context as "flow-uuid": all
 * writes of the run share it, so their events/records group under a single identity. Sub-flows
 * never mint their own — their caller passes its uuid down (execute()'s $executionUuid).
 */
class FlowDebugExecutor
{
    private const int TIMEOUT = 30;

    public function __construct(
        private readonly OntologyDataApiManager $dataApi,
        private readonly ManagerRegistry $doctrine,
        private readonly HttpClientInterface $httpClient,
        private readonly DwlTransformer $dwl,
    ) {
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
    public function execute(array $steps, array $links, array $input, ?OntologyFlow $flow = null, ?string $executionUuid = null): array
    {
        $order = $this->executionOrder($steps, $links);
        $this->touchLastExecuted($flow);

        // One uuid identifies the WHOLE execution: every writer stamps its upsert with it (all
        // events/records of a run group under it), and steps can read it from the context —
        // DWL scripts via context["flow-uuid"] (a hyphen is not a valid DWL identifier).
        // Minted only for a top-level run: a sub-flow inherits its caller's uuid via the param.
        $executionUuid ??= $this->dataApi->generateUuid();

        $context = $this->initialContext($order[0], $input, $executionUuid);
        foreach ($order as $step) {
            $this->executeStep($step, $context, $flow, $executionUuid);
        }

        return $context;
    }

    /**
     * Step-by-step debug: executes the step at $index of the execution order (or from it to the
     * end when $runToEnd), starting from the CLIENT-HELD context accumulated by the previous
     * steps. Index 0 seeds a fresh context — minting the run's flow-uuid — and ignores $context;
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
        $order = $this->executionOrder($steps, $links);
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
            $executionUuid = (string) ($context['flow-uuid'] ?? '');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $executionUuid)) {
                throw new \RuntimeException('The debug context lost its flow-uuid — restart the debug session.');
            }
        }

        $cursor = $index;
        do {
            $executed = $order[$cursor];
            $this->executeStep($executed, $context, $flow, $executionUuid);
            $cursor++;
        } while ($runToEnd && $cursor < $total);

        return [
            'context' => $context,
            'step' => ['name' => $executed['name'], 'type' => $executed['type']],
            'index' => $cursor - 1,
            'total' => $total,
            'done' => $cursor >= $total,
        ];
    }

    /**
     * The execution order: breadth-first along the links from the trigger (choice branches by
     * port order) — reachable steps only, the trigger itself first.
     *
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     *
     * @return array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}>
     */
    private function executionOrder(array $steps, array $links): array
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
            $order[] = $byId[$id];
            foreach ($outgoing[$id] ?? [] as $link) {
                if (isset($byId[$link['to']]) && !isset($visited[$link['to']])) {
                    $visited[$link['to']] = true;
                    $queue[] = $link['to'];
                }
            }
        }

        return $order;
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
     * The run's starting context: the execution uuid plus the trigger's event payload.
     *
     * @param array{type: string} $trigger
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function initialContext(array $trigger, array $input, string $executionUuid): array
    {
        $context = ['flow-uuid' => $executionUuid];
        if ($trigger['type'] === 'entity_change' && \array_key_exists('payload', $input)) {
            $context['payload'] = $input['payload'];
        }

        return $context;
    }

    /**
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $context
     */
    private function executeStep(array $step, array &$context, ?OntologyFlow $flow, string $executionUuid): void
    {
        if (!\in_array($step['type'], ['reader', 'dwl_transform', 'writer'], true)) {
            return; // triggers seed the context in execute(); other types have no debug behaviour yet
        }

        $config = $step['config'];
        if (!\is_array($config)) {
            throw new \RuntimeException(sprintf('Step "%s" is not configured.', $step['name']));
        }
        $destination = trim((string) ($config['destination'] ?? ''));
        if ($destination === '') {
            throw new \RuntimeException(sprintf('Step "%s" has no destination.', $step['name']));
        }

        if ($step['type'] === 'dwl_transform') {
            // The WHOLE current context is visible to the script (payload, prior destinations…).
            try {
                $context[$destination] = $this->dwl->transform((string) ($config['code'] ?? ''), $context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('Step "%s": %s', $step['name'], $e->getMessage()), 0, $e);
            }

            return;
        }

        if ($step['type'] === 'writer') {
            if (($config['writer'] ?? null) === 'entity') {
                $context[$destination] = $this->writeEntity($step['name'], $config, $context, $flow, $executionUuid);
            } else {
                $context[$destination] = ['_debug' => 'Connector writers are not executed in debug yet.'];
            }

            return;
        }

        if (($config['reader'] ?? null) === 'entity') {
            $context[$destination] = $this->readEntity($step['name'], $config);
        } else {
            $context[$destination] = $this->readConnector($step['name'], $config, $context);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readEntity(string $stepName, array $config): mixed
    {
        $system = (string) ($config['system'] ?? '');
        $entityName = (string) ($config['entity'] ?? '');
        try {
            if (($config['mode'] ?? 'all') === 'by_id') {
                return $this->dataApi->read($system, $entityName, (string) ($config['record_id'] ?? ''));
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
     * entity's unique_attribute. An EMPTY value (null / [] / "") is not an error — the write is
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
        $contentSource = trim((string) ($config['content'] ?? ''));
        if (($config['content_dwl'] ?? false) === true) {
            // The DWL toggle turns the content into an expression over the context; its result is
            // the record(s) to write.
            try {
                $value = $this->dwl->transform($contentSource, $context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
            }
        } else {
            if (!\array_key_exists($contentSource, $context)) {
                throw new \RuntimeException(sprintf('Step "%s": content "%s" is not available in the context.', $stepName, $contentSource));
            }
            $value = $context[$contentSource];
        }
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
