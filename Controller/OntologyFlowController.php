<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor;
use Cron\CronExpression;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
class OntologyFlowController extends AbstractController
{
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
     * Debug-executes the CURRENT editor state (steps + links + trigger input) without saving:
     * walks the graph from the trigger via {@see FlowDebugExecutor} and returns the accumulated
     * output context, which the editor presents as JSON.
     */
    #[Route(path: '/flows/api/debug', name: 'aaxis_ontology_flow_debug', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_flow_update')]
    #[CsrfProtection]
    public function debugAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $steps = [];
        $ids = [];
        foreach (\is_array($payload['steps'] ?? null) ? $payload['steps'] : [] as $step) {
            $id = \is_string($step['id'] ?? null) ? $step['id'] : '';
            $config = $step['config'] ?? null;
            if (!\is_array($step) || $id === '' || isset($ids[$id])
                || !\in_array($step['type'] ?? null, OntologyFlow::STEP_TYPES, true)
                || !\is_string($step['name'] ?? null)
                || ($config !== null && (!\is_array($config) || array_is_list($config)))
            ) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid debug steps.'], 400);
            }
            $ids[$id] = true;
            $steps[] = ['id' => $id, 'type' => $step['type'], 'name' => $step['name'], 'config' => $config];
        }
        $links = [];
        foreach (\is_array($payload['links'] ?? null) ? $payload['links'] : [] as $link) {
            if (!\is_array($link) || !isset($ids[$link['from'] ?? null]) || !isset($ids[$link['to'] ?? null])
                || !\is_int($link['fromPort'] ?? null)
            ) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid debug links.'], 400);
            }
            $links[] = ['from' => $link['from'], 'fromPort' => $link['fromPort'], 'to' => $link['to']];
        }
        $input = \is_array($payload['input'] ?? null) && !array_is_list($payload['input']) ? $payload['input'] : [];

        // Writers stamp their upserts with the flow being debugged; a never-saved flow has no id
        // yet (flowId null) and the executor falls back to the built-in Manual flow.
        $flow = null;
        if (($payload['flowId'] ?? null) !== null) {
            $flow = \is_int($payload['flowId'])
                ? $this->container->get(ManagerRegistry::class)->getRepository(OntologyFlow::class)->find($payload['flowId'])
                : null;
            if ($flow === null) {
                return new JsonResponse(['success' => false, 'message' => 'The flow being debugged no longer exists.'], 422);
            }
        }

        try {
            $output = $this->container->get(FlowDebugExecutor::class)->execute($steps, $links, $input, $flow);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true, 'output' => $output]);
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
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.flow_manager.name_required'),
            ], 422);
        }

        $existing = $this->registry()->getRepository(OntologyFlow::class)->findOneBy(['name' => $name]);
        if ($existing !== null && $existing->getId() !== $entity->getId()) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.flow_manager.name_unique'),
            ], 422);
        }

        if (\array_key_exists('design', $payload)) {
            // The design is the editor's own (versioned) canvas representation — stored opaquely;
            // the editor validates it on load and treats unreadable values as corrupted.
            if ($payload['design'] !== null && !\is_array($payload['design'])) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid design.'], 400);
            }
            $entity->setDesign($payload['design']);
        }

        if (\array_key_exists('steps', $payload)) {
            $steps = $this->normalizeSteps($payload['steps']);
            if ($steps === null) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid steps.'], 400);
            }
            $stepNames = array_map(static fn (array $s) => mb_strtolower($s['name']), $steps);
            if (\count($stepNames) !== \count(array_unique($stepNames))) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->trans('aaxis.ontology.flow_manager.step_names_unique'),
                ], 422);
            }
            // A step's config is optional (unconfigured steps may be saved mid-design), but a
            // PRESENT config must be complete and valid for its type.
            foreach ($steps as $step) {
                $expression = $step['config']['expression'] ?? null;
                if ($step['type'] === 'cron' && $expression !== null
                    && !CronExpression::isValidExpression((string) $expression)
                ) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $this->trans('aaxis.ontology.flow_manager.invalid_cron', ['{{ name }}' => $step['name']]),
                    ], 422);
                }
                if (!$this->isStepConfigValid($step)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $this->trans('aaxis.ontology.flow_manager.invalid_step_config', ['{{ name }}' => $step['name']]),
                    ], 422);
                }
                // DWL scripts must parse to be saved.
                $code = $step['config']['code'] ?? null;
                if ($step['type'] === 'dwl_transform' && \is_string($code) && trim($code) !== '') {
                    $dwlError = $this->container->get(DwlTransformer::class)->validate($code);
                    if ($dwlError !== null) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => $this->trans('aaxis.ontology.flow_manager.invalid_dwl', [
                                '{{ name }}' => $step['name'],
                                '{{ error }}' => $dwlError,
                            ]),
                        ], 422);
                    }
                }
            }
            $entity->setSteps($steps === [] ? null : $steps);
        }

        $entity->setName($name);
        $entity->setEnabled((bool) ($payload['enabled'] ?? true));
        $entity->setType(OntologyFlow::computeType($entity->getSteps()));

        $em = $this->registry()->getManagerForClass(OntologyFlow::class);
        $em->persist($entity);
        $em->flush();

        return new JsonResponse(['success' => true, 'flow' => $this->serialize($entity)]);
    }

    /**
     * Validates/normalizes the editor's steps payload: a list of {type, name, x, y, config} where
     * type is a known toolbox step type, name is a non-empty step label (uniqueness is checked by
     * the caller), x/y are non-negative canvas coordinates and config is an optional per-type
     * object (content validated by the caller). Returns null when the payload is malformed.
     *
     * @return array<int, array{type: string, name: string, x: int, y: int, config: array<string, mixed>|null}>|null
     */
    private function normalizeSteps(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $steps = [];
        foreach ($raw as $step) {
            $name = \is_string($step['name'] ?? null) ? trim($step['name']) : '';
            $config = $step['config'] ?? null;
            if (!\is_array($step) || !\in_array($step['type'] ?? null, OntologyFlow::STEP_TYPES, true)
                || $name === '' || mb_strlen($name) > 64
                || !is_numeric($step['x'] ?? null) || !is_numeric($step['y'] ?? null)
                || ($config !== null && (!\is_array($config) || array_is_list($config)))
            ) {
                return null;
            }
            $steps[] = [
                'type' => (string) $step['type'],
                'name' => $name,
                'x' => max(0, (int) $step['x']),
                'y' => max(0, (int) $step['y']),
                'config' => $config,
            ];
        }

        return $steps;
    }

    /**
     * Type-specific completeness of a PRESENT step config (null config = not configured yet):
     *  - entity_change: non-empty `system` and `entity`;
     *  - reader: `reader` = entity (with system+entity) or connector (with connector+path),
     *    plus a non-empty `destination`.
     *
     * @param array{type: string, name: string, config: array<string, mixed>|null} $step
     */
    private function isStepConfigValid(array $step): bool
    {
        $config = $step['config'];
        if ($config === null) {
            return true;
        }
        $filled = static fn (string $key): bool => \is_string($config[$key] ?? null) && trim($config[$key]) !== '';

        // Enum keys are lenient when ABSENT (configs saved by older editors) but strict when set.
        $enumOk = static fn (string $key, array $allowed): bool =>
            !isset($config[$key]) || \in_array($config[$key], $allowed, true);

        return match ($step['type']) {
            'entity_change' => $filled('system') && $filled('entity'),
            'dwl_transform' => $filled('code') && $filled('destination'),
            'reader' => \is_string($config['destination'] ?? null) && trim($config['destination']) !== ''
                && match ($config['reader'] ?? null) {
                    'entity' => $filled('system') && $filled('entity')
                        && $enumOk('mode', ['all', 'by_id'])
                        && (($config['mode'] ?? 'all') !== 'by_id' || $filled('record_id')),
                    'connector' => is_scalar($config['connector'] ?? null) && (string) $config['connector'] !== ''
                        && $filled('path')
                        && $enumOk('operation', ['get', 'put', 'post', 'patch', 'delete'])
                        && $enumOk('body', ['empty', 'json', 'text', 'xml']),
                    default => false,
                },
            'writer' => \is_string($config['destination'] ?? null) && trim($config['destination']) !== ''
                && match ($config['writer'] ?? null) {
                    'entity' => $filled('system') && $filled('entity') && $filled('content'),
                    'connector' => is_scalar($config['connector'] ?? null) && (string) $config['connector'] !== ''
                        && $filled('path')
                        && $enumOk('operation', ['get', 'put', 'post', 'patch', 'delete'])
                        && $enumOk('body', ['empty', 'json', 'text', 'xml']),
                    default => false,
                },
            default => true,
        };
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
            DwlTransformer::class,
        ]);
    }
}
