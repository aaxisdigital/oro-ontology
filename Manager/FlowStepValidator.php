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
    /**
     * The DWL-capable config fields, in reporting order: [step type ("file_*" = every File
     * Operations type), config key, the toggle that makes the field DWL (null = always DWL)].
     */
    private const array DWL_FIELDS = [
        ['dwl_transform', 'code', null],
        ['invoke', 'body_content', 'body_dwl'],
        // HTTP Request: the path is a single-line DWL expression while its toggle is on.
        ['invoke', 'path', 'path_dwl'],
        ['entity_write', 'content', 'content_dwl'],
        // Entity Read / Write: the system and entity NAMES may be computed at run time.
        ['entity_read', 'system', 'system_dwl'],
        ['entity_read', 'entity', 'entity_dwl'],
        ['entity_write', 'system', 'system_dwl'],
        ['entity_write', 'entity', 'entity_dwl'],
        ['file_write', 'content', 'content_dwl'],
        // File Operations: the path (all five) and the rename's new name are DWL-capable too.
        ['file_*', 'path', 'path_dwl'],
        ['file_rename', 'new_name', 'new_name_dwl'],
        // Read File: the reader options object is ALWAYS a DWL expression.
        ['file_read', 'reader_options', null],
        ['choice', 'expression', null],
        // Invoke PHP: the parameters object is ALWAYS a DWL expression.
        ['invoke_php', 'params', null],
        // Endpoint trigger: the optional response binding is ALWAYS a DWL expression.
        ['endpoint', 'response', null],
        // Event / Logger: DWL only while their toggle is on (plain text otherwise).
        ['event', 'value', 'value_dwl'],
        ['logger', 'message', 'message_dwl'],
        // SQL Query: the SQL text and the bindings are DWL-capable too.
        ['sql_query', 'sql', 'sql_dwl'],
        ['sql_query', 'binding', 'binding_dwl'],
        // Call Subflow / Foreach Loop: the subflow may be NAMED by an expression at run time.
        ['sub_flow', 'subflow', 'subflow_dwl'],
        ['foreach', 'subflow', 'subflow_dwl'],
    ];

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
        $steps = array_map($this->normalizeStep(...), $raw);

        return \in_array(null, $steps, true) ? null : $steps;
    }

    /**
     * @return array{type: string, name: string, x: int, y: int, config: array<string, mixed>|null}|null
     *         null when the step is malformed
     */
    private function normalizeStep(mixed $step): ?array
    {
        $name = \is_string($step['name'] ?? null) ? trim($step['name']) : '';
        $config = $step['config'] ?? null;
        if (!\is_array($step) || !\in_array($step['type'] ?? null, OntologyFlow::STEP_TYPES, true)
            || $name === '' || mb_strlen($name) > 64
            || !is_numeric($step['x'] ?? null) || !is_numeric($step['y'] ?? null)
            || ($config !== null && (!\is_array($config) || array_is_list($config)))
        ) {
            return null;
        }

        return [
            'type' => (string) $step['type'],
            'name' => $name,
            'x' => max(0, (int) $step['x']),
            'y' => max(0, (int) $step['y']),
            'config' => $config,
        ];
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
        return $this->problemReport($steps)['errors'];
    }

    /**
     * Per-step problems in one pass: `errors` = the first problem per step NAME (what
     * {@see stepErrors()} returns), `fields` = the config KEYS only the server can judge — a
     * DWL text that does not parse, an invalid cron expression — each with its message, per step
     * name. The editor paints those fields red in the step's settings popup (it checks missing
     * values itself); steps without such a problem are absent.
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array{errors: array<string, string>, fields: array<string, array<string, string>>}
     */
    public function problemReport(array $steps): array
    {
        $errors = [];
        $fields = [];
        foreach ($steps as $step) {
            $name = (string) $step['name'];
            $stepFields = [];
            $expression = $step['config']['expression'] ?? null;
            if ($step['type'] === 'cron' && $expression !== null
                && !CronExpression::isValidExpression((string) $expression)
            ) {
                $stepFields['expression'] = $errors[$name] ??= $this->trans('invalid_cron', ['{{ name }}' => $name]);
            }
            if (!$this->isStepConfigValid($step)) {
                $errors[$name] ??= $this->trans('invalid_step_config', ['{{ name }}' => $name]);
            }
            foreach ($this->stepDwlSnippets($step) as $key => $snippet) {
                $dwlError = $this->dwl->validate($snippet);
                if ($dwlError !== null) {
                    $stepFields[$key] = $this->trans('invalid_dwl', [
                        '{{ name }}' => $name,
                        '{{ error }}' => $dwlError,
                    ]);
                    $errors[$name] ??= $stepFields[$key];
                }
            }
            if ($stepFields !== []) {
                $fields[$name] = $stepFields;
            }
        }

        return ['errors' => $errors, 'fields' => $fields];
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

    /**
     * The step's DWL texts to parse-check, by config key.
     *
     * @return array<string, string>
     */
    private function stepDwlSnippets(array $step): array
    {
        $config = $step['config'];
        if (!\is_array($config)) {
            return [];
        }
        $snippets = [];
        foreach (self::DWL_FIELDS as [$type, $key, $toggle]) {
            if (self::typeMatches($step['type'], $type)
                && ($toggle === null || ($config[$toggle] ?? false) === true)
                && \is_string($config[$key] ?? null) && trim($config[$key]) !== ''
            ) {
                $snippets[$key] = $config[$key];
            }
        }

        return $snippets;
    }

    /** $pattern is a step type, or a "prefix*" matching every type that starts with the prefix. */
    private static function typeMatches(string $type, string $pattern): bool
    {
        return str_ends_with($pattern, '*')
            ? str_starts_with($type, substr($pattern, 0, -1))
            : $type === $pattern;
    }

    private function isStepConfigValid(array $step): bool
    {
        return FlowStepConfigRules::isValid($step['type'], \is_array($step['config']) ? $step['config'] : []);
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans('aaxis.ontology.flow_manager.' . $key, $params, 'messages');
    }
}
