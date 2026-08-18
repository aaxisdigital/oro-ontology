<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * Signs requests to S3-compatible object storage with AWS Signature Version 4 — the scheme
 * OCI Object Storage, AWS S3 and MinIO all authenticate an access key / secret key pair with.
 * Used by the `bucket` connector's test ({@see ConnectorTester::testBucket()}).
 *
 * Three details that make signatures match in practice:
 * - The signed `host` value must be EXACTLY what the HTTP client puts in the Host header, so the
 *   caller passes the host header value it will produce (with `:port` only when the port is not
 *   the scheme default) rather than a bare hostname — {@see hostHeader()}.
 * - The region lives in the credential scope, and S3-compatible endpoints encode it in the
 *   hostname rather than taking it as configuration — {@see regionFromHost()}.
 * - The URL the client requests must be encoded EXACTLY as the signature encodes it, which is not
 *   what `http_build_query()` produces (it renders a space as `+`, SigV4 wants `%20`). Callers
 *   build their URL with {@see encodePath()} / {@see encodeQuery()} for that reason.
 *
 * Requests WITH a body are supported by passing `$payloadHash` — the SHA-256 of the body, which
 * S3 requires to be both signed and sent as `x-amz-content-sha256`. Omitting it signs an empty
 * body, which is what every GET/HEAD/list call needs.
 */
class S3RequestSigner
{
    public const string DEFAULT_REGION = 'us-east-1';

    private const string ALGORITHM = 'AWS4-HMAC-SHA256';
    private const string SERVICE = 's3';

    /**
     * Builds the authentication headers for a request.
     *
     * @param string                $method       HTTP verb (GET, PUT, HEAD, ...)
     * @param string                $host         host header value, see {@see hostHeader()}
     * @param string                $path         path starting with '/' (raw, not URL-encoded)
     * @param array<string, string> $query        query parameters, unencoded
     * @param string|null           $payloadHash  SHA-256 of the body; null signs an empty body
     * @param array<string, string> $extraHeaders extra headers to SIGN and send (e.g.
     *                                            x-amz-copy-source); values sent verbatim
     *
     * @return array<string, string> headers to add to the request (Host excluded — the HTTP
     *                               client derives it from the URL)
     */
    public function headers(
        string $method,
        string $host,
        string $path,
        array $query,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $payloadHash = null,
        ?\DateTimeImmutable $now = null,
        array $extraHeaders = []
    ): array {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $payloadHash ??= hash('sha256', '');

        // Canonical headers: host + the two always-sent x-amz headers + any extras, sorted by
        // lowercase name as SigV4 requires (the fixed trio happens to be pre-sorted; extras like
        // x-amz-copy-source land in between).
        $toSign = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        foreach ($extraHeaders as $name => $value) {
            $toSign[strtolower(trim((string) $name))] = trim((string) $value);
        }
        ksort($toSign, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($toSign as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($toSign));

        // The blank line between the headers and the signed-header list comes from
        // $canonicalHeaders already ending in "\n" plus the join — both are required.
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->encodePath($path),
            $this->encodeQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($secretKey, $dateStamp, $region));

        $headers = [
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
            'Authorization' => sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                self::ALGORITHM,
                $accessKey,
                $scope,
                $signedHeaders,
                $signature
            ),
        ];
        // The signed extras must be SENT exactly as signed.
        foreach ($extraHeaders as $name => $value) {
            $headers[strtolower(trim((string) $name))] = trim((string) $value);
        }

        return $headers;
    }

    /**
     * The Host header value for an endpoint: bare host on the scheme's default port, host:port
     * otherwise. HTTP clients omit a default port from the Host header, and a signature computed
     * over a value the client does not send is rejected — so signing and the URL must both use this.
     */
    public function hostHeader(string $scheme, string $host, int $port): string
    {
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $isDefaultPort ? $host : $host . ':' . $port;
    }

    /**
     * Derives the SigV4 region from an S3-compatible endpoint hostname. Bucket connectors do not
     * configure a region — every supported provider encodes it in the host:
     *
     * - OCI: `<namespace>.compat.objectstorage.<region>.oraclecloud.com`
     * - AWS: `s3.<region>.amazonaws.com`, `s3-<region>.amazonaws.com`, `<bucket>.s3.<region>...`
     *
     * Anything else (MinIO, the legacy global `s3.amazonaws.com`) falls back to
     * {@see DEFAULT_REGION} — what those endpoints expect in the credential scope.
     */
    public function regionFromHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (preg_match('~\.objectstorage\.([a-z0-9-]+)\.oraclecloud\.com$~', $host, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('~(?:^|\.)s3[.-]([a-z0-9-]+)\.amazonaws\.com$~', $host, $matches) === 1) {
            return $matches[1];
        }

        return self::DEFAULT_REGION;
    }

    /**
     * URI-encodes each path segment, keeping the separators (AWS leaves '/' unencoded). Public
     * because the caller must build its request URL with the SAME encoding it signed.
     */
    public function encodePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        $segments = array_map(
            // rawurlencode is RFC 3986: it leaves -_.~ alone, exactly as the spec requires.
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path)
        );

        return implode('/', $segments);
    }

    /**
     * Renders a query string the SigV4 way — sorted, and rawurlencoded rather than
     * `http_build_query()`'s form encoding. Public for the same reason as {@see encodePath()}.
     *
     * @param array<string, string> $query
     */
    public function encodeQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        // Sorted by encoded key — the canonical form is order-independent for the caller.
        ksort($query);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    /** kDate → kRegion → kService → kSigning, each an HMAC of the previous (raw bytes). */
    private function signingKey(string $secretKey, string $dateStamp, string $region): string
    {
        $key = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', self::SERVICE, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }
}
