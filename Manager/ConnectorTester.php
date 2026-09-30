<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs the "Test" checks of the connector Configure popups against a (possibly unsaved)
 * configuration. Secrets in the config MUST already be resolved by the caller
 * ({@see ConnectorConfigSecrets::merge()}) — this class never sees the ******** sentinel.
 *
 * Per type:
 * - file_system: one step — the base path exists, is a directory and is readable.
 * - sftp:        1) TCP socket to server:port (reports the SSH banner when one is sent);
 *                2) authenticate with the informed user + password/key (phpseclib3 preferred,
 *                   ext-ssh2 fallback for passwords; auth "none" skips the step).
 * - rest_api:    1) TCP socket to server:port (port defaults from the scheme);
 *                2) auth "oauth" only — POST to the OAuth path with the informed headers and
 *                   form-encoded body, success = HTTP status < 400.
 * - bucket:      1) TCP socket to server:port (port defaults from the scheme);
 *                2) list the configured bucket with the informed access key / secret key
 *                   (AWS SigV4, {@see S3RequestSigner}) — so the step validates the credentials
 *                   AND that the bucket exists on that endpoint.
 * - database:    1) TCP socket to server:port (default 5432);
 *                2) open a real PDO connection to the informed database as the informed user
 *                   (reports the server version);
 *                3) only when a schema is configured — it exists and the user has USAGE on it.
 *                PostgreSQL only for now ({@see ENGINE_POSTGRESQL}).
 *
 * Result shape: {success, message, steps: [{label, success, message}]}. Messages are plain
 * English (matching the connection-tester convention) and NEVER contain credentials.
 *
 * The file_system, rest_api and bucket checks live here; the sftp and database ones are delegated
 * to {@see SftpConnectorProbe} and {@see DatabaseConnectorProbe} (same shared plumbing,
 * {@see ConnectorProbe}).
 */
class ConnectorTester extends ConnectorProbe
{
    public const string ENGINE_POSTGRESQL = 'postgresql';

    private const int HTTP_TIMEOUT = 10;

    private const string LABEL_PATH_CHECK = 'Path check';
    private const string MSG_NO_HTTP_CLIENT = 'The Symfony HTTP client is not available on the server.';
    private const string MSG_HTTP_STATUS = 'HTTP %d from %s.';

    private readonly SftpConnectorProbe $sftp;
    private readonly DatabaseConnectorProbe $database;

    /**
     * The sftp and database arms have their own classes; they are created here unless given.
     */
    public function __construct(
        private readonly S3RequestSigner $signer,
        ?SftpConnectorProbe $sftp = null,
        ?DatabaseConnectorProbe $database = null,
    ) {
        $this->sftp = $sftp ?? new SftpConnectorProbe();
        $this->database = $database ?? new DatabaseConnectorProbe();
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    public function test(string $type, ?array $config): array
    {
        $config ??= [];

        return match ($type) {
            OntologyConnector::TYPE_FILE_SYSTEM => $this->testFileSystem($config),
            OntologyConnector::TYPE_SFTP => $this->sftp->test($config),
            OntologyConnector::TYPE_REST_API => $this->testRestApi($config),
            OntologyConnector::TYPE_BUCKET => $this->testBucket($config),
            OntologyConnector::TYPE_DATABASE => $this->database->test($config),
            default => $this->result([$this->step('Configuration', false, 'Unknown connector type.')]),
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testFileSystem(array $config): array
    {
        [$ok, $message] = $this->checkBasePath(trim((string) ($config['base_path'] ?? '')));

        return $this->result([$this->step(self::LABEL_PATH_CHECK, $ok, $message)]);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkBasePath(string $path): array
    {
        return match (true) {
            $path === '' => [false, 'No base path configured.'],
            !file_exists($path) => [false, sprintf('Path "%s" does not exist on the server.', $path)],
            !is_dir($path) => [false, sprintf('Path "%s" is not a directory.', $path)],
            !is_readable($path) => [false, sprintf('Path "%s" is not readable by the application.', $path)],
            default => [true, sprintf('Path "%s" exists and is readable.', $path)],
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testRestApi(array $config): array
    {
        [$scheme, $host, $port] = $this->resolveHttpEndpoint($config);

        $problem = $this->endpointProblem($host, $port);
        if ($problem !== null) {
            return $problem;
        }

        [$steps, $reachable] = $this->socketSteps($host, $port, false, 'OAuth');
        if ($reachable) {
            $steps[] = ((string) ($config['auth'] ?? 'none')) === 'oauth'
                ? $this->step('OAuth', ...$this->oauthCall($scheme, $host, $port, $config))
                : $this->step('Authentication', true, 'Only socket connectivity is tested for this authentication mode.');
        }

        return $this->result($steps);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string}
     */
    private function oauthCall(string $scheme, string $host, int $port, array $config): array
    {
        $oauth = \is_array($config['oauth'] ?? null) ? $config['oauth'] : [];
        $path = trim((string) ($oauth['path'] ?? ''));
        $client = $path === '' ? null : $this->createHttpClient();
        $unusable = match (true) {
            $path === '' => 'No OAuth path configured.',
            $client === null => self::MSG_NO_HTTP_CLIENT,
            default => null,
        };
        if ($unusable !== null) {
            return [false, $unusable];
        }

        $url = $scheme . '://' . $host . ':' . $port . '/' . ltrim($path, '/');
        $headers = array_merge(
            $this->stringMap($config['headers'] ?? null),
            $this->stringMap($oauth['headers'] ?? null)
        );
        $body = $this->stringMap($oauth['body'] ?? null);

        try {
            $status = $client->request('POST', $url, [
                'headers' => $headers,
                'body' => $body === [] ? null : http_build_query($body),
            ])->getStatusCode();
        } catch (\Throwable $e) {
            return [false, 'The OAuth request failed: ' . $e->getMessage()];
        }

        return [$status < 400, sprintf(self::MSG_HTTP_STATUS, $status, $url)];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testBucket(array $config): array
    {
        [$scheme, $host, $port] = $this->resolveHttpEndpoint($config);

        $problem = $this->endpointProblem($host, $port);
        if ($problem !== null) {
            return $problem;
        }

        [$steps, $reachable] = $this->socketSteps($host, $port, false, 'Bucket');
        if ($reachable) {
            $steps[] = $this->step('Bucket', ...$this->bucketListCall($scheme, $host, $port, $config));
        }

        return $this->result($steps);
    }

    /**
     * Lists one object of the configured bucket with a SigV4-signed request — the cheapest call
     * that exercises the credentials and the bucket name together.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string}
     */
    private function bucketListCall(string $scheme, string $host, int $port, array $config): array
    {
        $bucket = trim((string) ($config['bucket_name'] ?? ''));
        $accessKey = trim((string) ($config['access_key'] ?? ''));
        $secretKey = (string) ($config['secret_key'] ?? '');

        $missing = match (true) {
            $bucket === '' => 'No bucket name configured.',
            $accessKey === '' => 'No access key configured.',
            $secretKey === '' => 'No secret key value available to test.',
            default => null,
        };
        if ($missing !== null) {
            return [false, $missing];
        }
        $client = $this->createHttpClient();
        if ($client === null) {
            return [false, self::MSG_NO_HTTP_CLIENT];
        }

        return $this->signedBucketList($client, $scheme, $host, $port, $bucket, $accessKey, $secretKey);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function signedBucketList(
        HttpClientInterface $client,
        string $scheme,
        string $host,
        int $port,
        string $bucket,
        string $accessKey,
        string $secretKey
    ): array {
        // Path-style addressing (endpoint/bucket) — supported by every S3-compatible provider,
        // and it keeps the signed host independent of the bucket name.
        $hostHeader = $this->signer->hostHeader($scheme, $host, $port);
        $path = '/' . $bucket;
        $query = ['list-type' => '2', 'max-keys' => '1'];
        $url = $scheme . '://' . $hostHeader . $path . '?' . http_build_query($query);

        try {
            $headers = $this->signer->headers(
                'GET',
                $hostHeader,
                $path,
                $query,
                $accessKey,
                $secretKey,
                $this->signer->regionFromHost($host)
            );
            $status = $client->request('GET', $url, ['headers' => $headers])->getStatusCode();
        } catch (\Throwable $e) {
            return [false, 'The bucket request failed: ' . $e->getMessage()];
        }

        return match (true) {
            $status < 400 => [true, sprintf('Listed bucket "%s" (HTTP %d).', $bucket, $status)],
            $status === 403 => [false, sprintf(
                'Access denied (HTTP 403) — the access key/secret key pair was rejected, or it has no '
                . 'permission on bucket "%s". A key created moments ago can also need a few minutes '
                . 'to become active.',
                $bucket
            )],
            $status === 404 => [false, sprintf('Bucket "%s" does not exist on this endpoint (HTTP 404).', $bucket)],
            default => [false, sprintf(self::MSG_HTTP_STATUS, $status, $scheme . '://' . $hostHeader . $path)],
        };
    }

    /**
     * Splits the configured server into scheme/host and resolves the effective port
     * (explicit config port > port in the server URL > scheme default; https assumed
     * when no scheme is given). Shared by the rest_api and bucket tests.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function resolveHttpEndpoint(array $config): array
    {
        $server = trim((string) ($config['server'] ?? ''));
        $scheme = 'https';
        $urlPort = null;

        if (preg_match('~^(https?)://~i', $server, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            $parts = parse_url($server);
            $host = (string) ($parts['host'] ?? '');
            $urlPort = isset($parts['port']) ? (int) $parts['port'] : null;
        } else {
            // No scheme: strip any path suffix ("api.example.com/v1" → "api.example.com").
            $host = explode('/', $server)[0];
            if (str_contains($host, ':')) {
                [$host, $rawPort] = explode(':', $host, 2);
                $urlPort = (int) $rawPort;
            }
        }

        $port = $this->normalizePort($config['port'] ?? null, $urlPort ?? ($scheme === 'http' ? 80 : 443));

        return [$scheme, $host, $port];
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && $key !== '' && \is_scalar($item)) {
                $out[$key] = (string) $item;
            }
        }

        return $out;
    }

    // I/O boundary to the HTTP client: protected so a test can stand in for the network.

    /** Null when the Symfony HTTP client is not installed. */
    protected function createHttpClient(): ?HttpClientInterface
    {
        return class_exists(HttpClient::class)
            ? HttpClient::create(['timeout' => self::HTTP_TIMEOUT, 'max_redirects' => 3])
            : null;
    }
}
