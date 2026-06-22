<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for the Ontology "System" entity.
 *
 * The page is a TypeScript-driven UI (system-component) backed by the JSON endpoints below.
 */
class OntologySystemController extends AbstractOntologyController
{
    #[Route(path: '/systems', name: 'aaxis_ontology_systems')]
    #[Template('@AaxisOntology/System/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_system_view', type: 'entity', class: OntologySystem::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    #[Route(path: '/systems/api/list', name: 'aaxis_ontology_system_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_system_view')]
    public function listAction(): JsonResponse
    {
        $systems = $this->registry()->getRepository(OntologySystem::class)->findBy([], ['name' => 'ASC']);

        return new JsonResponse([
            'systems' => array_map($this->serialize(...), $systems),
        ]);
    }

    #[Route(path: '/systems/api', name: 'aaxis_ontology_system_api_create', options: ['expose' => true], methods: ['POST'])]
    #[Acl(id: 'aaxis_ontology_system_create', type: 'entity', class: OntologySystem::class, permission: 'CREATE')]
    #[CsrfProtection]
    public function apiCreateAction(Request $request): JsonResponse
    {
        return $this->save(new OntologySystem(), $request);
    }

    #[Route(
        path: '/systems/api/{id}',
        name: 'aaxis_ontology_system_api_update',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['PUT', 'POST']
    )]
    #[Acl(id: 'aaxis_ontology_system_update', type: 'entity', class: OntologySystem::class, permission: 'EDIT')]
    #[CsrfProtection]
    public function apiUpdateAction(OntologySystem $entity, Request $request): JsonResponse
    {
        return $this->save($entity, $request);
    }

    #[Route(path: '/systems/delete/{id}', name: 'aaxis_ontology_system_delete', requirements: ['id' => '\d+'], options: ['expose' => true], methods: ['DELETE'])]
    #[Acl(id: 'aaxis_ontology_system_delete', type: 'entity', class: OntologySystem::class, permission: 'DELETE')]
    #[CsrfProtection]
    public function deleteAction(OntologySystem $entity): JsonResponse
    {
        return $this->deleteEntity($entity);
    }

    /**
     * Creates/updates a system from a JSON body ({name, enabled}).
     */
    private function save(OntologySystem $entity, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.system_manager.name_required'),
            ], 422);
        }

        $existing = $this->registry()->getRepository(OntologySystem::class)->findOneBy(['name' => $name]);
        if ($existing !== null && $existing->getId() !== $entity->getId()) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('aaxis.ontology.system_manager.name_unique'),
            ], 422);
        }

        $entity->setName($name);
        $entity->setEnabled((bool) ($payload['enabled'] ?? true));

        $em = $this->registry()->getManagerForClass(OntologySystem::class);
        $em->persist($entity);
        $em->flush();

        return new JsonResponse(['success' => true, 'system' => $this->serialize($entity)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OntologySystem $system): array
    {
        return [
            'id' => $system->getId(),
            'name' => $system->getName(),
            'enabled' => $system->isEnabled(),
        ];
    }

    private function registry(): ManagerRegistry
    {
        return $this->container->get(ManagerRegistry::class);
    }

    private function trans(string $key): string
    {
        return $this->container->get(TranslatorInterface::class)->trans($key);
    }
}
