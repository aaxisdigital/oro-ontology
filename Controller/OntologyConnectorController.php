<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Form\Type\OntologyConnectorType;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD controller for the Ontology "Connector" entity.
 */
class OntologyConnectorController extends AbstractOntologyController
{
    #[Route(path: '/connectors', name: 'aaxis_ontology_connectors')]
    #[Template('@AaxisOntology/Connector/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_connector_view', type: 'entity', class: OntologyConnector::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return ['gridName' => 'aaxis-ontology-connectors-grid'];
    }

    #[Route(path: '/connectors/view/{id}', name: 'aaxis_ontology_connector_view', requirements: ['id' => '\d+'])]
    #[Template('@AaxisOntology/Connector/view.html.twig')]
    #[AclAncestor('aaxis_ontology_connector_view')]
    public function viewAction(OntologyConnector $entity): array
    {
        return ['entity' => $entity];
    }

    #[Route(path: '/connectors/create', name: 'aaxis_ontology_connector_create')]
    #[Template('@AaxisOntology/Connector/update.html.twig')]
    #[Acl(id: 'aaxis_ontology_connector_create', type: 'entity', class: OntologyConnector::class, permission: 'CREATE')]
    public function createAction(Request $request): array|RedirectResponse
    {
        return $this->updateEntity(new OntologyConnector(), OntologyConnectorType::class, 'aaxis.ontology.connector.saved', $request);
    }

    #[Route(path: '/connectors/update/{id}', name: 'aaxis_ontology_connector_update', requirements: ['id' => '\d+'])]
    #[Template('@AaxisOntology/Connector/update.html.twig')]
    #[Acl(id: 'aaxis_ontology_connector_update', type: 'entity', class: OntologyConnector::class, permission: 'EDIT')]
    public function updateAction(OntologyConnector $entity, Request $request): array|RedirectResponse
    {
        return $this->updateEntity($entity, OntologyConnectorType::class, 'aaxis.ontology.connector.saved', $request);
    }

    #[Route(path: '/connectors/delete/{id}', name: 'aaxis_ontology_connector_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Acl(id: 'aaxis_ontology_connector_delete', type: 'entity', class: OntologyConnector::class, permission: 'DELETE')]
    #[CsrfProtection]
    public function deleteAction(OntologyConnector $entity): JsonResponse
    {
        return $this->deleteEntity($entity);
    }
}
