<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * View of the Ontology "Flow" records, rendered by the reusable TypeScript DataGrid
 * widget (flow-component) via the JSON endpoint below.
 *
 * The "Add Flow" experience is intentionally not implemented yet (the step editor is a larger
 * design effort); the page exposes the button only.
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

    #[Route(path: '/flows/api/list', name: 'aaxis_ontology_flow_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_flow_view')]
    public function listAction(): JsonResponse
    {
        $registry = $this->container->get(ManagerRegistry::class);

        /** @var OntologyFlow[] $flows */
        $flows = $registry->getRepository(OntologyFlow::class)->findBy([], ['name' => 'ASC']);

        return new JsonResponse([
            'records' => array_map($this->serialize(...), $flows),
        ]);
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
