<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Symfony\Component\HttpClient\HttpClient;

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
 */
class ConnectorTester
{
    public const string ENGINE_POSTGRESQL = 'postgresql';

    private const int SOCKET_TIMEOUT = 5;
    private const int SSH_TIMEOUT = 8;
    private const int HTTP_TIMEOUT = 10;
    private const int POSTGRES_DEFAULT_PORT = 5432;

    public function __construct(private readonly S3RequestSigner $signer)
    {
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
            OntologyConnector::TYPE_SFTP => $this->testSftp($config),
            OntologyConnector::TYPE_REST_API => $this->testRestApi($config),
            OntologyConnector::TYPE_BUCKET => $this->testBucket($config),
            OntologyConnector::TYPE_DATABASE => $this->testDatabase($config),
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
        $path = trim((string) ($config['base_path'] ?? ''));
        if ($path === '') {
            return $this->result([$this->step('Path check', false, 'No base path configured.')]);
        }
        if (!file_exists($path)) {
            return $this->result([$this->step('Path check', false, sprintf('Path "%s" does not exist on the server.', $path))]);
        }
        if (!is_dir($path)) {
            return $this->result([$this->step('Path check', false, sprintf('Path "%s" is not a directory.', $path))]);
        }
        if (!is_readable($path)) {
            return $this->result([$this->step('Path check', false, sprintf('Path "%s" is not readable by the application.', $path))]);
        }

        return $this->result([$this->step('Path check', true, sprintf('Path "%s" exists and is readable.', $path))]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testSftp(array $config): array
    {
        $host = trim((string) ($config['server'] ?? ''));
        $port = $this->normalizePort($config['port'] ?? null, 22);
        $user = trim((string) ($config['user'] ?? ''));
        $auth = (string) ($config['auth'] ?? 'none');

        if ($host === '') {
            return $this->result([$this->step('Socket', false, 'No server configured.')]);
        }
        if ($port === null) {
            return $this->result([$this->step('Socket', false, 'The port must be between 1 and 65535.')]);
        }

        [$socketOk, $socketMessage] = $this->openSocket($host, $port, true);
        $steps = [$this->step('Socket', $socketOk, $socketMessage)];
        if (!$socketOk) {
            // Authentication cannot be attempted if the server is unreachable.
            $steps[] = $this->step('Authentication', false, 'Skipped — the server is unreachable.');

            return $this->result($steps);
        }

        if ($auth === 'none') {
            $steps[] = $this->step('Authentication', true, 'No authentication configured — skipped.');
        } elseif ($user === '') {
            $steps[] = $this->step('Authentication', false, 'No user configured.');
        } else {
            $steps[] = $this->step('Authentication', ...$this->sftpAuthenticate($host, $port, $user, $auth, $config));
        }

        return $this->result($steps);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string}
     */
    private function sftpAuthenticate(string $host, int $port, string $user, string $auth, array $config): array
    {
        $password = (string) ($config['password'] ?? '');
        $key = (string) ($config['key'] ?? '');
        $secret = $auth === 'key' ? $key : $password;
        if ($secret === '') {
            return [false, $auth === 'key' ? 'No key value available to test.' : 'No password value available to test.'];
        }

        if (class_exists(\phpseclib3\Net\SFTP::class)) {
            try {
                $sftp = new \phpseclib3\Net\SFTP($host, $port, self::SSH_TIMEOUT);
                $credential = $auth === 'key' ? \phpseclib3\Crypt\PublicKeyLoader::load($key) : $password;
                if ($sftp->login($user, $credential)) {
                    return [true, sprintf('Authenticated as "%s".', $user)];
                }

                return [false, sprintf('The server rejected the %s for user "%s".', $auth === 'key' ? 'key' : 'password', $user)];
            } catch (\Throwable $e) {
                return [false, 'SFTP authentication failed: ' . $e->getMessage()];
            }
        }

        if ($auth === 'password' && \function_exists('ssh2_connect')) {
            try {
                $connection = @ssh2_connect($host, $port);
                if ($connection === false) {
                    return [false, 'Could not open an SSH session (ext-ssh2).'];
                }
                if (@ssh2_auth_password($connection, $user, $password)) {
                    return [true, sprintf('Authenticated as "%s".', $user)];
                }

                return [false, sprintf('The server rejected the password for user "%s".', $user)];
            } catch (\Throwable $e) {
                return [false, 'SFTP authentication failed: ' . $e->getMessage()];
            }
        }

        return [false, 'No SSH client is available on the server — install the phpseclib/phpseclib package'
            . ($auth === 'password' ? ' or ext-ssh2' : '') . ' to test authentication.'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testRestApi(array $config): array
    {
        [$scheme, $host, $port] = $this->resolveHttpEndpoint($config);
        $auth = (string) ($config['auth'] ?? 'none');

        if ($host === '') {
            return $this->result([$this->step('Socket', false, 'No server configured.')]);
        }
        if ($port === null) {
            return $this->result([$this->step('Socket', false, 'The port must be between 1 and 65535.')]);
        }

        [$socketOk, $socketMessage] = $this->openSocket($host, $port, false);
        $steps = [$this->step('Socket', $socketOk, $socketMessage)];
        if (!$socketOk) {
            $steps[] = $this->step('OAuth', false, 'Skipped — the server is unreachable.');

            return $this->result($steps);
        }

        if ($auth !== 'oauth') {
            $steps[] = $this->step('Authentication', true, 'Only socket connectivity is tested for this authentication mode.');

            return $this->result($steps);
        }

        $steps[] = $this->step('OAuth', ...$this->oauthCall($scheme, $host, $port, $config));

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
        if ($path === '') {
            return [false, 'No OAuth path configured.'];
        }
        if (!class_exists(HttpClient::class)) {
            return [false, 'The Symfony HTTP client is not available on the server.'];
        }

        $url = $scheme . '://' . $host . ':' . $port . '/' . ltrim($path, '/');
        $headers = array_merge(
            $this->stringMap($config['headers'] ?? null),
            $this->stringMap($oauth['headers'] ?? null)
        );
        $body = $this->stringMap($oauth['body'] ?? null);

        try {
            $client = HttpClient::create(['timeout' => self::HTTP_TIMEOUT, 'max_redirects' => 3]);
            $response = $client->request('POST', $url, [
                'headers' => $headers,
                'body' => $body === [] ? null : http_build_query($body),
            ]);
            $status = $response->getStatusCode();
            if ($status < 400) {
                return [true, sprintf('HTTP %d from %s.', $status, $url)];
            }

            return [false, sprintf('HTTP %d from %s.', $status, $url)];
        } catch (\Throwable $e) {
            return [false, 'The OAuth request failed: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testBucket(array $config): array
    {
        [$scheme, $host, $port] = $this->resolveHttpEndpoint($config);

        if ($host === '') {
            return $this->result([$this->step('Socket', false, 'No server configured.')]);
        }
        if ($port === null) {
            return $this->result([$this->step('Socket', false, 'The port must be between 1 and 65535.')]);
        }

        [$socketOk, $socketMessage] = $this->openSocket($host, $port, false);
        $steps = [$this->step('Socket', $socketOk, $socketMessage)];
        if (!$socketOk) {
            $steps[] = $this->step('Bucket', false, 'Skipped — the server is unreachable.');

            return $this->result($steps);
        }

        $steps[] = $this->step('Bucket', ...$this->bucketListCall($scheme, $host, $port, $config));

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

        if ($bucket === '') {
            return [false, 'No bucket name configured.'];
        }
        if ($accessKey === '') {
            return [false, 'No access key configured.'];
        }
        if ($secretKey === '') {
            return [false, 'No secret key value available to test.'];
        }
        if (!class_exists(HttpClient::class)) {
            return [false, 'The Symfony HTTP client is not available on the server.'];
        }

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
            $client = HttpClient::create(['timeout' => self::HTTP_TIMEOUT, 'max_redirects' => 3]);
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
            default => [false, sprintf('HTTP %d from %s.', $status, $scheme . '://' . $hostHeader . $path)],
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function testDatabase(array $config): array
    {
        // Absent engine = a config written before the field existed; PostgreSQL is the only value.
        $engine = trim((string) ($config['engine'] ?? self::ENGINE_POSTGRESQL));
        if ($engine !== '' && $engine !== self::ENGINE_POSTGRESQL) {
            return $this->result([$this->step(
                'Configuration',
                false,
                sprintf('Only PostgreSQL is supported for now — "%s" cannot be tested.', $engine)
            )]);
        }

        [$host, $port] = $this->resolveDatabaseEndpoint($config);
        if ($host === '') {
            return $this->result([$this->step('Socket', false, 'No server configured.')]);
        }
        if ($port === null) {
            return $this->result([$this->step('Socket', false, 'The port must be between 1 and 65535.')]);
        }

        [$socketOk, $socketMessage] = $this->openSocket($host, $port, false);
        $steps = [$this->step('Socket', $socketOk, $socketMessage)];
        if (!$socketOk) {
            $steps[] = $this->step('Connection', false, 'Skipped — the server is unreachable.');

            return $this->result($steps);
        }

        [$connected, $connectionMessage, $pdo] = $this->postgresConnect($host, $port, $config);
        $steps[] = $this->step('Connection', $connected, $connectionMessage);

        // The schema is optional — only report on it when one is configured.
        $schema = trim((string) ($config['schema'] ?? ''));
        if ($schema !== '') {
            $steps[] = $this->step('Schema', ...($pdo instanceof \PDO
                ? $this->postgresSchemaCheck($pdo, $schema)
                : [false, 'Skipped — no connection to the database.']));
        }

        return $this->result($steps);
    }

    /**
     * Opens a real connection with the informed credentials and reports the server version.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string, 2: \PDO|null}
     */
    private function postgresConnect(string $host, int $port, array $config): array
    {
        $database = trim((string) ($config['database'] ?? ''));
        $user = trim((string) ($config['user'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if ($database === '') {
            return [false, 'No database configured.', null];
        }
        if ($user === '') {
            return [false, 'No user configured.', null];
        }
        if (!\in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            return [false, 'The pdo_pgsql PHP extension is not installed on the server.', null];
        }
        // PDO splits the pgsql DSN on ";" BEFORE libpq sees it, so a value containing one would
        // inject another connection parameter — no quoting can rescue that, hence the refusal.
        foreach (['server' => $host, 'database' => $database] as $label => $value) {
            if (str_contains($value, ';')) {
                return [false, sprintf('The %s must not contain ";".', $label), null];
            }
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
            $host,
            $port,
            $database,
            self::SOCKET_TIMEOUT
        );

        try {
            // Credentials go as constructor arguments, never inside the DSN — a failure message
            // built from the DSN would otherwise carry them.
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => self::SOCKET_TIMEOUT,
            ]);
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return [
                true,
                sprintf(
                    'Connected to database "%s" as "%s"%s.',
                    $database,
                    $user,
                    $version === '' ? '' : ' — PostgreSQL ' . $version
                ),
                $pdo,
            ];
        } catch (\Throwable $e) {
            return [false, 'Connection failed: ' . $this->withoutSecret($e->getMessage(), $password), null];
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function postgresSchemaCheck(\PDO $pdo, string $schema): array
    {
        try {
            // pg_namespace, NOT information_schema.schemata: the latter only lists schemas the
            // current user OWNS, so an existing schema the user merely reads would look missing.
            // The ::int cast avoids depending on how the driver hands back a boolean.
            $statement = $pdo->prepare(
                'SELECT has_schema_privilege(nspname, \'USAGE\')::int FROM pg_catalog.pg_namespace WHERE nspname = :schema'
            );
            $statement->execute(['schema' => $schema]);
            $usable = $statement->fetchColumn();

            return match (true) {
                $usable === false => [false, sprintf('Schema "%s" does not exist in this database.', $schema)],
                (int) $usable === 1 => [true, sprintf('Schema "%s" exists and is accessible.', $schema)],
                default => [false, sprintf('Schema "%s" exists but the user has no USAGE permission on it.', $schema)],
            };
        } catch (\Throwable $e) {
            return [false, 'The schema check failed: ' . $e->getMessage()];
        }
    }

    /**
     * Resolves host/port for a database endpoint: a bare host, or "host:port" — no scheme is
     * involved, so this deliberately does not go through {@see resolveHttpEndpoint()}.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: string, 1: int|null}
     */
    private function resolveDatabaseEndpoint(array $config): array
    {
        $host = trim((string) ($config['server'] ?? ''));
        $hostPort = null;
        if (str_contains($host, ':')) {
            [$host, $rawPort] = explode(':', $host, 2);
            $hostPort = (int) $rawPort;
        }

        return [$host, $this->normalizePort($config['port'] ?? null, $hostPort ?? self::POSTGRES_DEFAULT_PORT)];
    }

    /** Belt and braces: strips a secret from a message built by code we do not control. */
    private function withoutSecret(string $message, string $secret): string
    {
        return $secret === '' ? $message : str_replace($secret, ConnectorConfigSecrets::SENTINEL, $message);
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
        $host = $server;
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
     * @return array{0: bool, 1: string}
     */
    private function openSocket(string $host, int $port, bool $readSshBanner): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, self::SOCKET_TIMEOUT);
        if ($socket === false) {
            return [false, sprintf('Could not connect to %s:%d — %s.', $host, $port, $errstr !== '' ? $errstr : 'connection failed')];
        }

        $message = sprintf('Connected to %s:%d.', $host, $port);
        if ($readSshBanner) {
            stream_set_timeout($socket, 2);
            $banner = fgets($socket, 256);
            if (\is_string($banner) && str_starts_with($banner, 'SSH-')) {
                $message .= ' Server: ' . trim($banner) . '.';
            }
        }
        fclose($socket);

        return [true, $message];
    }

    /** Returns the validated port (1–65535), the default when absent, or null when invalid. */
    private function normalizePort(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
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

    /**
     * @param array{label: string, success: bool, message: string} ...$steps
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function result(array $steps): array
    {
        $success = true;
        $failure = '';
        foreach ($steps as $step) {
            if (!$step['success'] && $success) {
                $success = false;
                $failure = $step['message'];
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'All checks passed.' : $failure,
            'steps' => array_values($steps),
        ];
    }

    /**
     * @return array{label: string, success: bool, message: string}
     */
    private function step(string $label, bool $success, string $message): array
    {
        return ['label' => $label, 'success' => $success, 'message' => $message];
    }
}
