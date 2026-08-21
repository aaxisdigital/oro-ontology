<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Read-only System Configuration widget listing the on-disk size of the bundle's data/history
 * tables (pg_total_relation_size: table + indexes + toast) — information to support the
 * retention-days settings above it. Declared `ui_only` in system_configuration.yml, renders no
 * input (block aaxis_ontology_db_usage_widget in Form/fields.html.twig) and persists nothing.
 */
class DbUsageType extends AbstractType
{
    /** Flow EXECUTION history lives in the flow events table (one row per execution event). */
    private const TABLES = [
        'aaxis_ontology_data',
        'aaxis_ontology_data_history',
        'aaxis_ontology_flow',
        'aaxis_ontology_flow_history',
        'aaxis_ontology_flow_events',
    ];

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['db_usage'] = array_map(
            fn (string $table): array => ['table' => $table, 'size' => $this->tableSize($table)],
            self::TABLES
        );
    }

    #[\Override]
    public function getParent(): string
    {
        return TextType::class;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'aaxis_ontology_db_usage';
    }

    private function tableSize(string $table): string
    {
        try {
            // to_regclass() is NULL for a missing table and the size function passes it through,
            // so an unknown/renamed table renders as "—" instead of erroring the config page.
            $bytes = $this->doctrine->getConnection()->fetchOne(
                'SELECT pg_total_relation_size(to_regclass(?))',
                [$table]
            );
        } catch (\Throwable) {
            return '—';
        }
        if ($bytes === null || $bytes === false) {
            return '—';
        }

        $kb = ((int) $bytes) / 1024;
        if ($kb < 1024) {
            return number_format($kb, 1) . ' kb';
        }
        if ($kb < 1024 * 1024) {
            return number_format($kb / 1024, 1) . ' mb';
        }

        return number_format($kb / (1024 * 1024), 2) . ' gb';
    }
}
