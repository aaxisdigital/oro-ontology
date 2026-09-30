<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Api;

use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Manager\EndpointFlowRunner;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Endpoint-trigger HTTP entry point: one catch-all route under "/api/aaxis/ontology/flow/"
 * (resolving under the back-office prefix like the data API, so /admin/api/aaxis/ontology/flow/…)
 * that {@see EndpointFlowRunner} resolves to the enabled flow whose trigger matches the request's
 * method + path.
 *
 * AUTH: the bundle's Resources/config/oro/app.yml grants the whole prefix PUBLIC_ACCESS at the
 * firewall (the same mechanism IntegrationBundle uses for webhooks) — a valid OAuth bearer token
 * still authenticates when present. Authorization is decided HERE per trigger: a non-public
 * endpoint (the default) returns 401 without an authenticated user and 403 when the user holds
 * neither aaxis_ontology_api_access_flow nor aaxis_ontology_api_access_all; a public one runs
 * for anyone.
 *
 * The flow sees the request as context variables: every {param} captured from the path under its
 * own name, the body under `body` (JSON-decoded when parseable, raw text otherwise, null when
 * empty), the query string under `queryParams` (an object, {} when none) and the headers under
 * `headers` (lowercased names; authorization/cookie excluded so credentials never leak into flow
 * contexts or event rows).
 */
class OntologyFlowApiController extends AbstractController
{
    /** Same capability model as the data API's guard: "all" covers everything, or the flow one. */
    private const string ACL_ALL = 'aaxis_ontology_api_access_all';
    private const string ACL_FLOW = 'aaxis_ontology_api_access_flow';

    /** Request headers that must never reach a flow context (credentials). */
    private const array HIDDEN_HEADERS = ['authorization', 'cookie', 'php-auth-user', 'php-auth-pw'];

    #[Route(
        path: '/api/aaxis/ontology/flow/{path}',
        name: 'aaxis_ontology_api_flow_endpoint',
        requirements: ['path' => '.+'],
        methods: EndpointFlowRunner::METHODS
    )]
    public function endpointAction(Request $request, string $path): JsonResponse
    {
        $runner = $this->container->get(EndpointFlowRunner::class);
        $match = $runner->match($request->getMethod(), $path);
        $rejection = $match === null
            ? new JsonResponse(
                ['error' => 'No enabled flow endpoint matches this method and path.', 'code' => 'endpoint_not_found'],
                Response::HTTP_NOT_FOUND
            )
            : $this->denial($match['config']);
        if ($rejection !== null) {
            return $rejection;
        }

        [$extraInput, $runInfo] = $this->callerContext();

        try {
            $context = $runner->run($match, $this->requestBody($request), $this->requestHeaders($request), $request->query->all(), $extraInput, $runInfo);
            // The trigger's optional Response binding shapes the HTTP answer from the final
            // context: {statusCode, body}. Without one, the default {success, flowUuid, context}.
            $bound = $runner->respond($match['config'], $context);
        } catch (\RuntimeException $e) {
            return $this->flowFailed($e);
        }

        $shownContext = $context === [] ? new \stdClass() : $context;

        return $bound !== null
            ? new JsonResponse($bound['body'], $bound['statusCode'])
            : new JsonResponse(['success' => true, 'flowUuid' => $context['flowUuid'] ?? null, 'context' => $shownContext]);
    }

    /**
     * Why the matched endpoint refuses this caller, or null when it may run: the System
     * Configuration kill switch of its kind (checked BEFORE auth so a switched-off endpoint does
     * not even reveal it exists), then — for a non-public trigger — authentication and the
     * flow-API capability. Authentication happened at the firewall (bearer token); authorization
     * is decided here per trigger, the guard twin of OntologyDataApiController.
     *
     * @param array<string, mixed> $triggerConfig
     */
    private function denial(array $triggerConfig): ?JsonResponse
    {
        $isPublic = ($triggerConfig['public'] ?? false) === true;
        $switch = $isPublic ? 'aaxis_ontology.flow_api_public_enabled' : 'aaxis_ontology.flow_api_secure_enabled';

        return match (true) {
            !$this->container->get(ConfigManager::class)->get($switch)
                => new JsonResponse(['error' => 'Not found.', 'code' => 'endpoint_disabled'], Response::HTTP_NOT_FOUND),
            $isPublic => null,
            $this->getUser() === null
                => new JsonResponse(['error' => 'Authentication required.', 'code' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED),
            !$this->isGranted(self::ACL_FLOW) && !$this->isGranted(self::ACL_ALL)
                => new JsonResponse(['error' => 'Access denied.', 'code' => 'forbidden'], Response::HTTP_FORBIDDEN),
            default => null,
        };
    }

    /**
     * ANY authenticated call — public endpoints included — exposes the OAuthApplication variable:
     * the OAuth application's name, carried by the OAuth2 token as its "client" attribute (null
     * when the caller authenticated another way). Anonymous calls get none. The flow-start event
     * names who called (endpoint runs with credentials).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the extra trigger input and the run info
     */
    private function callerContext(): array
    {
        $extraInput = [];
        $runInfo = ['trigger' => 'endpoint'];
        $user = $this->getUser();
        if ($user !== null) {
            $extraInput['OAuthApplication'] = $this->oauthApplication();
            $first = method_exists($user, 'getFirstName') ? (string) $user->getFirstName() : '';
            $last = method_exists($user, 'getLastName') ? (string) $user->getLastName() : '';
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = (string) $user->getUserIdentifier();
            }
            $runInfo['user'] = [
                'name' => $name,
                'email' => method_exists($user, 'getEmail') ? (string) $user->getEmail() : '',
            ];
        }

        return [$extraInput, $runInfo];
    }

    /** JSON when it parses, raw text otherwise, null when empty. */
    private function requestBody(Request $request): mixed
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * @return array<string, string> lowercased names, first values, credentials removed
     */
    private function requestHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (!\in_array(strtolower((string) $name), self::HIDDEN_HEADERS, true)) {
                $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
            }
        }

        return $headers;
    }

    private function flowFailed(\RuntimeException $e): JsonResponse
    {
        $body = ['error' => $e->getMessage(), 'code' => 'flow_failed'];
        if ($e instanceof FlowStepFailure) {
            $body['failedStepId'] = $e->stepId;
        }

        return new JsonResponse($body, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** The name of the OAuth application behind the current token, when there is one. */
    private function oauthApplication(): ?string
    {
        $token = $this->container->get('security.token_storage')->getToken();
        if ($token === null || !$token->hasAttribute('client')) {
            return null;
        }
        $client = $token->getAttribute('client');

        return \is_object($client) && method_exists($client, 'getName') ? (string) $client->getName() : null;
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            EndpointFlowRunner::class,
            ConfigManager::class,
        ]);
    }
}
