<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema\v1_10;

use Aaxis\Bundle\OntologyBundle\Migrations\Schema\OntologyDataFunctions;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Re-syncs the Ontology PostgreSQL functions with {@see OntologyDataFunctions} (CREATE OR REPLACE,
 * safe to replay).
 *
 * Brings in the VERSION-CONTINUITY fix in aaxis_ontology_data_upsert: a record whose unique id
 * still has history rows from a previous generation (the pre-v1_9 purge deleted data but kept
 * history) used to crash the archive INSERT on the (entity_id, unique_id, version) unique index —
 * inside the async consumer that surfaced as a logged error, a "no changes" event and a silently
 * unchanged record. Version numbering now always continues after the surviving history, so such
 * records heal on their next change instead of failing every update forever.
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
