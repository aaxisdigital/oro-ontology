<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_10;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\ParametrizedMigrationQuery;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use Psr\Log\LoggerInterface;

/**
 * Converts the LEGACY generic reader/writer steps into their typed successors, in both the logical
 * `steps` and the canvas `design.steps` of every flow:
 *
 *  - reader + `reader: entity`     → entity_read   (the discriminator key is dropped)
 *  - reader + `reader: connector`  → invoke (rest_api connector) or file_read (file-based)
 *  - writer + `writer: entity`     → entity_write
 *  - writer + `writer: connector`  → invoke (rest config, i.e. carries `operation`) or file_write
 *  - unconfigured reader/writer    → entity_read / entity_write (tile stays, config stays null)
 *
 * The connector's TYPE decides rest vs file; a connector that no longer exists falls back to the
 * config SHAPE (`operation` present = rest). File-based conversions gain `path_dwl: false` (the
 * legacy path was always a literal). The reader/writer types are REMOVED from STEP_TYPES in the
 * same version — without this conversion a flow carrying them would stop validating and its
 * design would open as "corrupted".
 */
class ConvertLegacyReaderWriterSteps implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $queries->addPostQuery(new class extends ParametrizedMigrationQuery {
            #[\Override]
            public function getDescription(): string
            {
                return 'Convert legacy reader/writer flow steps to entity_read/entity_write/invoke/file_read/file_write';
            }

            #[\Override]
            public function execute(LoggerInterface $logger): void
            {
                $connectorTypes = [];
                foreach ($this->connection->fetchAllAssociative('SELECT id, type FROM aaxis_ontology_connector') as $row) {
                    $connectorTypes[(int) $row['id']] = (string) $row['type'];
                }

                $flows = $this->connection->fetchAllAssociative(
                    "SELECT id, steps, design FROM aaxis_ontology_flow
                     WHERE steps::text LIKE '%\"reader\"%' OR steps::text LIKE '%\"writer\"%'
                        OR design::text LIKE '%\"reader\"%' OR design::text LIKE '%\"writer\"%'"
                );
                foreach ($flows as $flow) {
                    $steps = json_decode((string) $flow['steps'], true);
                    $design = json_decode((string) $flow['design'], true);
                    $changed = false;

                    if (\is_array($steps)) {
                        $steps = $this->convertList($steps, $connectorTypes, $changed);
                    }
                    if (\is_array($design) && \is_array($design['steps'] ?? null)) {
                        $design['steps'] = $this->convertList($design['steps'], $connectorTypes, $changed);
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
                    $logger->info(sprintf('Converted legacy reader/writer steps of flow #%d.', (int) $flow['id']));
                }
            }

            /**
             * @param array<int, mixed>  $steps
             * @param array<int, string> $connectorTypes
             *
             * @return array<int, mixed>
             */
            private function convertList(array $steps, array $connectorTypes, bool &$changed): array
            {
                foreach ($steps as $i => $step) {
                    if (!\is_array($step) || !\in_array($step['type'] ?? null, ['reader', 'writer'], true)) {
                        continue;
                    }
                    $isReader = $step['type'] === 'reader';
                    $config = \is_array($step['config'] ?? null) ? $step['config'] : null;
                    $variant = $config[$isReader ? 'reader' : 'writer'] ?? null;

                    if ($config === null || $variant === 'entity' || ($variant !== 'connector' && $variant !== 'entity')) {
                        $step['type'] = $isReader ? 'entity_read' : 'entity_write';
                    } else {
                        $connectorType = $connectorTypes[(int) ($config['connector'] ?? 0)] ?? null;
                        $isRest = $connectorType === 'rest_api'
                            || ($connectorType === null && isset($config['operation']));
                        if ($isRest) {
                            $step['type'] = 'invoke';
                        } else {
                            $step['type'] = $isReader ? 'file_read' : 'file_write';
                            $config['path_dwl'] = false; // legacy connector paths were literals
                            unset($config['operation'], $config['body'], $config['body_content'], $config['body_dwl']);
                        }
                    }
                    if ($config !== null) {
                        unset($config['reader'], $config['writer']);
                        $step['config'] = $config;
                    }
                    $steps[$i] = $step;
                    $changed = true;
                }

                return $steps;
            }
        });
    }
}
