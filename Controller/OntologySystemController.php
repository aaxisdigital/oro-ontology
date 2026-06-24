<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
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

        // Grouped counts for all systems at once (internal systems' record totals are summed from
        // their OroCommerce tables per entity, inside recordCount()).
        $entityCounts = $this->entityCountsBySystem();
        $externalRecordTotals = $this->externalRecordTotalsBySystem();

        return new JsonResponse([
            'systems' => array_map(
                fn (OntologySystem $system) => $this->serialize($system, $entityCounts, $externalRecordTotals),
                $systems
            ),
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
        // Internal systems (external = false, e.g. the built-in "OroCommerce") cannot be deleted.
        if (!$entity->isExternal()) {
            return new JsonResponse([
                'successful' => false,
                'message' => $this->trans('aaxis.ontology.system_manager.delete_internal_forbidden'),
            ], 422);
        }

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
     * @param array<int, int>|null $entityCounts         system id => number of entities
     * @param array<int, int>|null $externalRecordTotals system id => ontology-data records
     *
     * @return array<string, mixed>
     */
    private function serialize(OntologySystem $system, ?array $entityCounts = null, ?array $externalRecordTotals = null): array
    {
        return [
            'id' => $system->getId(),
            'name' => $system->getName(),
            'enabled' => $system->isEnabled(),
            // Read-only in the UI: user-created systems are always external; only the seeded
            // "OroCommerce" system is internal. `save()` deliberately never writes this.
            'external' => $system->isExternal(),
            'entityCount' => $this->entityCount($system, $entityCounts),
            // Total records held by all of the system's entities: ontology data for external
            // systems, the OroCommerce tables themselves for the internal system.
            'recordCount' => $this->recordCount($system, $externalRecordTotals),
            // How many flows depend on this system's entities. Not implemented yet — hard-coded.
            'flowCount' => 0,
        ];
    }

    private function entityCount(OntologySystem $system, ?array $entityCounts): int
    {
        if ($entityCounts !== null) {
            return (int) ($entityCounts[(int) $system->getId()] ?? 0);
        }

        return (int) $this->registry()->getRepository(OntologyEntity::class)->count(['system' => $system]);
    }

    private function recordCount(OntologySystem $system, ?array $externalRecordTotals): int
    {
        if (!$system->isExternal()) {
            return $this->internalSystemRecordCount($system);
        }

        if ($externalRecordTotals !== null) {
            return (int) ($externalRecordTotals[(int) $system->getId()] ?? 0);
        }

        return (int) $this->registry()->getManagerForClass(OntologyData::class)
            ->createQuery('SELECT COUNT(d.id) FROM ' . OntologyData::class . ' d JOIN d.entity e WHERE e.system = :system')
            ->setParameter('system', $system)
            ->getSingleScalarResult();
    }

    /** Sums the OroCommerce-table record counts of every entity mapped under an internal system. */
    private function internalSystemRecordCount(OntologySystem $system): int
    {
        $total = 0;
        foreach ($this->registry()->getRepository(OntologyEntity::class)->findBy(['system' => $system]) as $entity) {
            $total += $this->oroEntityRecordCount((string) $entity->getName());
        }

        return $total;
    }

    /**
     * @return array<int, int> system id => number of entities
     */
    private function entityCountsBySystem(): array
    {
        $rows = $this->registry()->getManagerForClass(OntologyEntity::class)
            ->createQuery(
                'SELECT IDENTITY(e.system) AS systemId, COUNT(e.id) AS cnt'
                . ' FROM ' . OntologyEntity::class . ' e GROUP BY e.system'
            )
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['systemId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @return array<int, int> system id => total ontology-data records across the system's entities
     */
    private function externalRecordTotalsBySystem(): array
    {
        $rows = $this->registry()->getManagerForClass(OntologyData::class)
            ->createQuery(
                'SELECT IDENTITY(e.system) AS systemId, COUNT(d.id) AS cnt'
                . ' FROM ' . OntologyData::class . ' d JOIN d.entity e GROUP BY e.system'
            )
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['systemId']] = (int) $row['cnt'];
        }

        return $totals;
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
