<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * aaxis_ontology_dwl_script — the Data Playground's saved scripts
 * ({@see \Aaxis\Bundle\OntologyBundle\Entity\OntologyDwlScript}): owner, name (unique per owner),
 * the DWL source and the playground metadata (public flag, the default INPUTS — jsonb
 * `[{entityId, name}]` — and the default max rows). Shared by the installer and the v1_16
 * migration so the two cannot drift; v1_17/v1_18 only convert the first, never-released shape
 * (a single `entity_id` input and a `render` column).
 */
final class DwlScriptTable
{
    public const string NAME = 'aaxis_ontology_dwl_script';

    public const string INPUTS_DEFINITION = "JSONB DEFAULT '[]' NOT NULL";

    public static function create(Schema $schema): void
    {
        $table = $schema->createTable(self::NAME);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('owner_id', 'integer');
        $table->addColumn('name', 'string', ['length' => 128]);
        $table->addColumn('script', 'text');
        $table->addColumn('is_public', 'boolean', ['default' => false]);
        $table->addColumn('inputs', 'json', ['columnDefinition' => self::INPUTS_DEFINITION]);
        $table->addColumn('row_limit', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('updated_at', 'datetime');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['owner_id', 'name'], 'aaxis_ontology_dwl_script_owner_name_uidx');
        // The list reads "mine OR public".
        $table->addIndex(['is_public'], 'aaxis_ontology_dwl_script_public_idx');
        // A user's scripts go with the user.
        $table->addForeignKeyConstraint($schema->getTable('oro_user'), ['owner_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => null]);
    }
}
