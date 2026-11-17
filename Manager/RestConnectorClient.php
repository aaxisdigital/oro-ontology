<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorRequestFailure;
use Aaxis\Bundle\OntologyBundle\Exception\OAuthTokenFailure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends ONE request through a rest_api connector — the transport half of the "HTTP Request" flow
 * step, shared by its two modes so they cannot drift: the flow executor calls it inline for a
 * synchronous step (response stored under the destination), the HTTP-request consumer for an
 * ASYNC one (response discarded).
 *
 * URL = connector server[:port] + the step's path (https:// assumed when the server has no
 * scheme); headers = the connector's headers, plus auth_headers for `auth: headers`, plus a bearer
 * from the token path for `auth: oauth`. OAuth tokens are REUSED via {@see OAuthTokenProvider};
 * a 401 to a reused token drops it and retries the call ONCE with a fresh one (a fresh token's 401
 * is final). TLS verification is off, like the toolbox proxy (internal endpoints commonly run
 * self-signed certs). Secrets are stored in clear in the connector config (masking is a
 * render-time concern), so they are used directly.
 */
class RestConnectorClient
{
    private const int TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthTokenProvider $oauthTokens,
    ) {
    }

    /**
     * The connector's base URL (scheme + host + port, no trailing slash).
     *
     * @throws ConnectorRequestFailure when the connector has no server configured
     */
    public function baseUrl(OntologyConnector $connector): string
    {
        $config = $connector->getConfig() ?? [];
        $base = trim((string) ($config['server'] ?? ''));
        if ($base === '') {
            throw new ConnectorRequestFailure('the connector has no server configured.');
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $base = rtrim($base, '/');
        $port = (int) ($config['port'] ?? 0);
        if ($port > 0 && parse_url($base, PHP_URL_PORT) === null) {
            $base .= ':' . $port;
        }

        return $base;
    }

    /**
     * @param string      $method   HTTP verb, any case (blank = GET)
     * @param string|null $body     the request body, already rendered; null = no body
     * @param string      $bodyType json|xml|text — picks the Content-Type of a present body
     * @param string|null $runId    the run's flowUuid: scopes OAuth tokens of unknown lifetime
     *
     * @return mixed the JSON-decoded response, or the raw text when it is not JSON
     *
     * @throws ConnectorRequestFailure with a step-agnostic message
     */
    public function send(OntologyConnector $connector, string $method, string $path, ?string $body, string $bodyType, ?string $runId): mixed
    {
        $config = $connector->getConfig() ?? [];
        $base = $this->baseUrl($connector);
        $url = $base . '/' . ltrim($path, '/');

        $headers = self::stringMap($config['headers'] ?? null);
        $auth = (string) ($config['auth'] ?? 'none');
        if ($auth === 'headers') {
            $headers = array_merge($headers, self::stringMap($config['auth_headers'] ?? null));
        }
        $oauth = null;
        if ($auth === 'oauth') {
            $oauth = $this->oauthAuthorization($connector, $base, $runId);
            $headers['Authorization'] = $oauth['authorization'];
        }

        $options = [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
            'max_redirects' => 5,
            'verify_peer' => false,
            'verify_host' => false,
        ];
        if ($body !== null) {
            $options['body'] = $body;
            $options['headers']['Content-Type'] = match ($bodyType) {
                'json' => 'application/json',
                'xml' => 'application/xml',
                default => 'text/plain',
            };
        }
        $method = strtoupper($method) ?: 'GET';

        [$status, $content] = $this->request($method, $url, $options);
        if ($status === 401 && $oauth !== null && $oauth['cached']) {
            $this->oauthTokens->invalidate($connector->getId());
            $options['headers']['Authorization'] = $this->oauthAuthorization($connector, $base, $runId)['authorization'];
            [$status, $content] = $this->request($method, $url, $options);
        }
        if ($status >= 400) {
            throw new ConnectorRequestFailure(sprintf('the connector responded HTTP %d.', $status));
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: string} status code + body
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (\Throwable $e) {
            throw new ConnectorRequestFailure($e->getMessage(), $e);
        }
    }

    /**
     * @return array{authorization: string, cached: bool}
     */
    private function oauthAuthorization(OntologyConnector $connector, string $base, ?string $runId): array
    {
        $config = $connector->getConfig() ?? [];
        $oauth = \is_array($config['oauth'] ?? null) ? $config['oauth'] : [];

        try {
            return $this->oauthTokens->authorization($connector->getId(), $base, $oauth, $runId);
        } catch (OAuthTokenFailure $e) {
            throw new ConnectorRequestFailure($e->getMessage(), $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && $key !== '' && is_scalar($item)) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }
}
