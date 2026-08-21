<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Cron\CronExpression;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bar a flow's steps must clear to be stored — shape, per-type completeness, a parseable cron
 * expression and parseable DWL — in ONE place.
 *
 * Both writers use it: the editor's save endpoint (which reports the first problem it hits) and
 * {@see FlowPortability} when importing a file (which reports every problem at once). Keeping them
 * on the same rules is the point: an imported flow has to be exactly as valid as a hand-built one,
 * and an import must never be able to store something the editor or the executor would choke on.
 */
class FlowStepValidator
{
    public function __construct(
        private readonly DwlTransformer $dwl,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function normalize(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $steps = [];
        foreach ($raw as $step) {
            $name = \is_string($step['name'] ?? null) ? trim($step['name']) : '';
            $config = $step['config'] ?? null;
            if (!\is_array($step) || !\in_array($step['type'] ?? null, OntologyFlow::STEP_TYPES, true)
                || $name === '' || mb_strlen($name) > 64
                || !is_numeric($step['x'] ?? null) || !is_numeric($step['y'] ?? null)
                || ($config !== null && (!\is_array($config) || array_is_list($config)))
            ) {
                return null;
            }
            $steps[] = [
                'type' => (string) $step['type'],
                'name' => $name,
                'x' => max(0, (int) $step['x']),
                'y' => max(0, (int) $step['y']),
                'config' => $config,
            ];
        }

        return $steps;
    }

    /**
     * Every problem with an already-normalized step list, as translated messages (empty = valid) —
     * structural problems plus every per-step one. This FULL bar still gates imports and RUNS
     * ({@see FlowDebugExecutor}); saving is gentler ({@see structuralErrors}): incomplete steps may
     * be stored, they just render red in the editor and keep the flow from running.
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array<int, string>
     */
    public function validate(array $steps): array
    {
        return array_merge($this->structuralErrors($steps), array_values($this->stepErrors($steps)));
    }

    /**
     * The problems that BLOCK a save: only what would corrupt the definition itself (duplicate
     * step names — links and configs address steps by name downstream).
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array<int, string>
     */
    public function structuralErrors(array $steps): array
    {
        $errors = [];
        $stepNames = array_map(static fn (array $s) => mb_strtolower((string) $s['name']), $steps);
        if (\count($stepNames) !== \count(array_unique($stepNames))) {
            $errors[] = $this->trans('step_names_unique');
        }
        // The trigger IS the flow's identity (name + enabled) — a definition without one is not
        // storable, importable or runnable. (The two step-less NATIVE flows never pass through
        // here: they are read-only.)
        $hasTrigger = false;
        foreach ($steps as $step) {
            if (\in_array($step['type'] ?? null, OntologyFlow::TRIGGER_STEP_TYPES, true)) {
                $hasTrigger = true;
                break;
            }
        }
        if (!$hasTrigger) {
            $errors[] = $this->trans('trigger_required');
        }

        return $errors;
    }

    /**
     * Per-step problems, keyed by STEP NAME (first problem per step): an incomplete/invalid config,
     * an invalid cron expression or unparseable DWL. A step's config is optional (unconfigured
     * steps may be saved mid-design), but a PRESENT config must be complete and valid for its type.
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array<string, string>
     */
    public function stepErrors(array $steps): array
    {
        $errors = [];
        foreach ($steps as $step) {
            $name = (string) $step['name'];
            $expression = $step['config']['expression'] ?? null;
            if ($step['type'] === 'cron' && $expression !== null
                && !CronExpression::isValidExpression((string) $expression)
            ) {
                $errors[$name] ??= $this->trans('invalid_cron', ['{{ name }}' => $name]);
            }
            if (!$this->isStepConfigValid($step)) {
                $errors[$name] ??= $this->trans('invalid_step_config', ['{{ name }}' => $name]);
            }
            foreach ($this->stepDwlSnippets($step) as $snippet) {
                $dwlError = $this->dwl->validate($snippet);
                if ($dwlError !== null) {
                    $errors[$name] ??= $this->trans('invalid_dwl', [
                        '{{ name }}' => $name,
                        '{{ error }}' => $dwlError,
                    ]);
                }
            }
        }

        return $errors;
    }

    /**
     * The names of the steps whose configuration keeps the flow from RUNNING — what the editor
     * paints with a red border.
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array<int, string>
     */
    public function invalidStepNames(array $steps): array
    {
        return array_keys($this->stepErrors($steps));
    }

    private function stepDwlSnippets(array $step): array
    {
        $config = $step['config'];
        if (!\is_array($config)) {
            return [];
        }
        $snippets = [];
        if ($step['type'] === 'dwl_transform' && \is_string($config['code'] ?? null) && trim($config['code']) !== '') {
            $snippets[] = $config['code'];
        }
        if ($step['type'] === 'invoke' && ($config['body_dwl'] ?? false) === true
            && \is_string($config['body_content'] ?? null) && trim($config['body_content']) !== ''
        ) {
            $snippets[] = $config['body_content'];
        }
        if (\in_array($step['type'], ['entity_write', 'file_write'], true) && ($config['content_dwl'] ?? false) === true
            && \is_string($config['content'] ?? null) && trim($config['content']) !== ''
        ) {
            $snippets[] = $config['content'];
        }
        // File Operations: the path (all five) and the rename's new name are DWL-capable too.
        if (str_starts_with($step['type'], 'file_') && ($config['path_dwl'] ?? false) === true
            && \is_string($config['path'] ?? null) && trim($config['path']) !== ''
        ) {
            $snippets[] = $config['path'];
        }
        if ($step['type'] === 'file_rename' && ($config['new_name_dwl'] ?? false) === true
            && \is_string($config['new_name'] ?? null) && trim($config['new_name']) !== ''
        ) {
            $snippets[] = $config['new_name'];
        }
        if ($step['type'] === 'choice' && \is_string($config['expression'] ?? null) && trim($config['expression']) !== '') {
            $snippets[] = $config['expression'];
        }
        // Invoke PHP: the parameters object is ALWAYS a DWL expression.
        if ($step['type'] === 'invoke_php' && \is_string($config['params'] ?? null) && trim($config['params']) !== '') {
            $snippets[] = $config['params'];
        }
        // Endpoint trigger: the optional response binding is ALWAYS a DWL expression.
        if ($step['type'] === 'endpoint' && \is_string($config['response'] ?? null) && trim($config['response']) !== '') {
            $snippets[] = $config['response'];
        }
        // Event: the value is DWL only while its toggle is on (plain text otherwise).
        if ($step['type'] === 'event' && ($config['value_dwl'] ?? false) === true
            && \is_string($config['value'] ?? null) && trim($config['value']) !== ''
        ) {
            $snippets[] = $config['value'];
        }
        // Logger: the message is DWL only while its toggle is on (plain text otherwise).
        if ($step['type'] === 'logger' && ($config['message_dwl'] ?? false) === true
            && \is_string($config['message'] ?? null) && trim($config['message']) !== ''
        ) {
            $snippets[] = $config['message'];
        }
        // SQL Query: the SQL text and the bindings are DWL-capable too.
        if ($step['type'] === 'sql_query') {
            if (($config['sql_dwl'] ?? false) === true && \is_string($config['sql'] ?? null) && trim($config['sql']) !== '') {
                $snippets[] = $config['sql'];
            }
            if (($config['binding_dwl'] ?? false) === true && \is_string($config['binding'] ?? null) && trim($config['binding']) !== '') {
                $snippets[] = $config['binding'];
            }
        }

        return $snippets;
    }

    private function isStepConfigValid(array $step): bool
    {
        // A missing config is NOT a free pass: a type that needs parameters is exactly as broken
        // never-configured as it is half-configured (fresh drops start red and the flow cannot
        // run until they are completed). Types whose arms accept an empty config — triggers via
        // $onlyEnabled(), parameterless placeholders via the default arm — stay valid.
        $config = \is_array($step['config']) ? $step['config'] : [];
        $filled = static fn (string $key): bool => \is_string($config[$key] ?? null) && trim($config[$key]) !== '';

        // Enum keys are lenient when ABSENT (configs saved by older editors) but strict when set.
        $enumOk = static fn (string $key, array $allowed): bool =>
            !isset($config[$key]) || \in_array($config[$key], $allowed, true);

        // The DWL on/off toggles (body_dwl / content_dwl) are lenient when absent, bool when set.
        $boolOk = static fn (string $key): bool => !isset($config[$key]) || \is_bool($config[$key]);

        // A trigger holding ONLY its enabled flag counts as "not configured yet" (the editor seeds
        // {enabled: true} the moment a trigger is dropped, so the flow starts armed without the
        // fresh tile turning red for the fields the user has not filled in yet). Endpoint drops
        // also seed the Response EXAMPLE, so that key is ignored by the test too.
        $onlyEnabled = static fn (): bool => array_diff_key($config, ['enabled' => 0, 'response' => 0]) === [];

        // "flowUuid" is reserved (every execution seeds its uuid into the context under it) and so
        // is "choiceResults" (choice steps record their branch verdicts there); the legacy
        // "flow-uuid" spelling stays rejected too.
        $destinationOk = static fn (): bool =>
            $filled('destination')
            && !\in_array(
                strtolower(trim((string) $config['destination'])),
                ['flowuuid', 'flow-uuid', 'choiceresults'],
                true
            );

        $connectorOk = static fn (): bool =>
            is_scalar($config['connector'] ?? null) && (string) $config['connector'] !== ''
            && $filled('path')
            && $enumOk('operation', ['get', 'put', 'post', 'patch', 'delete'])
            && $enumOk('body', ['empty', 'json', 'text', 'xml'])
            && $boolOk('body_dwl');

        // The entity READ rules — shared by the generic reader's entity variant and the typed
        // entity_read step (identical config, minus the reader discriminator).
        $entityReadOk = static fn (): bool =>
            $filled('system') && $filled('entity')
            && $enumOk('mode', ['all', 'by_id', 'by_attribute'])
            && (($config['mode'] ?? 'all') !== 'by_id' || $filled('record_id'))
            // "By attribute" carries the attribute to compare and the value to match.
            && (($config['mode'] ?? 'all') !== 'by_attribute'
                || ($filled('attribute') && $filled('attr_value')))
            // "All" extras, each optional: order_by attribute (+ direction) and limit.
            && (!isset($config['order_by']) || \is_string($config['order_by']))
            && $enumOk('order_dir', ['asc', 'desc'])
            && $enumOk('limit', [1, 10, 100, 1000]);

        // The entity WRITE rules — shared by the generic writer's entity variant and entity_write.
        $entityWriteOk = static fn (): bool =>
            $filled('system') && $filled('entity') && $filled('content') && $boolOk('content_dwl');

        // File Operations share a connector + DWL-capable path core.
        $fileOpOk = static fn (): bool =>
            is_scalar($config['connector'] ?? null) && (string) $config['connector'] !== ''
            && $filled('path') && $boolOk('path_dwl');

        return match ($step['type']) {
            // Schedule: interval (value + unit) or cron (expression; also the LEGACY shape, which
            // carries no mode). Expression syntax is checked separately with Cron\CronExpression.
            'cron' => $boolOk('enabled') && ($onlyEnabled() || match ($config['mode'] ?? 'cron') {
                'interval' => \is_int($config['value'] ?? null) && $config['value'] >= 1
                    && \in_array($config['unit'] ?? null, ['minute', 'hour', 'day', 'week', 'month', 'year'], true),
                'cron' => $filled('expression'),
                default => false,
            }),
            'entity_change' => $boolOk('enabled') && ($onlyEnabled() || ($filled('system') && $filled('entity'))),
            // The remaining triggers carry only the enabled flag (bool when set; a missing flag
            // reads as DISABLED at evaluation, not as invalid — old flows stay green).
            'subflow' => $boolOk('enabled'),
            // Endpoint trigger: method + path (+ the public switch). Path segments are literals
            // or {param} placeholders; a param name must be a valid identifier and not shadow a
            // reserved context variable (the params are seeded into the context by name).
            'endpoint' => $boolOk('enabled') && ($onlyEnabled() || (
                $enumOk('method', ['GET', 'POST', 'PUT', 'QUERY', 'PATCH', 'DELETE'])
                && $boolOk('public')
                && self::endpointPathOk((string) ($config['path'] ?? ''))
                && (!isset($config['response']) || \is_string($config['response']))
            )),
            // Choice: the success expression is the whole config (syntax checked via
            // stepDwlSnippets); the branch links live in the design, not here.
            'choice' => $filled('expression'),
            'dwl_transform' => $filled('code') && $destinationOk(),
            'entity_read' => $destinationOk() && $entityReadOk(),
            'entity_write' => $destinationOk() && $entityWriteOk(),
            // "HTTP Request": a rest_api connector call — connector/operation/path/body shape, the
            // response stored under the destination.
            'invoke' => $destinationOk() && $connectorOk(),
            'file_read', 'file_list', 'file_delete' => $destinationOk() && $fileOpOk(),
            'file_write' => $destinationOk() && $fileOpOk() && $filled('content') && $boolOk('content_dwl'),
            'file_rename' => $destinationOk() && $fileOpOk() && $filled('new_name') && $boolOk('new_name_dwl'),
            // "Call Subflow": which subflow to invoke (existence/type/enabled are RUNTIME checks —
            // this validator stays DB-free).
            'sub_flow' => is_scalar($config['subflow'] ?? null) && (string) $config['subflow'] !== '',
            // "Foreach Loop": the subflow + the array variable + the per-element variable name
            // (which must not shadow the injected `index` or a reserved context name).
            'foreach' => is_scalar($config['subflow'] ?? null) && (string) $config['subflow'] !== ''
                && $filled('array')
                && $filled('item')
                && !\in_array(strtolower(trim((string) $config['item'])), ['index', 'flowuuid', 'flow-uuid', 'choiceresults'], true),
            // "Invoke PHP": the service class + method (existence/callability are RUNTIME
            // checks — this validator stays container-free) and the optional parameters DWL.
            'invoke_php' => $destinationOk() && $filled('class') && $filled('method')
                && (!isset($config['params']) || \is_string($config['params'])),
            // "Logger": the message text (DWL-capable via its toggle).
            'logger' => $filled('message') && $boolOk('message_dwl'),
            // "Event": the value emitted as a log-message flow event (DWL-capable via toggle).
            'event' => $filled('value') && $boolOk('value_dwl'),
            // "MS Teams": the context variable holding the message (stringified at run time)
            // plus the Power Automate webhook — a well-formed HTTPS URL (plain text, not DWL;
            // the domain is deliberately NOT restricted to powerplatform.com).
            'ms_teams' => $filled('message')
                && \is_string($config['webhook'] ?? null)
                && filter_var(trim($config['webhook']), FILTER_VALIDATE_URL) !== false
                && str_starts_with(strtolower(trim($config['webhook'])), 'https://'),
            // "SQL Query": a database connector, the (DWL-capable) SQL, optional bindings.
            'sql_query' => $destinationOk()
                && is_scalar($config['connector'] ?? null) && (string) $config['connector'] !== ''
                && $filled('sql') && $boolOk('sql_dwl')
                && (!isset($config['binding']) || \is_string($config['binding']))
                && $boolOk('binding_dwl'),
            default => true,
        };
    }

    /**
     * Endpoint trigger path: non-empty; each segment a literal ([A-Za-z0-9_.-]+) or a "{param}"
     * placeholder whose name is a valid identifier and not a reserved context variable.
     */
    private static function endpointPathOk(string $path): bool
    {
        $path = trim($path, " \t/");
        if ($path === '') {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $m) === 1) {
                if (\in_array(strtolower($m[1]), ['body', 'headers', 'queryparams', 'oauthapplication', 'flowuuid', 'flow-uuid', 'choiceresults', 'payload'], true)) {
                    return false;
                }
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.-]+$/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans('aaxis.ontology.flow_manager.' . $key, $params, 'messages');
    }
}
