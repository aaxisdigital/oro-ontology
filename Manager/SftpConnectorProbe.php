<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * The sftp arm of {@see ConnectorTester}: 1) TCP socket to server:port (reports the SSH banner
 * when one is sent); 2) authenticate with the informed user + password/key — phpseclib3 preferred,
 * ext-ssh2 fallback for passwords; auth "none" skips the step.
 *
 * The SSH libraries sit behind protected one-call methods so a test can stand in for a server.
 */
class SftpConnectorProbe extends ConnectorProbe
{
    private const int SSH_TIMEOUT = 8;
    private const string MSG_SFTP_AUTH_FAILED = 'SFTP authentication failed: ';

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    public function test(array $config): array
    {
        $host = trim((string) ($config['server'] ?? ''));
        $port = $this->normalizePort($config['port'] ?? null, 22);

        $problem = $this->endpointProblem($host, $port);
        if ($problem !== null) {
            return $problem;
        }

        [$steps, $reachable] = $this->socketSteps($host, $port, true, 'Authentication');
        if ($reachable) {
            $steps[] = $this->authStep(
                $host,
                $port,
                trim((string) ($config['user'] ?? '')),
                (string) ($config['auth'] ?? 'none'),
                $config
            );
        }

        return $this->result($steps);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{label: string, success: bool, message: string}
     */
    private function authStep(string $host, int $port, string $user, string $auth, array $config): array
    {
        return match (true) {
            $auth === 'none' => $this->step('Authentication', true, 'No authentication configured — skipped.'),
            $user === '' => $this->step('Authentication', false, 'No user configured.'),
            default => $this->step('Authentication', ...$this->authenticate($host, $port, $user, $auth, $config)),
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string}
     */
    private function authenticate(string $host, int $port, string $user, string $auth, array $config): array
    {
        $password = (string) ($config['password'] ?? '');
        $key = (string) ($config['key'] ?? '');
        $secret = $auth === 'key' ? $key : $password;

        return match (true) {
            $secret === '' => [false, $auth === 'key' ? 'No key value available to test.' : 'No password value available to test.'],
            $this->phpseclibAvailable() => $this->authenticateWithPhpseclib($host, $port, $user, $auth, $password, $key),
            $auth === 'password' && $this->ssh2Available() => $this->authenticateWithExtSsh2($host, $port, $user, $password),
            default => [false, 'No SSH client is available on the server — install the phpseclib/phpseclib package'
                . ($auth === 'password' ? ' or ext-ssh2' : '') . ' to test authentication.'],
        };
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function authenticateWithPhpseclib(string $host, int $port, string $user, string $auth, string $password, string $key): array
    {
        try {
            $sftp = $this->createSftp($host, $port, self::SSH_TIMEOUT);
            $credential = $auth === 'key' ? \phpseclib3\Crypt\PublicKeyLoader::load($key) : $password;
            $accepted = $sftp->login($user, $credential);
        } catch (\Throwable $e) {
            return [false, self::MSG_SFTP_AUTH_FAILED . $e->getMessage()];
        }

        $credentialKind = $auth === 'key' ? 'key' : 'password';

        return $accepted
            ? [true, sprintf('Authenticated as "%s".', $user)]
            : [false, sprintf('The server rejected the %s for user "%s".', $credentialKind, $user)];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function authenticateWithExtSsh2(string $host, int $port, string $user, string $password): array
    {
        try {
            $accepted = $this->ssh2Authenticate($host, $port, $user, $password);
        } catch (\Throwable $e) {
            return [false, self::MSG_SFTP_AUTH_FAILED . $e->getMessage()];
        }

        return match (true) {
            $accepted === null => [false, 'Could not open an SSH session (ext-ssh2).'],
            $accepted => [true, sprintf('Authenticated as "%s".', $user)],
            default => [false, sprintf('The server rejected the password for user "%s".', $user)],
        };
    }

    // I/O boundaries to the SSH libraries: protected, one call each.

    protected function phpseclibAvailable(): bool
    {
        return class_exists(\phpseclib3\Net\SFTP::class);
    }

    /** Creating the client does not connect; the connection is made by its login(). */
    protected function createSftp(string $host, int $port, int $timeout): \phpseclib3\Net\SFTP
    {
        return new \phpseclib3\Net\SFTP($host, $port, $timeout);
    }

    protected function ssh2Available(): bool
    {
        return \function_exists('ssh2_connect');
    }

    /**
     * Password login through ext-ssh2: null when no SSH session could be opened, otherwise whether
     * the server accepted the credentials.
     */
    protected function ssh2Authenticate(string $host, int $port, string $user, string $password): ?bool
    {
        $connection = @ssh2_connect($host, $port);
        if ($connection === false) {
            return null;
        }

        return (bool) @ssh2_auth_password($connection, $user, $password);
    }
}
