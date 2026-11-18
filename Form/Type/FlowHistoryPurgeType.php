<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * System Configuration → Settings: the flow-execution history CLEANUP — the last purge's outcome
 * (daily job or button alike) and the "Clean up now" button
 * (flow-history-maintenance-component → OntologyConfigController::flowHistoryPurgeAction), then
 * the last COMPACTION (sizes before/after) and the "Compact tables" button
 * (→ OntologyConfigController::flowHistoryCompactAction).
 * Declared `ui_only`: renders no input and persists nothing; the value it shows is written only
 * by {@see FlowRunMaintenance::purge()}.
 */
class FlowHistoryPurgeType extends AbstractType
{
    public function __construct(private readonly FlowRunMaintenance $maintenance)
    {
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['last_purge'] = $this->maintenance->lastPurge();
        $view->vars['last_compact'] = self::compactView($this->maintenance->lastCompact());
        $view->vars['history_days'] = $this->maintenance->historyDays();
    }

    /**
     * The last compaction with each table's sizes formatted for display ('—' when not measured).
     *
     * @param array{at: string, tables: list<array<string, mixed>>}|null $compact
     *
     * @return array{at: string, tables: list<array{table: string, before: string, after: string, error: string|null}>}|null
     */
    private static function compactView(?array $compact): ?array
    {
        if ($compact === null) {
            return null;
        }
        $size = static fn (mixed $bytes): string => is_numeric($bytes) ? DbUsageType::formatBytes((int) $bytes) : '—';

        return [
            'at' => (string) $compact['at'],
            'tables' => array_map(static fn (array $t): array => [
                'table' => (string) ($t['table'] ?? ''),
                'before' => $size($t['before'] ?? null),
                'after' => $size($t['after'] ?? null),
                'error' => isset($t['error']) ? (string) $t['error'] : null,
            ], array_values(array_filter($compact['tables'], 'is_array'))),
        ];
    }

    #[\Override]
    public function getParent(): string
    {
        return TextType::class;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'aaxis_ontology_flow_history_purge';
    }
}
