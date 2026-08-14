<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Executes a flow definition in DEBUG mode: walks the links breadth-first from the trigger and
 * accumulates an output context keyed by the steps' destinations, which the editor shows as JSON.
 *
 * Per-type behaviour (grows as step types gain execution semantics):
 *  - entity_change trigger: seeds the context with the payload the user provided (`payload` key);
 *  - reader/entity: real data via {@see OntologyDataApiManager} (`all` = first page of records,
 *    `by_id` = one record's payload — a MISSING record yields null, it is not an error);
 *  - reader/connector: rest_api connectors execute for real (URL from the connector's
 *    server/port + the step's path; connector headers + auth headers; auth=oauth first POSTs the
 *    token path and attaches the returned access_token; the step's operation/body are honoured);
 *    sftp/file_system connectors emit a placeholder note;
 *  - everything else: no-op pass-through for now.
 */
class FlowDebugExecutor
{
    /** Debug cap for "load all" entity readers (the manager also applies the config page cap). */
    private const int MAX_RECORDS = 100;

    private const int TIMEOUT = 30;

    public function __construct(
        private readonly OntologyDataApiManager $dataApi,
        private readonly ManagerRegistry $doctrine,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<int, array{id: string, type: string, name: string, config: array<string, mixed>|null}> $steps
     * @param array<int, array{from: string, fromPort: int, to: string}> $links
     * @param array<string, mixed> $input trigger input (entity_change: system/entity/payload)
     *
     * @return array<string, mixed> the accumulated output context
     *
     * @throws \RuntimeException with a user-readable message when a step cannot execute
     */
    public function execute(array $steps, array $links, array $input): array
    {
        $byId = [];
        $trigger = null;
        foreach ($steps as $step) {
            $byId[$step['id']] = $step;
            if ($trigger === null && \in_array($step['type'], OntologyFlow::TRIGGER_STEP_TYPES, true)) {
                $trigger = $step;
            }
        }
        if ($trigger === null) {
            throw new \RuntimeException('The flow has no trigger to debug.');
        }

        $context = [];
        if ($trigger['type'] === 'entity_change' && \array_key_exists('payload', $input)) {
            $context['payload'] = $input['payload'];
        }

        // Execution order: breadth-first along the links (choice branches by port order).
        $outgoing = [];
        foreach ($links as $link) {
            $outgoing[$link['from']][] = $link;
        }
        foreach ($outgoing as &$list) {
            usort($list, static fn (array $a, array $b) => $a['fromPort'] <=> $b['fromPort']);
        }
        unset($list);

        $visited = [$trigger['id'] => true];
        $queue = [$trigger['id']];
        while ($queue !== []) {
            $id = array_shift($queue);
            $this->executeStep($byId[$id], $context);
            foreach ($outgoing[$id] ?? [] as $link) {
                if (isset($byId[$link['to']]) && !isset($visited[$link['to']])) {
                    $visited[$link['to']] = true;
                    $queue[] = $link['to'];
                }
            }
        }

        return $context;
    }

    /**
     * @param array{id: string, type: string, name: string, config: array<string, mixed>|null} $step
     * @param array<string, mixed>                                                             $context
     */
    private function executeStep(array $step, array &$context): void
    {
        if ($step['type'] !== 'reader') {
            return; // triggers seed the context in execute(); other types have no debug behaviour yet
        }

        $config = $step['config'];
        if (!\is_array($config)) {
            throw new \RuntimeException(sprintf('Step "%s" is not configured.', $step['name']));
        }
        $destination = trim((string) ($config['destination'] ?? ''));
        if ($destination === '') {
            throw new \RuntimeException(sprintf('Step "%s" has no destination.', $step['name']));
        }

        if (($config['reader'] ?? null) === 'entity') {
            $context[$destination] = $this->readEntity($step['name'], $config);
        } else {
            $context[$destination] = $this->readConnector($step['name'], $config);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readEntity(string $stepName, array $config): mixed
    {
        $system = (string) ($config['system'] ?? '');
        $entityName = (string) ($config['entity'] ?? '');
        try {
            if (($config['mode'] ?? 'all') === 'by_id') {
                return $this->dataApi->read($system, $entityName, (string) ($config['record_id'] ?? ''));
            }

            return $this->dataApi->query($system, $entityName, [], null, 1, self::MAX_RECORDS);
        } catch (OntologyApiException $e) {
            // A missing record is a legitimate outcome for "by id" — the step yields null.
            if ($e->getErrorCode() === 'record_not_found') {
                return null;
            }
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readConnector(string $stepName, array $config): mixed
    {
        /** @var OntologyConnector|null $connector */
        $connector = $this->doctrine->getRepository(OntologyConnector::class)->find((int) ($config['connector'] ?? 0));
        if ($connector === null) {
            throw new \RuntimeException(sprintf('Step "%s": the configured connector no longer exists.', $stepName));
        }
        if ($connector->getType() !== OntologyConnector::TYPE_REST_API) {
            return ['_debug' => sprintf('"%s" connectors are not executed in debug yet.', (string) $connector->getType())];
        }

        return $this->executeRestRead($stepName, $connector->getConfig() ?? [], $config);
    }

    /**
     * Performs the REST call a rest_api connector reader describes. Secrets are stored in clear
     * in the connector config (masking is a render-time concern), so they can be used directly.
     *
     * @param array<string, mixed> $connectorConfig
     * @param array<string, mixed> $stepConfig
     */
    private function executeRestRead(string $stepName, array $connectorConfig, array $stepConfig): mixed
    {
        $base = trim((string) ($connectorConfig['server'] ?? ''));
        if ($base === '') {
            throw new \RuntimeException(sprintf('Step "%s": the connector has no server configured.', $stepName));
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $base = rtrim($base, '/');
        $port = (int) ($connectorConfig['port'] ?? 0);
        if ($port > 0 && parse_url($base, PHP_URL_PORT) === null) {
            $base .= ':' . $port;
        }
        $url = $base . '/' . ltrim((string) ($stepConfig['path'] ?? ''), '/');

        $headers = $this->stringMap($connectorConfig['headers'] ?? null);
        $auth = (string) ($connectorConfig['auth'] ?? 'none');
        if ($auth === 'headers') {
            $headers = array_merge($headers, $this->stringMap($connectorConfig['auth_headers'] ?? null));
        } elseif ($auth === 'oauth') {
            $headers['Authorization'] = $this->fetchOAuthToken($stepName, $connectorConfig, $base);
        }

        $options = [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
            'max_redirects' => 5,
            // Internal endpoints commonly run self-signed certs — same policy as the toolbox proxy.
            'verify_peer' => false,
            'verify_host' => false,
        ];
        $bodyType = (string) ($stepConfig['body'] ?? 'empty');
        $bodyContent = (string) ($stepConfig['body_content'] ?? '');
        if ($bodyType !== 'empty' && $bodyContent !== '') {
            $options['body'] = $bodyContent;
            $options['headers']['Content-Type'] = match ($bodyType) {
                'json' => 'application/json',
                'xml' => 'application/xml',
                default => 'text/plain',
            };
        }
        $method = strtoupper((string) ($stepConfig['operation'] ?? 'get')) ?: 'GET';

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Step "%s": the connector responded HTTP %d.', $stepName, $status));
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
    }

    /**
     * OAuth (as modelled by the connector config): POST the token path with the configured
     * headers + form-encoded body and use the returned access_token as a bearer credential.
     *
     * @param array<string, mixed> $connectorConfig
     */
    private function fetchOAuthToken(string $stepName, array $connectorConfig, string $base): string
    {
        $oauth = \is_array($connectorConfig['oauth'] ?? null) ? $connectorConfig['oauth'] : [];
        $path = trim((string) ($oauth['path'] ?? ''));
        if ($path === '') {
            throw new \RuntimeException(sprintf('Step "%s": the connector has no OAuth token path configured.', $stepName));
        }

        try {
            $response = $this->httpClient->request('POST', $base . '/' . ltrim($path, '/'), [
                'headers' => $this->stringMap($oauth['headers'] ?? null),
                'body' => $this->stringMap($oauth['body'] ?? null),
                'timeout' => self::TIMEOUT,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Step "%s": OAuth token request failed — %s', $stepName, $e->getMessage()), 0, $e);
        }

        $token = \is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException(sprintf('Step "%s": the OAuth response carries no access_token.', $stepName));
        }
        $type = \is_array($data) && \is_string($data['token_type'] ?? null) && $data['token_type'] !== ''
            ? $data['token_type'] : 'Bearer';

        return $type . ' ' . $token;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && $key !== '' && is_scalar($item)) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }
}
