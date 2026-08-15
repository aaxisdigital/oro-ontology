<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_8;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds aaxis_ontology_flow.trigger_type — the flow's trigger step type (cron | queue |
 * entity_change; null = no trigger), denormalized from the steps JSON so the scheduled-flows
 * runner selects its candidates with a plain column WHERE. Existing rows are backfilled from
 * their steps.
 */
class AddFlowTriggerType implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_flow');
        if ($table->hasColumn('trigger_type')) {
            return;
        }

        $table->addColumn('trigger_type', 'string', ['length' => 16, 'notnull' => false]);

        $queries->addPostQuery(
            "UPDATE aaxis_ontology_flow f SET trigger_type = sub.tt FROM ("
            . " SELECT id, ("
            . "  SELECT s->>'type' FROM jsonb_array_elements(steps::jsonb) AS s"
            . "  WHERE s->>'type' IN ('cron', 'queue', 'entity_change') LIMIT 1"
            . " ) AS tt FROM aaxis_ontology_flow WHERE steps IS NOT NULL"
            . ") sub WHERE f.id = sub.id AND sub.tt IS NOT NULL"
        );
    }
}
