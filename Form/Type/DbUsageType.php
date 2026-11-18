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
 * tables (pg_total_relation_size: table + indexes + toast) and their live row count — information
 * to support the retention-days settings above it. The count is PostgreSQL's statistics estimate
 * (`pg_stat_user_tables.n_live_tup`, no table scan), so it shows a cleanup at once while the size
 * stays until the table is compacted (deleted rows leave reusable space INSIDE the files). Declared `ui_only` in system_configuration.yml, renders no
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
        'aaxis_ontology_flow_runs',
    ];

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['db_usage'] = array_map(fn (string $table): array => $this->usage($table), self::TABLES);
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

    /**
     * One table's line: its total size and estimated live rows, each '—' when it cannot be told
     * (unknown/renamed table, no statistics yet, or a database error — the config page must
     * render either way).
     *
     * @return array{table: string, size: string, rows: string}
     */
    private function usage(string $table): array
    {
        try {
            // to_regclass() is NULL for a missing table and the size function passes it through,
            // so an unknown/renamed table comes back null (and finds no statistics row).
            $row = $this->doctrine->getConnection()->fetchAssociative(
                'SELECT pg_total_relation_size(t.oid) AS bytes, s.n_live_tup AS live'
                . ' FROM (SELECT to_regclass(?) AS oid) t LEFT JOIN pg_stat_user_tables s ON s.relid = t.oid',
                [$table]
            );
        } catch (\Throwable) {
            $row = false;
        }
        $bytes = \is_array($row) ? ($row['bytes'] ?? null) : null;
        $live = \is_array($row) ? ($row['live'] ?? null) : null;

        return [
            'table' => $table,
            'size' => $bytes === null ? '—' : self::formatBytes((int) $bytes),
            'rows' => $live === null ? '—' : number_format((int) $live),
        ];
    }

    /** "12.3 kb" / "4.6 mb" / "1.25 gb" — shared with the history cleanup's compaction line. */
    public static function formatBytes(int $bytes): string
    {
        $kb = $bytes / 1024;

        return match (true) {
            $kb < 1024 => number_format($kb, 1) . ' kb',
            $kb < 1024 * 1024 => number_format($kb / 1024, 1) . ' mb',
            default => number_format($kb / (1024 * 1024), 2) . ' gb',
        };
    }
}
