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
 *
 * Result shape: {success, message, steps: [{label, success, message}]}. Messages are plain
 * English (matching the connection-tester convention) and NEVER contain credentials.
 */
class ConnectorTester
{
    private const int SOCKET_TIMEOUT = 5;
    private const int SSH_TIMEOUT = 8;
    private const int HTTP_TIMEOUT = 10;

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
            // database/bucket are valid types whose config shape (and therefore test) is not
            // defined yet — say that, rather than calling a known type "unknown".
            OntologyConnector::TYPE_DATABASE, OntologyConnector::TYPE_BUCKET => $this->result([
                $this->step('Configuration', false, 'No test is defined for this connector type yet.'),
            ]),
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
        [$scheme, $host, $port] = $this->resolveRestEndpoint($config);
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
     * Splits the configured server into scheme/host and resolves the effective port
     * (explicit config port > port in the server URL > scheme default; https assumed
     * when no scheme is given).
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function resolveRestEndpoint(array $config): array
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
