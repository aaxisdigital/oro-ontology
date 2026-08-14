<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Form\Type\OntologyConnectorType;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorConfigSecrets;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Doctrine\Persistence\ManagerRegistry;
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

    /** Lightweight list for pickers (e.g. the flow editor's reader step) — no config exposed. */
    #[Route(path: '/connectors/api/list', name: 'aaxis_ontology_connector_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_connector_view')]
    public function listJsonAction(): JsonResponse
    {
        /** @var OntologyConnector[] $connectors */
        $connectors = $this->container->get(ManagerRegistry::class)
            ->getRepository(OntologyConnector::class)->findBy([], ['name' => 'ASC']);

        return new JsonResponse([
            'records' => array_map(static fn (OntologyConnector $c) => [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'type' => $c->getType(),
                'systemName' => $c->getSystem()?->getName(),
            ], $connectors),
        ]);
    }

    #[Route(path: '/connectors/view/{id}', name: 'aaxis_ontology_connector_view', requirements: ['id' => '\d+'])]
    #[Template('@AaxisOntology/Connector/view.html.twig')]
    #[AclAncestor('aaxis_ontology_connector_view')]
    public function viewAction(OntologyConnector $entity): array
    {
        // Secrets never reach the page — the config is rendered from the masked copy.
        return [
            'entity' => $entity,
            'maskedConfig' => $this->container->get(ConnectorConfigSecrets::class)->mask($entity->getConfig()),
        ];
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

    /**
     * Runs the Configure popup's "Test" against a (possibly unsaved) configuration:
     * body {type, config, id?}. Untouched stored secrets arrive as the ******** sentinel and are
     * resolved from the persisted connector (same-type only) before testing. Deliberately NOT the
     * shared aaxis_common connection-test endpoint — that one only tests saved configuration,
     * while this feature is explicitly about probing the values just typed in the popup, so it is
     * gated to users who can actually create/edit connectors.
     */
    #[Route(path: '/connectors/test-config', name: 'aaxis_ontology_connector_test', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('aaxis_ontology_connector_view')]
    #[CsrfProtection]
    public function testConfigAction(Request $request): JsonResponse
    {
        if (!$this->isGranted('aaxis_ontology_connector_create') && !$this->isGranted('aaxis_ontology_connector_update')) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied.', 'steps' => []], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request body.', 'steps' => []], 400);
        }
        $type = (string) ($payload['type'] ?? '');
        if (!\in_array($type, OntologyConnector::TYPES, true)) {
            return new JsonResponse(['success' => false, 'message' => 'Unknown connector type.', 'steps' => []], 400);
        }
        $config = \is_array($payload['config'] ?? null) ? $payload['config'] : null;

        // Resolve ******** sentinels from the stored config of the connector being edited. Only a
        // same-type stored config is used — after a type switch the old secrets don't apply.
        $stored = null;
        $id = $payload['id'] ?? null;
        if (\is_int($id) || (\is_string($id) && ctype_digit($id))) {
            $connector = $this->container->get(ManagerRegistry::class)
                ->getRepository(OntologyConnector::class)->find((int) $id);
            if ($connector instanceof OntologyConnector && $connector->getType() === $type) {
                $stored = $connector->getConfig();
            }
        }
        $config = $this->container->get(ConnectorConfigSecrets::class)->merge($config, $stored);

        return new JsonResponse($this->container->get(ConnectorTester::class)->test($type, $config));
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ConnectorConfigSecrets::class,
            ConnectorTester::class,
        ]);
    }
}
