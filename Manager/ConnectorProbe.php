<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * The plumbing every connector check shares: the {success, message, steps} result shape, the
 * "no server / bad port" pre-check, the TCP socket step (with its "skipped" follow-up when the
 * server is unreachable) and port normalization. {@see ConnectorTester} and the per-transport
 * probes ({@see SftpConnectorProbe}, {@see DatabaseConnectorProbe}) build on it.
 *
 * Result shape: {success, message, steps: [{label, success, message}]}; the overall message is
 * the FIRST failing step's, or "All checks passed.". Messages NEVER contain credentials.
 *
 * The one I/O boundary here — {@see connectTcp()} — is protected so a test can stand in for the
 * network.
 */
abstract class ConnectorProbe
{
    protected const int SOCKET_TIMEOUT = 5;

    private const string MSG_NO_SERVER = 'No server configured.';
    private const string MSG_INVALID_PORT = 'The port must be between 1 and 65535.';
    private const string MSG_UNREACHABLE = 'Skipped — the server is unreachable.';

    /**
     * The failed result for an endpoint that cannot be dialled at all (no host, invalid port), or
     * null when the endpoint is usable.
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}|null
     */
    protected function endpointProblem(string $host, ?int $port): ?array
    {
        $message = match (true) {
            $host === '' => self::MSG_NO_SERVER,
            $port === null => self::MSG_INVALID_PORT,
            default => null,
        };

        return $message === null ? null : $this->result([$this->step('Socket', false, $message)]);
    }

    /**
     * The Socket step, followed — when the server cannot be reached — by the "skipped" step of the
     * check that depends on it (that check is not attempted then).
     *
     * @return array{0: list<array{label: string, success: bool, message: string}>, 1: bool} the steps so far, and whether the server is reachable
     */
    protected function socketSteps(string $host, int $port, bool $readSshBanner, string $dependentLabel): array
    {
        [$ok, $message] = $this->openSocket($host, $port, $readSshBanner);
        $steps = [$this->step('Socket', $ok, $message)];
        if (!$ok) {
            $steps[] = $this->step($dependentLabel, false, self::MSG_UNREACHABLE);
        }

        return [$steps, $ok];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function openSocket(string $host, int $port, bool $readSshBanner): array
    {
        $errno = 0;
        $errstr = '';
        $socket = $this->connectTcp('tcp://' . $host . ':' . $port, $errno, $errstr);
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
    protected function normalizePort(mixed $value, int $default): ?int
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
     * @param array<int, array{label: string, success: bool, message: string}> $steps
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    protected function result(array $steps): array
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
    protected function step(string $label, bool $success, string $message): array
    {
        return ['label' => $label, 'success' => $success, 'message' => $message];
    }

    /**
     * @return resource|false
     */
    protected function connectTcp(string $address, int &$errorCode, string &$errorMessage)
    {
        return @stream_socket_client($address, $errorCode, $errorMessage, self::SOCKET_TIMEOUT);
    }
}
