<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_15;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\OntologyDataFunctions;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Re-syncs the Ontology PostgreSQL functions with {@see OntologyDataFunctions} (CREATE OR REPLACE,
 * safe to replay).
 *
 * Brings in the SET-BASED aaxis_ontology_data_upsert: the per-record loop rebuilt its response
 * object on every record, so a batch cost O(N²) — a large file written by a flow pinned a database
 * CPU for hours instead of seconds. The response no longer echoes each record's payload/diff
 * (only {uuid, version} per created/updated record, null when unchanged), and the diff/merge
 * helpers now skip the keys a change cannot affect. Stored data and history are written exactly
 * as before.
 */
class ResyncDataFunctions implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        foreach (OntologyDataFunctions::all() as $functionSql) {
            $queries->addPostQuery($functionSql);
        }
    }
}
