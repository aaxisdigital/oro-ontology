<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyDataEvent;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of the Ontology "Data Event" records, rendered by the reusable
 * TypeScript DataGrid widget (event-component) via the JSON endpoint below.
 */
class OntologyEventController extends AbstractController
{
    #[Route(path: '/events', name: 'aaxis_ontology_events')]
    #[Template('@AaxisOntology/Event/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_event_view', type: 'entity', class: OntologyDataEvent::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    #[Route(path: '/events/api/list', name: 'aaxis_ontology_event_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_event_view')]
    public function listAction(): JsonResponse
    {
        $registry = $this->container->get(ManagerRegistry::class);

        /** @var OntologyDataEvent[] $records */
        $records = $registry->getRepository(OntologyDataEvent::class)->findBy([], ['startedAt' => 'DESC']);

        // aaxis_ontology_data_events has no FK to entity/flow, so resolve display names via lookups.
        $entityNames = [];
        foreach ($registry->getRepository(OntologyEntity::class)->findAll() as $entity) {
            $entityNames[$entity->getId()] = $entity->getName();
        }
        $flowNames = [];
        foreach ($registry->getRepository(OntologyFlow::class)->findAll() as $flow) {
            $flowNames[$flow->getId()] = $flow->getName();
        }

        return new JsonResponse([
            'records' => array_map(
                fn (OntologyDataEvent $event) => $this->serialize($event, $entityNames, $flowNames),
                $records
            ),
        ]);
    }

    /**
     * @param array<int, string|null> $entityNames
     * @param array<int, string|null> $flowNames
     *
     * @return array<string, mixed>
     */
    private function serialize(OntologyDataEvent $event, array $entityNames, array $flowNames): array
    {
        // simple_array reads an EMPTY column back as [''] (one blank element), which would show as
        // a count of 1 with no id — drop blanks so the counts the grid prefixes are truthful.
        $uniqueIds = array_values(array_filter($event->getUniqueIds() ?? [], static fn ($id) => $id !== ''));
        $changedIds = array_values(array_filter($event->getChangedIds() ?? [], static fn ($id) => $id !== ''));

        // Derived, never stored: still running / finished clean / finished with an error. The raw
        // word is what the grid's Status column filters and sorts on; the error text rides along.
        $status = 'running';
        if ($event->getFinishedAt() !== null) {
            $status = $event->getError() === null ? 'success' : 'error';
        }

        return [
            'id' => $event->getId(),
            'flowId' => $event->getFlowId(),
            'flow' => $event->getFlowId() !== null ? ($flowNames[$event->getFlowId()] ?? null) : null,
            'uuid' => $event->getUuid(),
            'entityId' => $event->getEntityId(),
            'entity' => $event->getEntityId() !== null ? ($entityNames[$event->getEntityId()] ?? null) : null,
            'uniqueIds' => implode(', ', $uniqueIds),
            'uniqueIdsCount' => \count($uniqueIds),
            'changedIds' => implode(', ', $changedIds),
            'changedIdsCount' => \count($changedIds),
            'startedAt' => $event->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'finishedAt' => $event->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'status' => $status,
            'error' => $event->getError(),
        ];
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
        ]);
    }
}
