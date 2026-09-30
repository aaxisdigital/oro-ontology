<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Aaxis\Bundle\OntologyBundle\Manager\OntologyBucketClient;

/**
 * An object store in a PHP array standing in for the S3 bucket behind OntologyBucketClient.
 * Keys list in binary order, like S3.
 */
class InMemoryBucketClient extends OntologyBucketClient
{
    /** @var array<string, string> key => body */
    public array $objects = [];

    public bool $enabled = true;
    public bool $configured = true;
    public string $base = 'aaxis-ontology';

    /** @var array<int, string> "VERB key" of every call, in order */
    public array $calls = [];

    public function __construct()
    {
        // Deliberately no parent constructor: nothing here talks to config, crypto or HTTP.
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isEnabledFor(string $toggleKey): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function basePath(): string
    {
        return $this->base;
    }

    public function get(string $key): ?string
    {
        $this->calls[] = 'GET ' . $key;

        return $this->objects[$key] ?? null;
    }

    public function put(string $key, string $body): void
    {
        $this->calls[] = 'PUT ' . $key;
        $this->objects[$key] = $body;
    }

    public function delete(string $key): void
    {
        $this->calls[] = 'DELETE ' . $key;
        unset($this->objects[$key]);
    }

    public function listKeys(string $prefix): array
    {
        $this->calls[] = 'LIST ' . $prefix;
        $keys = array_values(array_filter(array_keys($this->objects), static fn (string $key): bool => str_starts_with($key, $prefix)));
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function listPrefixes(string $prefix): array
    {
        $this->calls[] = 'PREFIXES ' . $prefix;
        $folders = [];
        foreach (array_keys($this->objects) as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $rest = substr($key, \strlen($prefix));
            $slash = strpos($rest, '/');
            if ($slash !== false) {
                $folders[$prefix . substr($rest, 0, $slash + 1)] = true;
            }
        }
        $folders = array_keys($folders);
        sort($folders, SORT_STRING);

        return $folders;
    }
}
