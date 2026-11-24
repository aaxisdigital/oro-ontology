<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDwlScript;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\DwlInputs;
use Aaxis\Bundle\OntologyBundle\Manager\DwlOutputFormatter;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptGuard;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptManager;
use Aaxis\Bundle\OntologyBundle\Manager\EntityPayloadReader;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Oro\Bundle\UserBundle\Entity\User;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Data Playground page (data-playground-component): saved DWL scripts on the left, the
 * selected one (name, inputs, public), its code and the result row (max rows, Run, Save as…) on the right.
 *
 * Scripts are PRIVATE to their owner unless marked PUBLIC ({@see DwlScriptManager}): the list holds
 * the user's own scripts plus everyone's public ones; only the owner changes or deletes a script
 * (anyone who sees it may run it, or save a copy of their own). Somebody else's private script
 * answers 404, so its existence does not leak. The inputs are picked at RUN time — the stored ones
 * are only the defaults ({@see OntologyDwlScript}).
 *
 * Runs follow the Entities grid's DWL popup: each picked input entity's records are bound under
 * the input's variable name ({@see DwlInputs}, {@see EntityPayloadReader} — the same reading and
 * counting), the script is screened by {@see DwlScriptGuard}, reading record content needs
 * `OntologyData` VIEW, the script's `output` header decides how the result is rendered
 * ({@see DwlOutputFormatter}), and a script error is a 200 `{success: false, error}` the page
 * shows in its result pane.
 */
class OntologyDataPlaygroundController extends AbstractController
{
    private const string ACCESS_DENIED = 'Access denied.';
    private const string INVALID_BODY = 'Invalid request body.';

    #[Route(path: '/data-playground', name: 'aaxis_ontology_data_playground', options: ['expose' => true])]
    #[Template('@AaxisOntology/DataPlayground/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_dwl_script_view', type: 'entity', class: OntologyDwlScript::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    /**
     * The user's own scripts and everyone's public ones, plus the entities the run-time picker
     * offers (only with entity VIEW — otherwise the picker stays empty).
     */
    #[Route(path: '/data-playground/api/scripts', name: 'aaxis_ontology_dwl_script_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_dwl_script_view')]
    public function listAction(): JsonResponse
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->failure(403, self::ACCESS_DENIED);
        }
        $scripts = $this->scripts();

        return new JsonResponse([
            'scripts' => array_map(static fn (OntologyDwlScript $script): array => $scripts->serialize($script, $user), $scripts->visibleTo($user)),
            'entities' => $this->entityCatalog(),
        ]);
    }

    #[Route(path: '/data-playground/api/scripts', name: 'aaxis_ontology_dwl_script_create', options: ['expose' => true], methods: ['POST'])]
    #[Acl(id: 'aaxis_ontology_dwl_script_create', type: 'entity', class: OntologyDwlScript::class, permission: 'CREATE')]
    #[CsrfProtection]
    public function createAction(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return $user === null
            ? $this->failure(403, self::ACCESS_DENIED)
            : $this->save((new OntologyDwlScript())->setOwner($user), $request, $user);
    }

    #[Route(
        path: '/data-playground/api/scripts/{id}',
        name: 'aaxis_ontology_dwl_script_update',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['PUT']
    )]
    #[Acl(id: 'aaxis_ontology_dwl_script_update', type: 'entity', class: OntologyDwlScript::class, permission: 'EDIT')]
    #[CsrfProtection]
    public function updateAction(int $id, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $script = $this->scripts()->findVisible($id, $user);

        return $this->ownerRefusal($script, $user) ?? $this->save($script, $request, $user);
    }

    #[Route(
        path: '/data-playground/api/scripts/{id}',
        name: 'aaxis_ontology_dwl_script_delete',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['DELETE']
    )]
    #[Acl(id: 'aaxis_ontology_dwl_script_delete', type: 'entity', class: OntologyDwlScript::class, permission: 'DELETE')]
    #[CsrfProtection]
    public function deleteAction(int $id): JsonResponse
    {
        $user = $this->currentUser();
        $script = $this->scripts()->findVisible($id, $user);
        $refusal = $this->ownerRefusal($script, $user);
        if ($refusal === null) {
            $this->scripts()->delete($script);
        }

        return $refusal ?? new JsonResponse(['success' => true]);
    }

    /**
     * Runs a script — the editor's CURRENT text, saved or not — against the picked INPUTS: each
     * entity's records, ALL of them, bound under its variable name ({@see DwlInputs}). `limit` (max
     * rows, null/0 = all) only cuts the RESULT when it is a list ({@see cutRows()}). Body
     * `{script, inputs?: [{entityId, name}], limit?}`. The result is rendered by the script's
     * `output` header, like the Entities popup ({@see DwlOutputFormatter}): `{success: true,
     * result: <text>, format, mime, extension, inputs: [{name, records}], output: {rows, shown,
     * truncated} | null}`, or a script-level `{success: false, error, inputs}` (200).
     */
    #[Route(path: '/data-playground/api/run', name: 'aaxis_ontology_dwl_script_run', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_dwl_script_view')]
    #[CsrfProtection]
    public function runAction(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $source = \is_array($body) && \is_string($body['script'] ?? null) ? $body['script'] : '';
        $inputs = DwlInputs::parse(
            \is_array($body) ? ($body['inputs'] ?? null) : null,
            fn (int $id): ?OntologyEntity => $this->registry()->getRepository(OntologyEntity::class)->find($id)
        );
        $rejection = match (true) {
            !\is_array($body) => new JsonResponse(['success' => false, 'error' => self::INVALID_BODY], 400),
            trim($source) === '' => new JsonResponse(['success' => false, 'error' => $this->trans('script_empty')], 400),
            \is_string($inputs) => new JsonResponse(['success' => false, 'error' => $this->trans($inputs)], 422),
            $inputs !== [] && !$this->isGranted('VIEW', 'entity:' . OntologyData::class)
                => new JsonResponse(['success' => false, 'error' => self::ACCESS_DENIED], 403),
            default => $this->guardRefusal($source),
        };
        if ($rejection !== null || \is_string($inputs)) {
            return $rejection;
        }
        $rawLimit = $body['limit'] ?? null;

        return $this->execute($source, $inputs, is_numeric($rawLimit) && (int) $rawLimit > 0 ? (int) $rawLimit : null);
    }

    /**
     * @param list<array{name: string, entity: OntologyEntity}> $inputs
     *
     * @return JsonResponse the run's outcome (see {@see runAction()})
     */
    private function execute(string $source, array $inputs, ?int $limit): JsonResponse
    {
        $variables = [];
        $counts = [];
        $reader = new EntityPayloadReader($this->registry(), $this->container->get(BucketEntityDataStore::class));
        $formatter = $this->container->get(DwlOutputFormatter::class);
        $format = $formatter->detect($source);

        try {
            // The inputs are read IN FULL — max rows only cuts what the run shows. Inside the try: an
            // internal entity whose class cannot be read fails like a script would.
            foreach ($inputs as ['name' => $name, 'entity' => $entity]) {
                $variables[$name] = $reader->payloads($entity, null);
                $counts[] = ['name' => $name, 'records' => \count($variables[$name])];
            }
            [$result, $output] = self::cutRows(
                $this->container->get(DwlTransformer::class)->transform($source, $variables),
                $limit
            );

            return new JsonResponse([
                'success' => true,
                'result' => $formatter->serialize($result, $format['format']),
                'inputs' => $counts,
                'output' => $output,
            ] + $format);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage(), 'inputs' => $counts]);
        }
    }

    /**
     * MAX ROWS: a result that is a LIST (what becomes CSV lines and XML items) keeps its first
     * `$limit` rows; an object or a single value is a result of its own and stays whole (null).
     *
     * @return array{0: mixed, 1: array{rows: int, shown: int, truncated: bool}|null}
     */
    private static function cutRows(mixed $result, ?int $limit): array
    {
        if (!\is_array($result) || !array_is_list($result)) {
            return [$result, null];
        }
        $rows = \count($result);
        if ($limit !== null && $rows > $limit) {
            $result = \array_slice($result, 0, $limit);
        }

        return [$result, ['rows' => $rows, 'shown' => \count($result), 'truncated' => $rows > \count($result)]];
    }

    /**
     * Create/update: `{name, script, public, inputs, limit}` — the script and its
     * playground metadata, validated by {@see DwlScriptManager::save()}.
     */
    private function save(OntologyDwlScript $script, Request $request, User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $refusal = \is_array($body) ? $this->scripts()->save($script, $body) : null;
        if (!\is_array($body) || $refusal !== null) {
            return $this->failure(\is_array($body) ? 422 : 400, \is_array($body) ? $this->trans((string) $refusal) : self::INVALID_BODY);
        }

        return new JsonResponse(['success' => true, 'script' => $this->scripts()->serialize($script, $user)]);
    }

    /**
     * Why this user cannot change the script — not visible to them (404), or not theirs (403) —
     * or null when they own it.
     *
     * @phpstan-assert-if-false !null $script
     * @phpstan-assert-if-false !null $user
     */
    private function ownerRefusal(?OntologyDwlScript $script, ?User $user): ?JsonResponse
    {
        return match (true) {
            $user === null || $script === null => $this->failure(404, $this->trans('not_found')),
            !$script->isOwnedBy($user) => $this->failure(403, $this->trans('not_owner')),
            default => null,
        };
    }

    /**
     * The entities the input picker offers, by system then name.
     *
     * @return list<array{id: ?int, name: ?string, system: ?string}>
     */
    private function entityCatalog(): array
    {
        if (!$this->isGranted('VIEW', 'entity:' . OntologyEntity::class)) {
            return [];
        }
        /** @var OntologyEntity[] $entities */
        $entities = $this->registry()->getRepository(OntologyEntity::class)->findBy([], ['name' => 'ASC']);
        $catalog = array_map(static fn (OntologyEntity $entity): array => [
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'system' => $entity->getSystem()?->getName(),
        ], $entities);
        usort($catalog, static fn (array $a, array $b): int => strcasecmp((string) $a['system'], (string) $b['system'])
            ?: strcasecmp((string) $a['name'], (string) $b['name']));

        return $catalog;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * The guard's refusal as a script-level outcome (shown in the result pane), or null when the
     * script may run.
     */
    private function guardRefusal(string $source): ?JsonResponse
    {
        $refusal = $this->container->get(DwlScriptGuard::class)->check($source);

        return $refusal !== null ? new JsonResponse(['success' => false, 'error' => $refusal]) : null;
    }

    private function failure(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }

    private function trans(string $key): string
    {
        return $this->container->get(TranslatorInterface::class)->trans(
            'aaxis.ontology.data_playground.' . $key,
            $key === 'name_too_long' ? ['%max%' => OntologyDwlScript::NAME_MAX_LENGTH] : []
        );
    }

    private function scripts(): DwlScriptManager
    {
        return $this->container->get(DwlScriptManager::class);
    }

    private function registry(): ManagerRegistry
    {
        return $this->container->get(ManagerRegistry::class);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
            TranslatorInterface::class,
            BucketEntityDataStore::class,
            DwlTransformer::class,
            DwlScriptGuard::class,
            DwlScriptManager::class,
            DwlOutputFormatter::class,
        ]);
    }
}
