<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\FlowImportException;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor;
use Aaxis\Bundle\OntologyBundle\Manager\FlowPortability;
use Aaxis\Bundle\OntologyBundle\Manager\FlowHistoryArchiver;
use Aaxis\Bundle\OntologyBundle\Manager\FlowStepValidator;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Cache\CacheItemPoolInterface;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * View of the Ontology "Flow" records, rendered by the reusable TypeScript DataGrid
 * widget (flow-component) via the JSON endpoint below, plus the flow editor page
 * (flow-editor-component): top bar (name, enabled switch, cancel/save) over a dot-matrix
 * canvas holding the draggable step toolbox and the placed step tiles ({type, name, x, y},
 * persisted in `steps`; step names are unique per flow). Wiring steps together is the next
 * design step.
 *
 * Only user-created flows can be edited; the two built-in flows seeded by the data fixture
 * (type = native) are read-only. A user flow's type is recomputed from its steps on every
 * save: `flow` when a trigger step is present, `subflow` otherwise.
 */
class OntologyFlowController extends AbstractOntologyController
{
    private const string INVALID_PAYLOAD = 'Invalid payload.';
    private const string SESSION_EXPIRED = 'The debug session expired — restart the debug.';
    private const string SESSION_TIMED_OUT = 'The debug session was terminated by timeout — restart the debug.';

    /** A flow document is small; anything larger is not one. */
    private const int MAX_IMPORT_BYTES = 2 * 1024 * 1024;

    #[Route(path: '/flows', name: 'aaxis_ontology_flows')]
    #[Template('@AaxisOntology/Flow/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_flow_view', type: 'entity', class: OntologyFlow::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    /**
     * The flow editor: without {id} it creates a new flow, with {id} it edits an existing
     * (custom-only) one.
     *
     * @return array<string, mixed>
     */
    #[Route(
        path: '/flows/editor/{id}',
        name: 'aaxis_ontology_flow_editor',
        requirements: ['id' => '\d+'],
        defaults: ['id' => null],
        options: ['expose' => true]
    )]
    #[Template('@AaxisOntology/Flow/editor.html.twig')]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function editorAction(?int $id = null): array
    {
        $flow = null;
        if ($id !== null) {
            $flow = $this->registry()->getRepository(OntologyFlow::class)->find($id);
            if ($flow === null) {
                throw $this->createNotFoundException('Flow not found.');
            }
            if ($flow->isNative()) {
                throw new AccessDeniedHttpException(
                    $this->trans('aaxis.ontology.flow_manager.edit_builtin_forbidden')
                );
            }
        }

        return [
            'flow' => $flow !== null ? $this->serialize($flow) : null,
            'gridSpacing' => $this->gridSpacing(),
            'stepSizeFactor' => $this->stepSizeFactor(),
            'debugAutoCloseSeconds' => max(0, (int) $this->container->get(ConfigManager::class)->get('aaxis_ontology.flow_debug_autoclose_seconds')),
        ];
    }

    #[Route(path: '/flows/api/list', name: 'aaxis_ontology_flow_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function listAction(): JsonResponse
    {
        /** @var OntologyFlow[] $flows */
        $flows = $this->registry()->getRepository(OntologyFlow::class)->findBy([], ['name' => 'ASC']);

        return new JsonResponse([
            'records' => array_map($this->serialize(...), $flows),
        ]);
    }

    /** The "Invoke PHP" step's class type-ahead: every app-namespace service class. */
    #[Route(path: '/flows/api/php-classes', name: 'aaxis_ontology_flow_php_classes', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function phpClassesAction(): JsonResponse
    {
        return new JsonResponse(['classes' => $this->container->get(PhpMethodInvoker::class)->invokableClasses()]);
    }

    /**
     * The "Invoke PHP" step's method type-ahead: the PUBLIC methods of one invokable class, each
     * with its parameter shapes so the editor can pre-fill the DWL parameters template
     * client-side (no third round trip).
     */
    #[Route(path: '/flows/api/php-methods', name: 'aaxis_ontology_flow_php_methods', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function phpMethodsAction(Request $request): JsonResponse
    {
        $class = ltrim(trim((string) $request->query->get('class', '')), '\\');
        if (!$this->container->get(PhpMethodInvoker::class)->isInvokable($class)) {
            return new JsonResponse(['error' => 'Unknown class.'], 404);
        }

        $methods = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isAbstract() || $method->isConstructor() || $method->isDestructor()
                || str_starts_with($method->getName(), '__')
            ) {
                continue;
            }
            $methods[] = [
                'name' => $method->getName(),
                'params' => array_map(static fn (\ReflectionParameter $p): array => [
                    'name' => $p->getName(),
                    'type' => $p->getType() instanceof \ReflectionType ? (string) $p->getType() : 'mixed',
                    'required' => !$p->isDefaultValueAvailable(),
                ], $method->getParameters()),
            ];
        }
        usort($methods, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return new JsonResponse(['methods' => $methods]);
    }

    #[Route(path: '/flows/api', name: 'aaxis_ontology_flow_api_create', options: ['expose' => true], methods: ['POST'])]
    #[Acl(id: 'aaxis_ontology_flow_create', type: 'entity', class: OntologyFlow::class, permission: 'CREATE')]
    #[CsrfProtection]
    public function apiCreateAction(Request $request): JsonResponse
    {
        // User-created flows are always custom (the entity default); save() never writes the flag.
        return $this->save(new OntologyFlow(), $request);
    }

    #[Route(
        path: '/flows/api/{id}',
        name: 'aaxis_ontology_flow_api_update',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['PUT', 'POST']
    )]
    #[Acl(id: 'aaxis_ontology_flow_update', type: 'entity', class: OntologyFlow::class, permission: 'EDIT')]
    #[CsrfProtection]
    public function apiUpdateAction(OntologyFlow $entity, Request $request): JsonResponse
    {
        // Built-in flows (type = native) are read-only.
        if ($entity->isNative()) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.flow_manager.edit_builtin_forbidden'),
            ], 422);
        }

        return $this->save($entity, $request);
    }

    /**
     * Deletes a flow. Built-ins (type = native) are refused — the Data View "Add Data" path and
     * the REST API depend on them. Events the flow recorded keep their rows (flow_id has no FK;
     * the Events page then shows the id without a name).
     */
    #[Route(path: '/flows/delete/{id}', name: 'aaxis_ontology_flow_delete', requirements: ['id' => '\d+'], options: ['expose' => true], methods: ['DELETE'])]
    #[Acl(id: 'aaxis_ontology_flow_delete', type: 'entity', class: OntologyFlow::class, permission: 'DELETE')]
    #[CsrfProtection]
    public function deleteAction(OntologyFlow $entity): JsonResponse
    {
        if ($entity->isNative()) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.flow_manager.delete_builtin_forbidden'),
            ], 422);
        }

        return $this->deleteEntity($entity);
    }

    /**
     * The flow's archived revisions (aaxis_ontology_flow_history, newest first) with their full
     * definitions — the editor's "Flow history" popup diffs the current canvas against them.
     * Rows are written by {@see FlowHistoryArchiver} only when a save replaces an EXECUTED
     * revision, so a flow edited but never run in between has no rows here.
     */
    #[Route(
        path: '/flows/api/{id}/history',
        name: 'aaxis_ontology_flow_history_list',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['GET']
    )]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function historyAction(OntologyFlow $entity): JsonResponse
    {
        $rows = $this->registry()->getConnection()->fetchAllAssociative(
            'SELECT version, name, steps, design, last_executed, archived_at
             FROM aaxis_ontology_flow_history
             WHERE flow_id = ?
             ORDER BY version DESC',
            [$entity->getId()]
        );
        $atom = static fn (?string $raw): ?string => $raw !== null
            ? date_create($raw, new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
            : null;

        return new JsonResponse([
            'versions' => array_map(static fn (array $row): array => [
                'version' => (int) $row['version'],
                'name' => $row['name'],
                'steps' => json_decode((string) $row['steps'], true),
                'design' => json_decode((string) $row['design'], true),
                'lastExecuted' => $atom($row['last_executed']),
                'archivedAt' => $atom($row['archived_at']),
            ], $rows),
        ]);
    }

    /**
     * Exports a flow as a portable JSON document (see {@see FlowPortability}) — connector ids are
     * rewritten as name/type descriptors so another environment can match them. The client saves
     * the returned document as a file.
     */
    #[Route(
        path: '/flows/api/{id}/export',
        name: 'aaxis_ontology_flow_export',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['GET']
    )]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function exportAction(OntologyFlow $entity): JsonResponse
    {
        // Built-in flows are fixture-seeded in every environment — nothing to carry across, and an
        // import could not recreate one anyway (it always builds a user flow).
        if ($entity->isNative()) {
            return new JsonResponse([
                'success' => false,
                'errors' => [$this->trans('aaxis.ontology.flow_portability.export_builtin_forbidden')],
            ], 422);
        }

        try {
            $document = $this->container->get(FlowPortability::class)->export($entity);
        } catch (FlowImportException $e) {
            return new JsonResponse(['success' => false, 'errors' => $e->getErrors()], 422);
        }

        return new JsonResponse([
            'success' => true,
            'document' => $document,
            'filename' => $this->exportFilename((string) $entity->getName()),
        ]);
    }

    /**
     * Creates a flow from a previously exported document. Everything is validated first — format,
     * a free name, the same step rules a normal save enforces, and that every referenced
     * connector/entity exists here — and ALL problems come back at once; the flow lands disabled.
     */
    #[Route(path: '/flows/api/import', name: 'aaxis_ontology_flow_import', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_flow_create')]
    #[CsrfProtection]
    public function importAction(Request $request): JsonResponse
    {
        [$document, $error] = $this->parseImportRequest($request);
        if ($error !== null) {
            return new JsonResponse([
                'success' => false,
                'errors' => [$this->trans('aaxis.ontology.flow_portability.' . $error)],
            ], 422);
        }

        try {
            $flow = $this->container->get(FlowPortability::class)->import($document);
        } catch (FlowImportException $e) {
            return new JsonResponse(['success' => false, 'errors' => $e->getErrors()], 422);
        }

        return new JsonResponse(['success' => true, 'flow' => $this->serialize($flow)]);
    }

    /**
     * The decoded flow document of an import request, or the translation key (under
     * aaxis.ontology.flow_portability.) of what is wrong with it.
     *
     * @return array{0: mixed, 1: string|null}
     */
    private function parseImportRequest(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $raw = \is_array($payload) ? ($payload['document'] ?? null) : null;
        $error = match (true) {
            !\is_string($raw) || trim($raw) === '' => 'invalid_format',
            // Refusing an oversized document here beats letting the web server answer with an
            // HTML error page the client cannot parse.
            \strlen($raw) > self::MAX_IMPORT_BYTES => 'too_large',
            default => null,
        };
        if ($error !== null) {
            return [null, $error];
        }

        // Decoded here (not by the client) so a malformed or absurdly nested file is rejected the
        // same way as any other bad document.
        try {
            return [json_decode($raw, true, 64, JSON_THROW_ON_ERROR), null];
        } catch (\JsonException) {
            return [null, 'invalid_format'];
        }
    }

    /** A filesystem-friendly file name for an exported flow. */
    private function exportFilename(string $flowName): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $flowName), '-'));

        return sprintf('flow-%s.json', $slug === '' ? 'export' : $slug);
    }

    /**
     * Debug-executes the CURRENT editor state (steps + links + trigger input) without saving:
     * walks the graph from the trigger via {@see FlowDebugExecutor} and returns the accumulated
     * output context, which the editor presents as JSON.
     */
    #[Route(path: '/flows/api/debug', name: 'aaxis_ontology_flow_debug', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_flow_update')]
    #[CsrfProtection]
    public function debugAction(Request $request): JsonResponse
    {
        $parsed = $this->parseDebugPayload(json_decode($request->getContent(), true));
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$steps, $links, $input, $flow] = $parsed;

        $executor = $this->container->get(FlowDebugExecutor::class);
        try {
            $output = $executor->execute($steps, $links, $input, $flow, null, null, $this->debugRunInfo());
        } catch (\RuntimeException $e) {
            return $this->debugFailure($e);
        }

        // The final context is stored too, so the debugger sidebar's evaluator works after a run.
        return new JsonResponse([
            'success' => true,
            'output' => $output,
            'executedIds' => $executor->lastExecutedIds(),
            'contextKey' => $this->storeDebugContext($output),
        ]);
    }

    /**
     * Step-by-step debug ("Debug" button; the full run above is "Run Now"): executes ONE step of
     * the execution order — or the rest of it when runAll — against the context accumulated so
     * far. The context is held SERVER-SIDE between calls (cache, keyed by the run's flowUuid):
     * the client only sends `contextKey` back — round-tripping a large context (a reader loading
     * thousands of records) through the request body used to blow past the web server's body
     * size limit, which answered with an HTML error page. Returns the new context (for display)
     * plus `contextKey` and the progress metadata ({step, index, total, done}).
     */
    #[Route(path: '/flows/api/debug-step', name: 'aaxis_ontology_flow_debug_step', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_flow_update')]
    #[CsrfProtection]
    public function debugStepAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $prepared = $this->prepareDebugStep($payload);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$steps, $links, $input, $flow, $blob] = $prepared;

        $executor = $this->container->get(FlowDebugExecutor::class);
        try {
            $result = $executor->debugWalk(
                $steps,
                $links,
                $input,
                $blob,
                $flow,
                ($payload['runAll'] ?? false) === true,
                $this->debugRunInfo(),
                ($payload['stepInto'] ?? false) === true,
                ($payload['stepOut'] ?? false) === true
            );
        } catch (\RuntimeException $e) {
            return $this->debugFailure($e, $executor->subflowTrails(...));
        }

        // Every tick refreshes the inactivity clock.
        $result['blob']['lastTickAt'] = time();

        return new JsonResponse([
            'success' => true,
            'contextKey' => $this->storeDebugContext($result['blob']),
        ] + $result['response']);
    }

    /**
     * The definition, trigger input, flow and — when the request resumes a session — the stored
     * walk state of a step-debug call, or the error response to answer with.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>, 3: OntologyFlow|null, 4: array<string, mixed>|null}|JsonResponse
     */
    private function prepareDebugStep(mixed $payload): array|JsonResponse
    {
        $parsed = $this->parseDebugPayload($payload);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        // The walk state (context + the frame STACK — step-into descends into subflows) lives
        // server-side; the client only round-trips the key. No key = a fresh session.
        $contextKey = $payload['contextKey'] ?? null;
        $session = \is_string($contextKey) && $contextKey !== '' ? $this->resumeSession($contextKey) : null;

        return $session instanceof JsonResponse ? $session : [...$parsed, $session];
    }

    /**
     * The stored walk state of a session, or the 422 explaining why it cannot be resumed: expired
     * (missing, or a legacy plain context that cannot resume a walk), already terminated, or idle
     * for longer than the configured inactivity timeout — a session past it is DEAD: the run is
     * closed with a flow-exception "debug-timeout" event and stepping it further is refused.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function resumeSession(string $contextKey): array|JsonResponse
    {
        $stored = $this->loadDebugContext($contextKey);
        // The blob is {context, frames, done}.
        $blob = \is_array($stored['frames'] ?? null) ? $stored : null;

        $refusal = match (true) {
            $blob === null => new JsonResponse(['success' => false, 'message' => self::SESSION_EXPIRED], 422),
            ($blob['terminated'] ?? null) !== null => new JsonResponse(['success' => false, 'message' => self::SESSION_TIMED_OUT], 422),
            $this->isIdle($blob) => $this->terminateSession($blob),
            default => null,
        };

        return $refusal ?? $blob;
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function isIdle(array $blob): bool
    {
        $timeoutMinutes = max(0, (int) $this->container->get(ConfigManager::class)->get('aaxis_ontology.flow_debug_timeout_minutes'));

        return $timeoutMinutes > 0 && isset($blob['lastTickAt']) && time() - (int) $blob['lastTickAt'] > $timeoutMinutes * 60;
    }

    /**
     * Closes an idle session's run with a "debug-timeout" flow-exception event and marks the
     * stored walk terminated, so the next call is refused without a second event.
     *
     * @param array<string, mixed> $blob
     */
    private function terminateSession(array $blob): JsonResponse
    {
        $root = $blob['frames'][0] ?? [];
        $this->container->get(OntologyFlowEventEmitter::class)->emitRaw(
            isset($root['flowId']) ? (int) $root['flowId'] : null,
            $root['flowName'] ?? null,
            (string) ($blob['context']['flowUuid'] ?? '') ?: null,
            'flow-exception',
            ['message' => 'debug-timeout']
        );
        $blob['terminated'] = 'debug-timeout';
        $this->storeDebugContext($blob);

        return new JsonResponse(['success' => false, 'message' => self::SESSION_TIMED_OUT], 422);
    }

    /**
     * The 422 for a failed run: the editor paints the trail — executed tiles green, the failing
     * one red — so a step failure carries the step id and the executed ids (and, for the step
     * debugger, the subflow trails).
     *
     * @param (callable(): array<string, mixed>)|null $subflowTrails
     */
    private function debugFailure(\RuntimeException $e, ?callable $subflowTrails = null): JsonResponse
    {
        $body = ['success' => false, 'message' => $e->getMessage()];
        if ($e instanceof FlowStepFailure) {
            $body += ['failedStepId' => $e->stepId, 'executedIds' => $e->executedIds];
            if ($subflowTrails !== null) {
                $body['subflowTrails'] = $subflowTrails();
            }
        }

        return new JsonResponse($body, 422);
    }

    /**
     * Evaluates ONE DWL expression against a debug session's CURRENT variables — the debugger
     * sidebar's evaluator. The context comes from the server-side session store (`contextKey`).
     */
    #[Route(path: '/flows/api/debug-eval', name: 'aaxis_ontology_flow_debug_eval', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_flow_update')]
    #[CsrfProtection]
    public function debugEvalAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $expression = \is_array($payload) && \is_string($payload['expression'] ?? null) ? trim($payload['expression']) : '';
        $stored = $this->loadDebugContext(\is_array($payload) ? ($payload['contextKey'] ?? null) : null);
        // The stored blob wraps the context since the step-into debugger ({context, frames, done}).
        $context = \is_array($stored['frames'] ?? null) ? ($stored['context'] ?? null) : $stored;

        $rejection = match (true) {
            $expression === '' => new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400),
            $context === null => new JsonResponse(['success' => false, 'message' => self::SESSION_EXPIRED], 422),
            default => null,
        };
        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $output = $this->container->get(DwlTransformer::class)->transform($expression, $context);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true, 'output' => $output]);
    }

    /**
     * Stores a debug session's context in the app cache under its flowUuid and returns that key
     * (null when the context has no valid uuid — nothing is stored then).
     *
     * @param array<string, mixed> $context
     */
    private function storeDebugContext(array $context): ?string
    {
        // Two shapes live here: Run Now stores the final CONTEXT (uuid at the top level), the
        // step debugger stores the whole WALK BLOB ({context, frames, done} — uuid inside).
        $key = $context['flowUuid'] ?? ($context['context']['flowUuid'] ?? null);
        if (!\is_string($key) || !preg_match('/^[0-9a-f-]{36}$/i', $key)) {
            return null;
        }
        $pool = $this->container->get(CacheItemPoolInterface::class);
        $item = $pool->getItem('aaxis_ontology_debug_ctx.' . strtolower($key));
        $item->set($context);
        $item->expiresAfter(3600); // a debug session left open for an hour has expired anyway
        $pool->save($item);

        return $key;
    }

    /**
     * @return array<string, mixed>|null the stored context, or null (missing/expired/bad key)
     */
    private function loadDebugContext(mixed $key): ?array
    {
        if (!\is_string($key) || !preg_match('/^[0-9a-f-]{36}$/i', $key)) {
            return null;
        }
        $item = $this->container->get(CacheItemPoolInterface::class)->getItem('aaxis_ontology_debug_ctx.' . strtolower($key));
        $value = $item->isHit() ? $item->get() : null;

        return \is_array($value) ? $value : null;
    }

    /**
     * Validates/normalizes the shared debug payload of both debug endpoints, or answers with the
     * error response directly when it is not even an object.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>, 3: OntologyFlow|null}|JsonResponse
     */
    private function parseDebugPayload(mixed $payload): array|JsonResponse
    {
        return \is_array($payload)
            ? $this->parseDebugDefinition($payload)
            : new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400);
    }

    /**
     * Validates/normalizes the shared debug payload (steps, links, trigger input, flowId) used by
     * both debug endpoints. Returns the error response directly when something is malformed.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>, 3: OntologyFlow|null}|JsonResponse
     */
    private function parseDebugDefinition(array $payload): array|JsonResponse
    {
        $steps = $this->debugSteps($payload['steps'] ?? null);
        $links = $steps === null ? null : $this->debugLinks($payload['links'] ?? null, $steps);
        $input = \is_array($payload['input'] ?? null) && !array_is_list($payload['input']) ? $payload['input'] : [];

        // Writers stamp their upserts with the flow being debugged; a never-saved flow has no id
        // yet (flowId null) and the executor falls back to the built-in Manual flow.
        $flowId = $payload['flowId'] ?? null;
        $flow = $links !== null && \is_int($flowId)
            ? $this->container->get(ManagerRegistry::class)->getRepository(OntologyFlow::class)->find($flowId)
            : null;

        $error = match (true) {
            $steps === null => [400, 'Invalid debug steps.'],
            $links === null => [400, 'Invalid debug links.'],
            $flowId !== null && $flow === null => [422, 'The flow being debugged no longer exists.'],
            default => null,
        };

        return $error !== null
            ? new JsonResponse(['success' => false, 'message' => $error[1]], $error[0])
            : [$steps, $links, $input, $flow];
    }

    /**
     * @return array<int, array<string, mixed>>|null the normalized steps, null when any is malformed
     */
    private function debugSteps(mixed $raw): ?array
    {
        $steps = [];
        $ids = [];
        foreach (\is_array($raw) ? $raw : [] as $step) {
            if (!$this->isValidDebugStep($step, $ids)) {
                return null;
            }
            $ids[$step['id']] = true;
            $steps[] = ['id' => $step['id'], 'type' => $step['type'], 'name' => $step['name'], 'config' => $step['config'] ?? null];
        }

        return $steps;
    }

    /**
     * A debug step: an object with a unique non-empty string id, a known type, a string name and
     * a config that is absent or an object.
     *
     * @param array<string, bool> $ids the ids of the steps accepted so far
     */
    private function isValidDebugStep(mixed $step, array $ids): bool
    {
        if (!\is_array($step)) {
            return false;
        }
        $id = \is_string($step['id'] ?? null) ? $step['id'] : '';
        $config = $step['config'] ?? null;

        return $id !== ''
            && !isset($ids[$id])
            && \in_array($step['type'] ?? null, OntologyFlow::STEP_TYPES, true)
            && \is_string($step['name'] ?? null)
            && ($config === null || (\is_array($config) && !array_is_list($config)));
    }

    /**
     * @param array<int, array<string, mixed>> $steps the (valid) debug steps the links must connect
     *
     * @return array<int, array<string, mixed>>|null the normalized links, null when any is malformed
     */
    private function debugLinks(mixed $raw, array $steps): ?array
    {
        $ids = array_flip(array_column($steps, 'id'));
        $links = [];
        foreach (\is_array($raw) ? $raw : [] as $link) {
            if (!\is_array($link) || !isset($ids[$link['from'] ?? null]) || !isset($ids[$link['to'] ?? null])
                || !\is_int($link['fromPort'] ?? null)
            ) {
                return null;
            }
            $links[] = ['from' => $link['from'], 'fromPort' => $link['fromPort'], 'to' => $link['to']];
        }

        return $links;
    }

    /**
     * The flow-start event bits for editor-driven runs: trigger "debug" plus the acting
     * back-office user's name/email.
     *
     * @return array{trigger: string, user?: array{name: string, email: string}}
     */
    private function debugRunInfo(): array
    {
        $info = ['trigger' => 'debug'];
        $user = $this->getUser();
        if (\is_object($user)) {
            $first = method_exists($user, 'getFirstName') ? (string) $user->getFirstName() : '';
            $last = method_exists($user, 'getLastName') ? (string) $user->getLastName() : '';
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = (string) $user->getUserIdentifier();
            }
            $info['user'] = [
                'name' => $name,
                'email' => method_exists($user, 'getEmail') ? (string) $user->getEmail() : '',
            ];
        }

        return $info;
    }

    /**
     * Creates/updates a flow from a JSON body ({name, enabled, steps}). The type is deliberately
     * never read from the payload — it is recomputed from the steps on every save (native flows
     * never reach this method).
     */
    private function save(OntologyFlow $entity, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400);
        }

        // Snapshot the STORED definition before the payload mutates the entity — the archiver
        // needs the version being replaced (history is written only for executed revisions).
        $previousName = (string) $entity->getName();
        $previousSteps = $entity->getSteps();
        $previousDesign = $entity->getDesign();

        // The flow's NAME is the TRIGGER STEP's name (the top-bar input is gone) — a triggerless
        // canvas keeps the stored name, and a brand new one gets a generated placeholder.
        $rejection = $this->applyDefinition($entity, $payload);
        $name = $rejection === null ? $this->deriveFlowName($entity) : '';
        $rejection ??= $this->nameRejection($entity, $name);
        if ($rejection !== null) {
            return $rejection;
        }

        $entity->setName($name);
        // Enabled is DERIVED, not taken from the payload: the trigger step's `enabled` config flag
        // is the one switch (a missing/unconfigured trigger reads as disabled — a broken entry
        // point must not run). The column stays synced so the grid and the scheduler keep their
        // plain reads.
        $entity->setEnabled(OntologyFlow::computeEnabled($entity->getSteps()));
        $entity->setType(OntologyFlow::computeType($entity->getSteps()));
        $entity->setTriggerType(OntologyFlow::computeTriggerType($entity->getSteps()));
        // Every save rewrites the flow definition — that IS a modification.
        $entity->setLastModified(new \DateTime('now', new \DateTimeZone('UTC')));

        // History: archive the replaced definition first — but only when it actually RAN (an
        // unexecuted revision is simply overwritten, see FlowHistoryArchiver).
        if ($entity->getId() !== null) {
            $this->container->get(FlowHistoryArchiver::class)->archiveIfExecuted(
                $entity->getId(),
                $previousName,
                $previousSteps,
                $previousDesign,
                $entity->getSteps(),
                $entity->getDesign()
            );
        }

        $em = $this->registry()->getManagerForClass(OntologyFlow::class);
        $em->persist($entity);
        $em->flush();

        return new JsonResponse(['success' => true, 'flow' => $this->serialize($entity)]);
    }

    /**
     * Applies the payload's `design` and `steps` (each only when present) to the entity, or
     * returns the error response for an unusable one.
     *
     * @param array<string, mixed> $payload
     */
    private function applyDefinition(OntologyFlow $entity, array $payload): ?JsonResponse
    {
        if (\array_key_exists('design', $payload)) {
            // The design is the editor's own (versioned) canvas representation — stored opaquely;
            // the editor validates it on load and treats unreadable values as corrupted.
            if ($payload['design'] !== null && !\is_array($payload['design'])) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid design.'], 400);
            }
            $entity->setDesign($payload['design']);
        }
        if (!\array_key_exists('steps', $payload)) {
            return null;
        }

        // The same rules an IMPORTED flow must clear — see Manager/FlowStepValidator.
        $validator = $this->container->get(FlowStepValidator::class);
        $steps = $validator->normalize($payload['steps']);
        // Only STRUCTURAL problems block a save (duplicate names): incomplete/invalid step
        // configs are storable — the editor marks them red and the flow cannot RUN until they are
        // fixed (the executor re-checks the FULL bar).
        $structural = $steps === null ? [] : $validator->structuralErrors($steps);
        $rejection = match (true) {
            $steps === null => new JsonResponse(['success' => false, 'message' => 'Invalid steps.'], 400),
            $structural !== [] => new JsonResponse(['success' => false, 'message' => $structural[0]], 422),
            default => null,
        };
        if ($rejection === null) {
            $entity->setSteps($steps === [] ? null : $steps);
        }

        return $rejection;
    }

    /** The 422 for a flow name another flow already carries (names are unique), null when free. */
    private function nameRejection(OntologyFlow $entity, string $name): ?JsonResponse
    {
        $existing = $this->registry()->getRepository(OntologyFlow::class)->findOneBy(['name' => $name]);

        return $existing !== null && $existing->getId() !== $entity->getId()
            ? new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.flow_manager.name_unique'),
            ], 422)
            : null;
    }

    /** Trigger step name → flow name; else the stored name; else a generated placeholder. */
    private function deriveFlowName(OntologyFlow $entity): string
    {
        foreach ($entity->getSteps() ?? [] as $step) {
            if (\is_array($step) && \in_array($step['type'] ?? null, OntologyFlow::TRIGGER_STEP_TYPES, true)
                && \is_string($step['name'] ?? null) && trim($step['name']) !== ''
            ) {
                return trim($step['name']);
            }
        }

        $current = trim((string) $entity->getName());

        return $current !== '' ? $current : 'new_flow_' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OntologyFlow $flow): array
    {
        return [
            'id' => $flow->getId(),
            'name' => $flow->getName(),
            'enabled' => $flow->isEnabled(),
            // Read-only in the UI: native = the two seeded built-ins (edit is disabled for them);
            // user flows are `flow`/`subflow`, recomputed from the steps on every save.
            'type' => $flow->getType(),
            'steps' => $flow->getSteps(),
            'design' => $flow->getDesign(),
            'lastExecuted' => $flow->getLastExecuted()?->format(\DateTimeInterface::ATOM),
            'lastFinished' => $flow->getLastFinished()?->format(\DateTimeInterface::ATOM),
            // Derived, not stored: last_executed with no matching last_finished yet.
            'running' => $flow->isRunning(),
            'lastModified' => $flow->getLastModified()?->format(\DateTimeInterface::ATOM),
            // Step NAMES whose config keeps the flow from running — the editor paints them red
            // and disables Run Now / Debug while any exist.
            'invalidSteps' => $this->container->get(FlowStepValidator::class)
                ->invalidStepNames($flow->getSteps() ?? []),
        ];
    }

    /** Dot-matrix spacing (px) of the flow editor canvas, from System Configuration. */
    private function gridSpacing(): int
    {
        $spacing = (int) $this->container->get(ConfigManager::class)->get('aaxis_ontology.flow_editor_grid_spacing');

        return max(4, min(100, $spacing ?: 10));
    }

    /** Step tile size as a multiple of the dot spacing (tile side = factor × spacing px). */
    private function stepSizeFactor(): int
    {
        $factor = (int) $this->container->get(ConfigManager::class)->get('aaxis_ontology.flow_editor_step_size_factor');

        return max(2, min(16, $factor ?: 8));
    }

    private function registry(): ManagerRegistry
    {
        return $this->container->get(ManagerRegistry::class);
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->container->get(TranslatorInterface::class)->trans($key, $params);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
            TranslatorInterface::class,
            ConfigManager::class,
            FlowDebugExecutor::class,
            FlowPortability::class,
            FlowStepValidator::class,
            FlowHistoryArchiver::class,
            OntologyFlowEventEmitter::class,
            PhpMethodInvoker::class,
            DwlTransformer::class,
            CacheItemPoolInterface::class,
        ]);
    }
}
