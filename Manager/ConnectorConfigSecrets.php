<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * Handles the secret values inside a connector's JSON configuration (passwords, keys,
 * Authorization headers, OAuth secrets...):
 *
 * - {@see mask()} replaces every secret value with the {@see SENTINEL} before the config is
 *   shown to a client (form textarea, view page). The real values never leave the server.
 * - {@see merge()} does the reverse on submit: a secret still holding the sentinel means
 *   "keep the stored value" and is restored from the previously persisted config at the
 *   same path. A sentinel with no stored counterpart collapses to ''.
 *
 * A key counts as secret when it matches {@see SECRET_KEYS} exactly (case-insensitive), or
 * when its normalized form ('-'/' ' → '_') ends in _key/_token/_secret/_password — so
 * header names like "X-Api-Key" or "X-Auth-Token" are masked too.
 */
class ConnectorConfigSecrets
{
    public const string SENTINEL = '********';

    private const array SECRET_KEYS = [
        'password', 'passphrase', 'key', 'private_key', 'secret', 'client_secret',
        'api_key', 'apikey', 'token', 'access_token', 'refresh_token', 'authorization',
    ];

    private const array SECRET_SUFFIXES = ['_key', '_token', '_secret', '_password'];

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array<string, mixed>|null
     */
    public function mask(?array $config): ?array
    {
        return $config === null ? null : $this->maskArray($config);
    }

    /**
     * @param array<string, mixed>|null $submitted the client-submitted config (may hold sentinels)
     * @param array<string, mixed>|null $original  the previously stored config
     *
     * @return array<string, mixed>|null
     */
    public function merge(?array $submitted, ?array $original): ?array
    {
        return $submitted === null ? null : $this->mergeArray($submitted, $original ?? []);
    }

    private function maskArray(array $config): array
    {
        $out = [];
        foreach ($config as $key => $value) {
            if (\is_array($value)) {
                $out[$key] = $this->maskArray($value);
            } elseif (\is_string($value) && $value !== '' && $this->isSecretKey($key)) {
                $out[$key] = self::SENTINEL;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function mergeArray(array $submitted, array $original): array
    {
        $out = [];
        foreach ($submitted as $key => $value) {
            if (\is_array($value)) {
                $storedBranch = $original[$key] ?? null;
                $out[$key] = $this->mergeArray($value, \is_array($storedBranch) ? $storedBranch : []);
            } elseif ($value === self::SENTINEL && $this->isSecretKey($key)) {
                $stored = $original[$key] ?? null;
                $out[$key] = \is_string($stored) && $stored !== '' ? $stored : '';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isSecretKey(string|int $key): bool
    {
        if (!\is_string($key)) {
            return false;
        }
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (\in_array($normalized, self::SECRET_KEYS, true)) {
            return true;
        }
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
