<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_10;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds aaxis_ontology_data_events.error — how the run FAILED, when it did (null on success).
 *
 * Until now a rejected batch and a "nothing changed" success were indistinguishable on the Events
 * page: both showed a finished event with no changed ids, with the failure visible only in the
 * consumer log. The page now derives the status from finished_at + error: running / success /
 * the error description. Existing rows stay null, i.e. read as success — historical failures
 * cannot be reconstructed from the data.
 */
class AddEventError implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_data_events');
        if ($table->hasColumn('error')) {
            return;
        }

        $table->addColumn('error', 'text', ['notnull' => false]);
    }
}
