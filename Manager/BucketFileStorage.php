<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bucket storage (S3-compatible object storage, path-style addressing, SigV4-signed requests).
 * Object storage has no folders: a path is a folder when it is EMPTY or ends with `/` (a prefix
 * listing with delimiter `/`), an object key otherwise.
 */
class BucketFileStorage extends FileStorage
{
    public function __construct(
        private readonly S3RequestSigner $signer,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function read(array $config, string $path): mixed
    {
        $endpoint = $this->bucketEndpoint($config);

        // No folders in object storage: an empty path or a trailing slash means "list this prefix".
        if ($path === '' || str_ends_with($path, '/')) {
            return $this->listBucket($endpoint, $path);
        }

        [$status, $body] = $this->bucketRequest($endpoint, 'GET', $path, [], null);
        if ($status === 404) {
            // A missing BUCKET and a missing OBJECT are both 404 — only the error code tells them
            // apart, and blaming the object for a wrong bucket name sends the reader hunting in the
            // wrong place. (OCI says BucketNotFound, AWS NoSuchBucket.)
            $code = (string) ($this->parseXml($body)?->Code ?? '');

            throw new ConnectorTransferFailure(stripos($code, 'bucket') !== false
                ? sprintf('Bucket "%s" does not exist on this endpoint.', $endpoint['bucket'])
                : sprintf('Object "%s" does not exist in bucket "%s".', $path, $endpoint['bucket']));
        }
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, $body));
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function write(array $config, string $path, string $content): array
    {
        $endpoint = $this->bucketEndpoint($config);
        [$status, $body] = $this->bucketRequest($endpoint, 'PUT', $path, [], $content);
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, $body));
        }

        return $this->receipt($path, \strlen($content));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function delete(array $config, string $path): void
    {
        $endpoint = $this->bucketEndpoint($config);
        // Object storage "deletes" a key that never existed without complaint — check first so a
        // typo reports like it does on the other storages.
        $this->assertBucketObjectExists($endpoint, $path);

        [$status, $body] = $this->bucketRequest($endpoint, 'DELETE', $path, [], null);
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, $body));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function rename(array $config, string $path, string $newPath): void
    {
        $endpoint = $this->bucketEndpoint($config);
        $this->assertBucketObjectExists($endpoint, $path);
        [$status] = $this->bucketRequest($endpoint, 'HEAD', $newPath, [], null);
        if ($status < 400) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_ALREADY_EXISTS, $newPath));
        }

        // No rename in object storage: server-side copy, then delete the source.
        $copySource = '/' . $endpoint['bucket'] . '/' . $path;
        [$status, $body] = $this->bucketRequest($endpoint, 'PUT', $newPath, [], null, [
            'x-amz-copy-source' => $this->signer->encodePath($copySource),
        ]);
        // A copy can FAIL with a 200 carrying an <Error> body (S3 quirk) — status alone is not enough.
        if ($status >= 400 || (string) ($this->parseXml($body)?->getName() ?? '') === 'Error') {
            throw new ConnectorTransferFailure($this->bucketHttpMessage(max($status, 400), $path, $body));
        }

        [$status, $body] = $this->bucketRequest($endpoint, 'DELETE', $path, [], null);
        if ($status >= 400) {
            throw new ConnectorTransferFailure(sprintf(
                'Copied to "%s" but the source "%s" could not be deleted: %s',
                $newPath,
                $path,
                $this->bucketHttpMessage($status, $path, $body)
            ));
        }
    }

    /**
     * @param array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string} $endpoint
     */
    private function assertBucketObjectExists(array $endpoint, string $path): void
    {
        [$status] = $this->bucketRequest($endpoint, 'HEAD', $path, [], null);
        if ($status === 404) {
            throw new ConnectorTransferFailure(sprintf('Object "%s" does not exist in bucket "%s".', $path, $endpoint['bucket']));
        }
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, ''));
        }
    }

    /**
     * @param array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string} $endpoint
     *
     * @return list<array<string, mixed>>
     */
    private function listBucket(array $endpoint, string $prefix): array
    {
        $query = ['list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000'];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }
        [$status, $body] = $this->bucketRequest($endpoint, 'GET', '', $query, null);
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $prefix, $body));
        }

        $root = $this->parseXml($body);
        if ($root === null) {
            throw new ConnectorTransferFailure(sprintf('Bucket "%s" returned an unreadable listing.', $endpoint['bucket']));
        }

        $items = [];
        foreach ($root->CommonPrefixes as $common) {
            $name = $this->keyLeaf((string) $common->Prefix, $prefix);
            if ($name !== '') {
                $items[] = $this->item($name, $prefix, self::ITEM_FOLDER, null, null);
            }
        }
        foreach ($root->Contents as $object) {
            $key = (string) $object->Key;
            // The prefix itself can come back as a zero-byte "folder marker" — not an item.
            $name = $this->keyLeaf($key, $prefix);
            if ($name === '') {
                continue;
            }
            $modified = strtotime((string) $object->LastModified);
            $items[] = $this->item(
                $name,
                $prefix,
                self::ITEM_FILE,
                (int) $object->Size,
                $modified === false ? null : $modified
            );
        }

        return $this->sortItems($items);
    }

    /**
     * Signs and performs one request against the bucket. Path-style addressing keeps the signed
     * host independent of the bucket name; the URL is built with the signer's own encoders so it
     * matches the signature byte for byte.
     *
     * @param array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string} $endpoint
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders extra SIGNED headers (e.g. x-amz-copy-source)
     *
     * @return array{0: int, 1: string}
     */
    private function bucketRequest(array $endpoint, string $method, string $key, array $query, ?string $body, array $extraHeaders = []): array
    {
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
            throw new ConnectorTransferFailure('The bucket request failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string}
     */
    private function bucketEndpoint(array $config): array
    {
        $server = trim((string) ($config['server'] ?? ''));
        $bucket = trim((string) ($config['bucket_name'] ?? ''));
        if ($server === '') {
            throw new ConnectorConfigurationException('the connector has no server configured');
        }
        if ($bucket === '') {
            throw new ConnectorConfigurationException('the connector has no bucket name configured');
        }

        $scheme = 'https';
        $urlPort = null;
        if (preg_match('~^(https?)://~i', $server, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            $parts = parse_url($server);
            $host = (string) ($parts['host'] ?? '');
            $urlPort = isset($parts['port']) ? (int) $parts['port'] : null;
        } else {
            $host = explode('/', $server)[0];
            if (str_contains($host, ':')) {
                [$host, $rawPort] = explode(':', $host, 2);
                $urlPort = (int) $rawPort;
            }
        }
        $schemePort = $scheme === 'http' ? 80 : 443;
        $configPort = (int) ($config['port'] ?? 0);
        $port = $configPort > 0 ? $configPort : ($urlPort ?? $schemePort);

        return [
            'scheme' => $scheme,
            'host' => $this->signer->hostHeader($scheme, $host, $port),
            'bucket' => $bucket,
            'region' => $this->signer->regionFromHost($host),
            'accessKey' => trim((string) ($config['access_key'] ?? '')),
            'secretKey' => (string) ($config['secret_key'] ?? ''),
        ];
    }

    /**
     * Parses an S3 XML body for plain property access.
     *
     * S3 bodies declare a DEFAULT namespace, and SimpleXML will not return namespaced children
     * through `$xml->Contents`; `children($uri)` fixes the first level but not the nested
     * `->Key`/`->Size` reads. Dropping the declaration up front makes every level accessible with
     * no namespace juggling — the documents carry exactly one namespace, so nothing is lost.
     */
    private function parseXml(string $body): ?\SimpleXMLElement
    {
        $stripped = preg_replace('~\sxmlns="[^"]*"~', '', $body, 1) ?? $body;
        $xml = @simplexml_load_string($stripped);

        return $xml === false ? null : $xml;
    }

    /** Turns an S3 error body into one line, without dumping raw XML into the flow output. */
    private function bucketHttpMessage(int $status, string $path, string $body): string
    {
        $detail = '';
        $xml = $this->parseXml($body);
        if ($xml !== null && (string) $xml->Message !== '') {
            $detail = ' — ' . (string) $xml->Message;
        }

        return sprintf(
            'The bucket rejected "%s" with HTTP %d%s',
            $path,
            $status,
            $detail === '' ? '.' : $detail
        );
    }

    /** The last segment of an object key relative to the prefix being listed. */
    private function keyLeaf(string $key, string $prefix): string
    {
        $relative = $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, \strlen($prefix)) : $key;

        return trim($relative, '/');
    }
}
