<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_10;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\ParametrizedMigrationQuery;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use Psr\Log\LoggerInterface;

/**
 * The flow's enabled state moved from the top-level column to the TRIGGER step's `enabled` config
 * flag (the column is now derived from it on every save). Seeds the flag into every existing
 * trigger step that lacks it — from the flow's current column value — in both `steps` and
 * `design.steps`, so no flow changes its effective state during the transition (a trigger without
 * the flag reads as DISABLED).
 */
class SeedTriggerEnabledFlags implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $queries->addPostQuery(new class extends ParametrizedMigrationQuery {
            private const array TRIGGERS = ['cron', 'endpoint', 'entity_change', 'subflow'];

            #[\Override]
            public function getDescription(): string
            {
                return "Seed the trigger steps' enabled config flag from the flow's enabled column";
            }

            #[\Override]
            public function execute(LoggerInterface $logger): void
            {
                $flows = $this->connection->fetchAllAssociative(
                    'SELECT id, enabled, steps, design FROM aaxis_ontology_flow WHERE steps IS NOT NULL'
                );
                foreach ($flows as $flow) {
                    $enabled = (bool) $flow['enabled'];
                    $steps = json_decode((string) $flow['steps'], true);
                    $design = json_decode((string) $flow['design'], true);
                    $changed = false;

                    if (\is_array($steps)) {
                        $steps = $this->seed($steps, $enabled, $changed);
                    }
                    if (\is_array($design) && \is_array($design['steps'] ?? null)) {
                        $design['steps'] = $this->seed($design['steps'], $enabled, $changed);
                    }
                    if (!$changed) {
                        continue;
                    }

                    $this->connection->executeStatement(
                        'UPDATE aaxis_ontology_flow SET steps = :steps, design = :design WHERE id = :id',
                        [
                            'steps' => \is_array($steps) ? json_encode($steps) : $flow['steps'],
                            'design' => \is_array($design) ? json_encode($design) : $flow['design'],
                            'id' => $flow['id'],
                        ]
                    );
                    $logger->info(sprintf('Seeded the trigger enabled flag of flow #%d.', (int) $flow['id']));
                }
            }

            /**
             * @param array<int, mixed> $steps
             *
             * @return array<int, mixed>
             */
            private function seed(array $steps, bool $enabled, bool &$changed): array
            {
                foreach ($steps as $i => $step) {
                    if (!\is_array($step) || !\in_array($step['type'] ?? null, self::TRIGGERS, true)) {
                        continue;
                    }
                    $config = \is_array($step['config'] ?? null) ? $step['config'] : [];
                    if (\array_key_exists('enabled', $config)) {
                        continue;
                    }
                    $config['enabled'] = $enabled;
                    $step['config'] = $config;
                    $steps[$i] = $step;
                    $changed = true;
                }

                return $steps;
            }
        });
    }
}
