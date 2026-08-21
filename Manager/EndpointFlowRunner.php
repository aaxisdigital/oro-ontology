<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Resolves an incoming "/api/aaxis/ontology/flow/<path>" request to the ENABLED flow whose
 * Endpoint trigger matches its method + path, and runs it — the HTTP twin of
 * {@see ScheduledFlowRunner} (same design-rebuild, same executor, so flowUuid minting,
 * last_executed stamping and event rows all behave identically).
 *
 * Trigger config: {enabled, method, path, public}. The path is segment-matched: a literal segment
 * must match verbatim, a "{param}" segment matches any single non-empty segment and captures it.
 * When several flows match, the one with the MOST literal segments wins (ties: lowest flow id) —
 * "orders/latest" beats "orders/{id}" for GET /orders/latest.
 *
 * The executor seeds the trigger's request variables from the input built here: every captured
 * {param} becomes a context variable under its own name, the request body arrives as `body` and
 * the headers as `headers` (see FlowDebugExecutor::initialContext).
 */
class EndpointFlowRunner
{
    use FlowDesignParserTrait;

    public const array METHODS = ['GET', 'POST', 'PUT', 'QUERY', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly FlowDebugExecutor $executor,
        private readonly DwlTransformer $dwl,
    ) {
    }

    /**
     * @return array{flow: OntologyFlow, steps: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, config: array<string, mixed>, params: array<string, string>}|null
     */
    public function match(string $method, string $requestPath): ?array
    {
        $method = strtoupper($method);
        $requestSegments = self::segments($requestPath);
        if ($requestSegments === []) {
            return null;
        }

        /** @var OntologyFlow[] $flows */
        $flows = $this->doctrine->getRepository(OntologyFlow::class)->findBy([
            'type' => OntologyFlow::TYPE_FLOW,
            'enabled' => true,
            'triggerType' => 'endpoint',
        ], ['id' => 'ASC']);

        $best = null;
        $bestLiterals = -1;
        foreach ($flows as $flow) {
            $parsed = $this->parseDesign($flow->getDesign(), 'endpoint');
            if ($parsed === null) {
                continue;
            }
            [$steps, $links, $trigger] = $parsed;
            $config = \is_array($trigger['config']) ? $trigger['config'] : [];
            if (strtoupper((string) ($config['method'] ?? 'GET')) !== $method) {
                continue;
            }
            $matched = self::matchSegments(self::segments((string) ($config['path'] ?? '')), $requestSegments);
            if ($matched === null) {
                continue;
            }
            [$params, $literals] = $matched;
            if ($literals > $bestLiterals) {
                $bestLiterals = $literals;
                $best = ['flow' => $flow, 'steps' => $steps, 'links' => $links, 'config' => $config, 'params' => $params];
            }
        }

        return $best;
    }

    /**
     * Runs a matched flow over the request data and returns the final context.
     *
     * @param array{flow: OntologyFlow, steps: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, config: array<string, mixed>, params: array<string, string>} $match
     * @param array<string, string> $headers
     * @param array<string, mixed>  $queryParams the request's query-string parameters
     * @param array<string, mixed>  $extraInput additional trigger variables (e.g. OAuthApplication
     *                                          for authenticated calls — a key is seeded only when
     *                                          present, see FlowDebugExecutor::initialContext)
     *
     * @return array<string, mixed>
     */
    public function run(array $match, mixed $body, array $headers, array $queryParams = [], array $extraInput = [], array $runInfo = []): array
    {
        return $this->executor->execute($match['steps'], $match['links'], [
            'params' => $match['params'],
            'body' => $body,
            'headers' => $headers,
            'queryParams' => $queryParams,
        ] + $extraInput, $match['flow'], null, null, $runInfo !== [] ? $runInfo : ['trigger' => 'endpoint']);
    }

    /**
     * The trigger's optional Response binding: a DWL expression evaluated against the FINAL
     * context that must produce `{statusCode, body}` — either element a fixed value or a context
     * variable (e.g. `{statusCode: 200, body: payload}`). Returns null when the trigger defines
     * none (the caller then answers with its default shape).
     *
     * @param array<string, mixed> $config  the endpoint trigger's config
     * @param array<string, mixed> $context the run's final context
     *
     * @return array{statusCode: int, body: mixed}|null
     *
     * @throws \RuntimeException when the expression fails or produces an unusable shape
     */
    public function respond(array $config, array $context): ?array
    {
        $expression = trim((string) ($config['response'] ?? ''));
        if ($expression === '') {
            return null;
        }
        try {
            $result = $this->dwl->transform($expression, $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException('the response binding failed: ' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($result) || array_is_list($result)) {
            throw new \RuntimeException(sprintf(
                'the response binding must produce an object like {statusCode: 200, body: payload}, got %s',
                get_debug_type($result)
            ));
        }
        $status = $result['statusCode'] ?? 200;
        if (\is_float($status) && floor($status) === $status) {
            $status = (int) $status;
        }
        if (!\is_int($status) || $status < 100 || $status > 599) {
            throw new \RuntimeException(sprintf(
                'the response statusCode must be an HTTP status between 100 and 599, got %s',
                \is_scalar($status) ? var_export($status, true) : get_debug_type($status)
            ));
        }

        return ['statusCode' => $status, 'body' => $result['body'] ?? null];
    }

    /**
     * @return array<int, string> normalized, non-empty path segments
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, " \t/")), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param array<int, string> $pattern the trigger's configured segments
     * @param array<int, string> $request the incoming path's segments
     *
     * @return array{0: array<string, string>, 1: int}|null [captured params, literal-segment count]
     */
    private static function matchSegments(array $pattern, array $request): ?array
    {
        if ($pattern === [] || \count($pattern) !== \count($request)) {
            return null;
        }
        $params = [];
        $literals = 0;
        foreach ($pattern as $i => $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $m) === 1) {
                $params[$m[1]] = $request[$i];
                continue;
            }
            if ($segment !== $request[$i]) {
                return null;
            }
            $literals++;
        }

        return [$params, $literals];
    }
}
