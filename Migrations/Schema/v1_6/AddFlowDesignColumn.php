<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_6;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds `aaxis_ontology_flow.design` (jsonb, nullable): the flow editor's versioned canvas
 * representation (step tiles + toolbox state). Existing flows keep NULL — the editor falls back
 * to rebuilding the canvas from the logical `steps` list.
 */
class AddFlowDesignColumn implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_flow');
        if (!$table->hasColumn('design')) {
            $table->addColumn('design', 'json', ['notnull' => false, 'columnDefinition' => 'JSONB DEFAULT NULL']);
        }
    }
}
