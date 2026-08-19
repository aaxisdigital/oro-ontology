<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Api;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth-authenticated HTTP API over the Ontology data records, addressing them by system + entity
 * name. The routes are declared under "/api/aaxis/ontology/data/..." (the sibling
 * "/api/aaxis/ontology/flow/..." prefix belongs to the Endpoint-trigger API, see
 * OntologyFlowApiController); Oro's RouteCollectionListener prepends the back-office prefix, so
 * they resolve under "/admin/api/aaxis/ontology/data/..." and are therefore handled by the
 * stateless OAuth ("api_secured") firewall.
 *
 * All business logic lives in {@see OntologyDataApiManager}; this controller only enforces the
 * per-endpoint config toggle and ACL, parses the request, and renders JSON.
 *
 * ACLs: read & query need aaxis_ontology_api_access_read OR aaxis_ontology_api_access_all;
 * upsert needs aaxis_ontology_api_access_all.
 */
class OntologyDataApiController extends AbstractController
{
    private const string ACL_ALL = 'aaxis_ontology_api_access_all';
    private const string ACL_READ = 'aaxis_ontology_api_access_read';

    #[Route(
        path: '/api/aaxis/ontology/data/{systemName}/{entityName}/uid/{uniqueId}',
        name: 'aaxis_ontology_api_data_read',
        methods: ['GET']
    )]
    public function readAction(string $systemName, string $entityName, string $uniqueId): JsonResponse
    {
        if (($disabled = $this->guard('aaxis_ontology.api_read_enabled', false)) !== null) {
            return $disabled;
        }

        try {
            $this->manager()->requireEnabledFlow(OntologyFlow::NAME_REST_API);
            $payload = $this->manager()->read($systemName, $entityName, $uniqueId);
        } catch (OntologyApiException $e) {
            return $this->error($e);
        }

        // Return the payload itself; render an empty JSON object when it is null/empty.
        return new JsonResponse($payload === null || $payload === [] ? new \stdClass() : $payload);
    }

    #[Route(
        path: '/api/aaxis/ontology/data/{systemName}/{entityName}/upsert',
        name: 'aaxis_ontology_api_data_upsert',
        methods: ['POST']
    )]
    public function upsertAction(Request $request, string $systemName, string $entityName): JsonResponse
    {
        if (($disabled = $this->guard('aaxis_ontology.api_upsert_enabled', true)) !== null) {
            return $disabled;
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(
                ['error' => 'The request body must be a JSON object or array of objects.', 'code' => 'invalid_payload'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // A JSON object is a single record; a JSON array is a batch of records. The unique id of each
        // record is inferred from the payload via the entity's unique attribute.
        $records = array_is_list($body) ? $body : [$body];

        try {
            $flow = $this->manager()->requireEnabledFlow(OntologyFlow::NAME_REST_API);
            $uuid = $this->manager()->upsert($systemName, $entityName, $records, $flow);
        } catch (OntologyApiException $e) {
            return $this->error($e);
        }

        return new JsonResponse(
            ['success' => true, 'uuid' => $uuid, 'count' => \count($records)],
            Response::HTTP_ACCEPTED
        );
    }

    #[Route(
        path: '/api/aaxis/ontology/data/{systemName}/{entityName}/query',
        name: 'aaxis_ontology_api_data_query',
        methods: ['POST']
    )]
    public function queryAction(Request $request, string $systemName, string $entityName): JsonResponse
    {
        if (($disabled = $this->guard('aaxis_ontology.api_query_enabled', false)) !== null) {
            return $disabled;
        }

        $body = $request->getContent() === '' ? [] : json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(
                ['error' => 'The request body must be a JSON object.', 'code' => 'invalid_query'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $filters = \is_array($body['filter'] ?? null) ? $body['filter'] : [];
        $orderBy = isset($body['orderBy']) ? (string) $body['orderBy'] : null;
        $page = max(1, (int) $request->query->get('page', '1'));
        $pageSize = max(1, (int) $request->query->get('page_size', '50'));

        try {
            $this->manager()->requireEnabledFlow(OntologyFlow::NAME_REST_API);
            $items = $this->manager()->query($systemName, $entityName, $filters, $orderBy, $page, $pageSize);
        } catch (OntologyApiException $e) {
            return $this->error($e);
        }

        return new JsonResponse(['items' => $items, 'page' => $page, 'page_size' => $pageSize]);
    }

    /**
     * Enforces the per-endpoint config toggle and ACL. Returns a JsonResponse to short-circuit with,
     * or null when the request may proceed.
     */
    private function guard(string $configKey, bool $writeAccess): ?JsonResponse
    {
        if (!$this->config()->get($configKey)) {
            return new JsonResponse(['error' => 'Not found.', 'code' => 'endpoint_disabled'], Response::HTTP_NOT_FOUND);
        }

        $granted = $writeAccess
            ? $this->isGranted(self::ACL_ALL)
            : ($this->isGranted(self::ACL_READ) || $this->isGranted(self::ACL_ALL));

        if (!$granted) {
            return new JsonResponse(['error' => 'Access denied.', 'code' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function error(OntologyApiException $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->getMessage(), 'code' => $e->getErrorCode()], $e->getStatusCode());
    }

    private function manager(): OntologyDataApiManager
    {
        return $this->container->get(OntologyDataApiManager::class);
    }

    private function config(): ConfigManager
    {
        return $this->container->get(ConfigManager::class);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            OntologyDataApiManager::class,
            ConfigManager::class,
        ]);
    }
}
