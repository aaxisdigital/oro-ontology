<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_4;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\ParametrizedSqlMigrationQuery;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds `aaxis_ontology_flow.custom` (boolean, default true).
 *
 * Existing rows become custom = true (the column default) — right for user-created flows. The two
 * built-in flows already seeded by {@see \Aaxis\Bundle\OntologyBundle\Migrations\Data\ORM\LoadOntologyFlows}
 * are downgraded to custom = false here, because that fixture is idempotent-by-name and will not
 * touch them again on existing databases (fresh installs get custom = false from the fixture itself).
 */
class AddFlowCustomFlag implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_ontology_flow');
        if (!$table->hasColumn('custom')) {
            $table->addColumn('custom', 'boolean', ['default' => true]);

            $queries->addPostQuery(new ParametrizedSqlMigrationQuery(
                'UPDATE aaxis_ontology_flow SET custom = false WHERE name IN (:manual, :restApi)',
                ['manual' => OntologyFlow::NAME_MANUAL, 'restApi' => OntologyFlow::NAME_REST_API]
            ));
        }
    }
}
