<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_7;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds the flow lifecycle timestamps to aaxis_ontology_flow:
 *  - last_executed (nullable): stamped whenever the flow runs (debug / Run Now, and the real
 *    triggers once they exist); null = never ran.
 *  - last_modified (NOT NULL): the creation date at first, bumped by every save that rewrites
 *    the flow. Existing rows are backfilled with the migration time — the column is added
 *    nullable, filled by a post query, then constrained (a NOT NULL column can't land directly
 *    on a populated table without carrying a permanent default).
 */
class AddFlowTimestamps implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_flow');
        if ($table->hasColumn('last_modified')) {
            return;
        }

        $table->addColumn('last_executed', 'datetime', ['notnull' => false]);
        $table->addColumn('last_modified', 'datetime', ['notnull' => false]);

        $queries->addPostQuery('UPDATE aaxis_ontology_flow SET last_modified = NOW() WHERE last_modified IS NULL');
        $queries->addPostQuery('ALTER TABLE aaxis_ontology_flow ALTER COLUMN last_modified SET NOT NULL');
    }
}
