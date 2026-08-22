<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Low-level S3 client for the SYSTEM-CONFIGURATION bucket (Aaxis Ontology → Bucket): get/put/
 * delete/list signed with SigV4 against the configured endpoint URL. This is the transport under
 * {@see BucketEntityDataStore}; it knows keys and bytes, nothing about entities.
 *
 * Config: aaxis_ontology.use_bucket_for_entity_data (the master toggle), bucket_endpoint_url
 * (scheme://host[:port], port defaulting from the scheme — same shape the config page's Test
 * probes), bucket_name, bucket_base_path (prepended to every key), and the two keys — stored
 * ENCRYPTED by OroEncodedPlaceholderPasswordType, decrypted here with the same crypter
 * (oro_security.encoder.default). Error messages never carry credentials.
 */
class OntologyBucketClient
{
    private const TIMEOUT = 20;

    /** @var array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string}|null */
    private ?array $endpoint = null;

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly SymmetricCrypterInterface $crypter,
        private readonly S3RequestSigner $signer,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /** The entity-data toggle AND a usable connection ({@see isEnabledFor}). */
    public function isEnabled(): bool
    {
        return $this->isEnabledFor('use_bucket_for_entity_data');
    }

    /** One of the use_bucket_for_* toggles AND a usable connection. */
    public function isEnabledFor(string $toggleKey): bool
    {
        return (bool) $this->configManager->get('aaxis_ontology.' . $toggleKey) && $this->isConfigured();
    }

    /** A usable connection: endpoint URL, bucket name and both keys configured. */
    public function isConfigured(): bool
    {
        try {
            $this->resolveEndpoint();
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /** The configured base path (key prefix), trimmed of slashes; '' when none. */
    public function basePath(): string
    {
        return trim((string) $this->configManager->get('aaxis_ontology.bucket_base_path'), " /\t");
    }

    /** GET an object: its body, or null when the key does not exist (404). */
    public function get(string $key): ?string
    {
        [$status, $body] = $this->request('GET', $key, []);
        if ($status === 404) {
            return null;
        }
        if ($status >= 400) {
            throw new \RuntimeException($this->httpMessage('GET', $key, $status));
        }

        return $body;
    }

    public function put(string $key, string $body): void
    {
        [$status] = $this->request('PUT', $key, [], $body, ['content-type' => 'application/json']);
        if ($status >= 400) {
            throw new \RuntimeException($this->httpMessage('PUT', $key, $status));
        }
    }

    /** DELETE an object (S3 answers 204 for missing keys too — deletes are idempotent). */
    public function delete(string $key): void
    {
        [$status] = $this->request('DELETE', $key, []);
        if ($status >= 400 && $status !== 404) {
            throw new \RuntimeException($this->httpMessage('DELETE', $key, $status));
        }
    }

    /**
     * Every object key under the prefix (list-objects-v2, following continuation tokens so the
     * result is COMPLETE, not first-page-only). Keys come back XML-escaped; they are decoded.
     *
     * @return list<string>
     */
    public function listKeys(string $prefix): array
    {
        $keys = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            [$status, $body] = $this->request('GET', '', $query);
            if ($status >= 400) {
                throw new \RuntimeException($this->httpMessage('LIST', $prefix, $status));
            }

            $xml = $this->parseXml($body);
            foreach ($xml->Contents ?? [] as $item) {
                $keys[] = (string) $item->Key;
            }
            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true'
                ? ((string) ($xml->NextContinuationToken ?? '')) : null;
            $token = $token === '' ? null : $token;
        } while ($token !== null);

        return $keys;
    }

    /**
     * The immediate child "folders" under the prefix (list-objects-v2 with delimiter "/", following
     * continuation tokens): each returned string is a full prefix ending in "/".
     *
     * @return list<string>
     */
    public function listPrefixes(string $prefix): array
    {
        $prefixes = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix, 'delimiter' => '/'];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            [$status, $body] = $this->request('GET', '', $query);
            if ($status >= 400) {
                throw new \RuntimeException($this->httpMessage('LIST', $prefix, $status));
            }

            $xml = $this->parseXml($body);
            foreach ($xml->CommonPrefixes ?? [] as $item) {
                $prefixes[] = (string) $item->Prefix;
            }
            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true'
                ? ((string) ($xml->NextContinuationToken ?? '')) : null;
            $token = $token === '' ? null : $token;
        } while ($token !== null);

        return $prefixes;
    }

    /** Signed request against the configured bucket (path-style addressing, like the tester). */
    private function request(string $method, string $key, array $query, ?string $body = null, array $extraHeaders = []): array
    {
        $endpoint = $this->resolveEndpoint();
        $path = '/' . $endpoint['bucket'] . ($key === '' ? '' : '/' . $key);
        $headers = $this->signer->headers(
            $method,
            $endpoint['host'],
            $path,
            $query,
            $endpoint['accessKey'],
            $endpoint['secretKey'],
            $endpoint['region'],
            $body === null ? null : hash('sha256', $body),
            null,
            $extraHeaders
        );

        $encodedQuery = $this->signer->encodeQuery($query);
        $url = $endpoint['scheme'] . '://' . $endpoint['host'] . $this->signer->encodePath($path)
            . ($encodedQuery === '' ? '' : '?' . $encodedQuery);

        $options = ['headers' => $headers, 'timeout' => self::TIMEOUT];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (\Throwable $e) {
            throw new \RuntimeException('The ontology bucket request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function parseXml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \RuntimeException('The ontology bucket returned an unreadable listing.');
        }

        return $xml;
    }

    private function httpMessage(string $operation, string $key, int $status): string
    {
        return sprintf('The ontology bucket refused %s of "%s" (HTTP %d).', $operation, $key, $status);
    }

    /**
     * @return array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string}
     */
    private function resolveEndpoint(): array
    {
        if ($this->endpoint !== null) {
            return $this->endpoint;
        }

        $url = trim((string) $this->configManager->get('aaxis_ontology.bucket_endpoint_url'));
        $bucket = trim((string) $this->configManager->get('aaxis_ontology.bucket_name'));
        $accessKey = (string) $this->configManager->get('aaxis_ontology.bucket_access_key');
        $secretKey = (string) $this->configManager->get('aaxis_ontology.bucket_secret_key');
        if ($url === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new \RuntimeException('The ontology bucket is not fully configured (endpoint URL, bucket name and keys are required).');
        }

        $scheme = 'https';
        $urlPort = null;
        if (preg_match('~^(https?)://~i', $url, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            $parts = parse_url($url);
            $host = (string) ($parts['host'] ?? '');
            $urlPort = isset($parts['port']) ? (int) $parts['port'] : null;
        } else {
            $host = explode('/', $url)[0];
            if (str_contains($host, ':')) {
                [$host, $rawPort] = explode(':', $host, 2);
                $urlPort = (int) $rawPort;
            }
        }
        if ($host === '') {
            throw new \RuntimeException('The ontology bucket endpoint URL is not a valid URL.');
        }
        $port = $urlPort ?? ($scheme === 'http' ? 80 : 443);

        return $this->endpoint = [
            'scheme' => $scheme,
            'host' => $this->signer->hostHeader($scheme, $host, $port),
            'bucket' => $bucket,
            'region' => $this->signer->regionFromHost($host),
            'accessKey' => $this->crypter->decryptData($accessKey),
            'secretKey' => $this->crypter->decryptData($secretKey),
        ];
    }
}
