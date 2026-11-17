<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\OAuthTokenFailure;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth access tokens for rest_api connectors configured with `auth: oauth` — fetched once and
 * REUSED, instead of one token request per HTTP Request step (a 500-element foreach used to make
 * 500 token calls, and token endpoints commonly rate-limit).
 *
 * - A token whose response carries `expires_in` goes to the shared cache (aaxis_ontology.cache) until shortly
 *   before it expires (10% of its lifetime, at most 60s, earlier), so every run, process and user
 *   of the connector shares it.
 * - A token WITHOUT `expires_in` has no known lifetime: it is kept in memory for the run that
 *   fetched it only (keyed by the run's flowUuid), never across runs.
 * - The cache key carries only the connector id; a fingerprint of the server + OAuth settings is
 *   stored next to the token, so editing the connector retires its token without ever putting
 *   anything derived from the secrets into a key.
 * - {@see invalidate()} drops a token the API rejected; the executor then fetches a fresh one and
 *   retries the call once.
 */
class OAuthTokenProvider
{
    private const CACHE_PREFIX = 'aaxis_ontology_oauth_token.';
    private const MAX_EXPIRY_MARGIN = 60;
    private const TIMEOUT = 30;

    /** @var array<int, array{runId: string, fingerprint: string, authorization: string}> */
    private array $runTokens = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * The Authorization header value for a connector, from cache when possible.
     *
     * @param int|null             $connectorId null = an unsaved connector: never cached
     * @param array<string, mixed> $oauth       the connector config's `oauth` object {path, headers, body}
     * @param string|null          $runId       the current run's flowUuid (scopes tokens without expires_in)
     *
     * @return array{authorization: string, cached: bool} `cached` = reused rather than just fetched
     *
     * @throws OAuthTokenFailure with a step-agnostic message when no token can be obtained
     */
    public function authorization(?int $connectorId, string $base, array $oauth, ?string $runId): array
    {
        $fingerprint = hash('sha256', (string) json_encode([$base, $oauth]));
        $cached = $connectorId !== null ? $this->cachedAuthorization($connectorId, $fingerprint, $runId) : null;
        if ($cached !== null) {
            return ['authorization' => $cached, 'cached' => true];
        }

        [$authorization, $ttl] = $this->requestToken($base, $oauth);
        if ($connectorId !== null && $ttl === null && $runId !== null) {
            $this->runTokens[$connectorId] = ['runId' => $runId, 'fingerprint' => $fingerprint, 'authorization' => $authorization];
        } elseif ($connectorId !== null && $ttl > 0) {
            $item = $this->cache->getItem(self::CACHE_PREFIX . $connectorId);
            $item->set(['fingerprint' => $fingerprint, 'authorization' => $authorization]);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        }

        return ['authorization' => $authorization, 'cached' => false];
    }

    /** Forgets a connector's token (e.g. the API answered 401 to it). */
    public function invalidate(?int $connectorId): void
    {
        if ($connectorId === null) {
            return;
        }
        unset($this->runTokens[$connectorId]);
        $this->cache->deleteItem(self::CACHE_PREFIX . $connectorId);
    }

    private function cachedAuthorization(int $connectorId, string $fingerprint, ?string $runId): ?string
    {
        $item = $this->cache->getItem(self::CACHE_PREFIX . $connectorId);
        $shared = $item->isHit() ? $item->get() : null;
        if (\is_array($shared) && ($shared['fingerprint'] ?? null) === $fingerprint && \is_string($shared['authorization'] ?? null)) {
            return $shared['authorization'];
        }

        $run = $this->runTokens[$connectorId] ?? null;
        $sameRun = $run !== null && $runId !== null && $run['runId'] === $runId && $run['fingerprint'] === $fingerprint;

        return $sameRun ? $run['authorization'] : null;
    }

    /**
     * POSTs the token path with the configured headers + form-encoded body.
     *
     * @param array<string, mixed> $oauth
     *
     * @return array{0: string, 1: int|null} the Authorization value, and how long it may be cached:
     *                                       null = unknown lifetime, 0 = do not cache
     */
    private function requestToken(string $base, array $oauth): array
    {
        $path = trim((string) ($oauth['path'] ?? ''));
        if ($path === '') {
            throw new OAuthTokenFailure('the connector has no OAuth token path configured.');
        }

        try {
            $response = $this->httpClient->request('POST', $base . '/' . ltrim($path, '/'), [
                'headers' => self::stringMap($oauth['headers'] ?? null),
                'body' => self::stringMap($oauth['body'] ?? null),
                'timeout' => self::TIMEOUT,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            throw new OAuthTokenFailure('OAuth token request failed — ' . $e->getMessage(), $e);
        }

        $token = \is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!\is_string($token) || $token === '') {
            throw new OAuthTokenFailure('the OAuth response carries no access_token.');
        }
        $type = \is_string($data['token_type'] ?? null) && $data['token_type'] !== '' ? $data['token_type'] : 'Bearer';

        return [$type . ' ' . $token, self::ttl($data['expires_in'] ?? null)];
    }

    /** Seconds to cache a token: its lifetime minus a safety margin; null when unknown. */
    private static function ttl(mixed $expiresIn): ?int
    {
        if (!is_numeric($expiresIn)) {
            return null;
        }
        $lifetime = (int) $expiresIn;

        return max(0, $lifetime - min(self::MAX_EXPIRY_MARGIN, intdiv($lifetime, 10)));
    }

    /**
     * Header/body maps as configured: non-empty string keys with scalar values (same rule as the
     * executor applies to the connector's plain headers).
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $map): array
    {
        $result = [];
        foreach (\is_array($map) ? $map : [] as $key => $value) {
            if (\is_string($key) && $key !== '' && \is_scalar($value)) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }
}
