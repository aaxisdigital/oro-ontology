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
     * Every problem with an already-normalized step list, as translated messages (empty = valid).
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array<int, string>
     */
    public function validate(array $steps): array
    {
        $errors = [];

        $stepNames = array_map(static fn (array $s) => mb_strtolower((string) $s['name']), $steps);
        if (\count($stepNames) !== \count(array_unique($stepNames))) {
            $errors[] = $this->trans('step_names_unique');
        }

        // A step's config is optional (unconfigured steps may be saved mid-design), but a PRESENT
        // config must be complete and valid for its type.
        foreach ($steps as $step) {
            $expression = $step['config']['expression'] ?? null;
            if ($step['type'] === 'cron' && $expression !== null
                && !CronExpression::isValidExpression((string) $expression)
            ) {
                $errors[] = $this->trans('invalid_cron', ['{{ name }}' => $step['name']]);
            }
            if (!$this->isStepConfigValid($step)) {
                $errors[] = $this->trans('invalid_step_config', ['{{ name }}' => $step['name']]);
            }
            foreach ($this->stepDwlSnippets($step) as $snippet) {
                $dwlError = $this->dwl->validate($snippet);
                if ($dwlError !== null) {
                    $errors[] = $this->trans('invalid_dwl', [
                        '{{ name }}' => $step['name'],
                        '{{ error }}' => $dwlError,
                    ]);
                }
            }
        }

        return $errors;
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
        if (\in_array($step['type'], ['reader', 'writer'], true) && ($config['body_dwl'] ?? false) === true
            && \is_string($config['body_content'] ?? null) && trim($config['body_content']) !== ''
        ) {
            $snippets[] = $config['body_content'];
        }
        if ($step['type'] === 'writer' && ($config['content_dwl'] ?? false) === true
            && \is_string($config['content'] ?? null) && trim($config['content']) !== ''
        ) {
            $snippets[] = $config['content'];
        }
        if ($step['type'] === 'choice' && \is_string($config['expression'] ?? null) && trim($config['expression']) !== '') {
            $snippets[] = $config['expression'];
        }

        return $snippets;
    }

    private function isStepConfigValid(array $step): bool
    {
        $config = $step['config'];
        if ($config === null) {
            return true;
        }
        $filled = static fn (string $key): bool => \is_string($config[$key] ?? null) && trim($config[$key]) !== '';

        // Enum keys are lenient when ABSENT (configs saved by older editors) but strict when set.
        $enumOk = static fn (string $key, array $allowed): bool =>
            !isset($config[$key]) || \in_array($config[$key], $allowed, true);

        // The DWL on/off toggles (body_dwl / content_dwl) are lenient when absent, bool when set.
        $boolOk = static fn (string $key): bool => !isset($config[$key]) || \is_bool($config[$key]);

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

        return match ($step['type']) {
            // Schedule: interval (value + unit) or cron (expression; also the LEGACY shape, which
            // carries no mode). Expression syntax is checked separately with Cron\CronExpression.
            'cron' => match ($config['mode'] ?? 'cron') {
                'interval' => \is_int($config['value'] ?? null) && $config['value'] >= 1
                    && \in_array($config['unit'] ?? null, ['minute', 'hour', 'day', 'week', 'month', 'year'], true),
                'cron' => $filled('expression'),
                default => false,
            },
            'entity_change' => $filled('system') && $filled('entity'),
            // Choice: the success expression is the whole config (syntax checked via
            // stepDwlSnippets); the branch links live in the design, not here.
            'choice' => $filled('expression'),
            'dwl_transform' => $filled('code') && $destinationOk(),
            'reader' => $destinationOk()
                && match ($config['reader'] ?? null) {
                    'entity' => $filled('system') && $filled('entity')
                        && $enumOk('mode', ['all', 'by_id', 'by_attribute'])
                        && (($config['mode'] ?? 'all') !== 'by_id' || $filled('record_id'))
                        // "By attribute" carries the attribute to compare and the value to match.
                        && (($config['mode'] ?? 'all') !== 'by_attribute'
                            || ($filled('attribute') && $filled('attr_value')))
                        // "All" extras, each optional: order_by attribute (+ direction) and limit.
                        && (!isset($config['order_by']) || \is_string($config['order_by']))
                        && $enumOk('order_dir', ['asc', 'desc'])
                        && $enumOk('limit', [1, 10, 100, 1000]),
                    'connector' => $connectorOk(),
                    default => false,
                },
            'writer' => $destinationOk()
                && match ($config['writer'] ?? null) {
                    'entity' => $filled('system') && $filled('entity') && $filled('content') && $boolOk('content_dwl'),
                    // A connector writer carries what to write: rest_api ones put it in the request
                    // body (operation/body/body_content), file/sftp/bucket ones in `content`. The
                    // connector's TYPE lives in the database and this validator stays DB-free, so
                    // the shape decides: no `operation` means it is not a rest config, and then
                    // `content` is what the step writes and must be there.
                    'connector' => $connectorOk() && $boolOk('content_dwl')
                        && (isset($config['operation']) || $filled('content')),
                    default => false,
                },
            default => true,
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans('aaxis.ontology.flow_manager.' . $key, $params, 'messages');
    }
}
