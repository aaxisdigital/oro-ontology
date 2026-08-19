<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Api;

use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Manager\EndpointFlowRunner;
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
        if ($match === null) {
            return new JsonResponse(
                ['error' => 'No enabled flow endpoint matches this method and path.', 'code' => 'endpoint_not_found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Non-public triggers demand an authenticated caller HOLDING the flow-API capability —
        // the guard twin of OntologyDataApiController: authentication happened at the firewall
        // (bearer token), authorization is decided here per trigger.
        if (($match['config']['public'] ?? false) !== true) {
            if ($this->getUser() === null) {
                return new JsonResponse(
                    ['error' => 'Authentication required.', 'code' => 'unauthenticated'],
                    Response::HTTP_UNAUTHORIZED
                );
            }
            if (!$this->isGranted(self::ACL_FLOW) && !$this->isGranted(self::ACL_ALL)) {
                return new JsonResponse(
                    ['error' => 'Access denied.', 'code' => 'forbidden'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }
        // ANY authenticated call — public endpoints included — exposes the OAuthApplication
        // variable: the OAuth application's name, carried by the OAuth2 token as its "client"
        // attribute (null when the caller authenticated another way). Anonymous calls get none.
        $extraInput = [];
        if ($this->getUser() !== null) {
            $extraInput['OAuthApplication'] = $this->oauthApplication();
        }

        // Body: JSON when it parses, raw text otherwise, null when empty.
        $raw = $request->getContent();
        $body = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (!\in_array(strtolower((string) $name), self::HIDDEN_HEADERS, true)) {
                $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
            }
        }

        try {
            $context = $runner->run($match, $body, $headers, $request->query->all(), $extraInput);
            // The trigger's optional Response binding shapes the HTTP answer from the final
            // context: {statusCode, body}. Without one, the default {success, flowUuid, context}.
            $bound = $runner->respond($match['config'], $context);
        } catch (FlowStepFailure $e) {
            return new JsonResponse(
                ['error' => $e->getMessage(), 'code' => 'flow_failed', 'failedStepId' => $e->stepId],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage(), 'code' => 'flow_failed'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($bound !== null) {
            return new JsonResponse($bound['body'], $bound['statusCode']);
        }

        return new JsonResponse([
            'success' => true,
            'flowUuid' => $context['flowUuid'] ?? null,
            'context' => $context === [] ? new \stdClass() : $context,
        ]);
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
        ]);
    }
}
