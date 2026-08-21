<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of the Ontology FLOW-EXECUTION events (aaxis_ontology_flow_events — one row per
 * flow-start / flow-finish / flow-exception / data-upsert / log-message / step event, written
 * asynchronously by the flow-event queue processor), rendered by the reusable TypeScript DataGrid
 * widget (event-component) via the JSON endpoint below.
 */
class OntologyEventController extends AbstractController
{
    #[Route(path: '/events', name: 'aaxis_ontology_events')]
    #[Template('@AaxisOntology/Event/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_event_view', type: 'entity', class: OntologyFlowEvent::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    #[Route(path: '/events/api/list', name: 'aaxis_ontology_event_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_event_view')]
    public function listAction(): JsonResponse
    {
        $registry = $this->container->get(ManagerRegistry::class);

        /** @var OntologyFlowEvent[] $records */
        $records = $registry->getRepository(OntologyFlowEvent::class)->findBy([], ['datetime' => 'DESC', 'id' => 'DESC']);

        return new JsonResponse([
            'records' => array_map(static fn (OntologyFlowEvent $event): array => [
                'id' => $event->getId(),
                'flowId' => $event->getFlowId(),
                'flowName' => $event->getFlowName(),
                'flowUuid' => $event->getFlowUuid(),
                'event' => $event->getEvent(),
                // The raw column value is micro-precision text (see the entity) — normalize to
                // ATOM (UTC) for the grid's date formatter.
                'datetime' => $event->getDatetime() !== null
                    ? date_create($event->getDatetime(), new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
                    : null,
                'payload' => $event->getPayload(),
            ], $records),
        ]);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
        ]);
    }
}
