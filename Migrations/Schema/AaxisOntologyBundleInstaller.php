<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Creates the AaxisOntologyBundle database schema (systems, entities, connectors, attributes,
 * data + history, data events, flows and per-user grid preferences) together with the PostgreSQL
 * functions backing the async data upsert flow.
 *
 * This is a single, consolidated install reflecting the current state of the model.
 */
class AaxisOntologyBundleInstaller implements Installation
{
    private const string JSONB_NULL = 'JSONB DEFAULT NULL';
    private const string FK_SET_NULL = 'SET NULL';
    private const string FK_CASCADE = 'CASCADE';

    #[\Override]
    public function getMigrationVersion(): string
    {
        return 'v1_7';
    }

    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $this->createSystemTable($schema);
        $this->createEntityTable($schema);
        $this->createConnectorTable($schema);
        $this->createEntityAttributeTable($schema);
        $this->createDataTable($schema);
        $this->createDataHistoryTable($schema);
        $this->createDataEventsTable($schema);
        $this->createFlowTable($schema);

        $this->addForeignKeys($schema);

        // Data upsert PostgreSQL functions (validation, diff/merge helpers, upsert routine).
        foreach (OntologyDataFunctions::all() as $functionSql) {
            $queries->addPostQuery($functionSql);
        }
    }

    private function createSystemTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_system');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('name', 'string', ['length' => 64]);
        $table->addColumn('enabled', 'boolean', ['default' => true]);
        $table->addColumn('external', 'boolean', ['default' => true]);
        $table->addColumn('logo_id', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['logo_id'], 'aaxis_ontology_system_logo_uidx');
        $table->addUniqueIndex(['name'], 'aaxis_ontology_system_name_uidx');
    }

    private function createEntityTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_entity');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('system_id', 'integer', []);
        $table->addColumn('name', 'string', ['length' => 128]);
        $table->addColumn('unique_attribute', 'string', ['length' => 100, 'default' => '']);
        $table->addColumn('enabled', 'boolean', ['default' => true]);
        $table->setPrimaryKey(['id']);
        // Composite unique (system_id, name) also serves system_id-prefix lookups, so no separate
        // single-column index is needed.
        $table->addUniqueIndex(['system_id', 'name'], 'aaxis_ontology_entity_system_name_uidx');
    }

    private function createConnectorTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_connector');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('system_id', 'integer', []);
        $table->addColumn('name', 'string', ['length' => 128]);
        $table->addColumn('type', 'string', ['length' => 32]);
        $table->addColumn('config', 'json', ['notnull' => false, 'columnDefinition' => self::JSONB_NULL]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['system_id'], 'aaxis_ontology_connector_system_idx');
    }

    private function createEntityAttributeTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_entity_attribute');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('entity_id', 'integer', []);
        $table->addColumn('name', 'string', ['length' => 100]);
        $table->addColumn('datatype', 'string', ['length' => 32, 'default' => 'undefined']);
        $table->addColumn('required', 'boolean', ['default' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['entity_id'], 'aaxis_ontology_entity_attr_entity_idx');
    }

    private function createDataTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_data');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('entity_id', 'integer', []);
        $table->addColumn('unique_id', 'string', ['length' => 255]);
        $table->addColumn('uuid', 'string', ['length' => 36]);
        $table->addColumn('version', 'integer', []);
        $table->addColumn('payload', 'json', ['notnull' => false, 'columnDefinition' => self::JSONB_NULL]);
        $table->addColumn('updated_at', 'datetime', []);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['entity_id'], 'aaxis_ontology_data_entity_idx');
        $table->addIndex(['unique_id'], 'aaxis_ontology_data_unique_id_idx');
        $table->addIndex(['updated_at'], 'aaxis_ontology_data_updated_at_idx');
        $table->addUniqueIndex(['entity_id', 'unique_id'], 'aaxis_ontology_data_entity_uid_uidx');
    }

    private function createDataHistoryTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_data_history');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('entity_id', 'integer', []);
        $table->addColumn('unique_id', 'string', ['length' => 255]);
        $table->addColumn('uuid', 'string', ['length' => 36]);
        $table->addColumn('version', 'integer', []);
        $table->addColumn('payload', 'json', ['notnull' => false, 'columnDefinition' => self::JSONB_NULL]);
        $table->addColumn('updated_at', 'datetime', []);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['entity_id'], 'aaxis_ontology_data_hist_entity_idx');
        $table->addIndex(['unique_id'], 'aaxis_ontology_data_hist_unique_id_idx');
        $table->addIndex(['updated_at'], 'aaxis_ontology_data_hist_updated_at_idx');
        $table->addUniqueIndex(['entity_id', 'unique_id', 'version'], 'aaxis_ontology_data_hist_e_u_v_uidx');
    }

    private function createDataEventsTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_data_events');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('flow_id', 'integer', []);
        $table->addColumn('uuid', 'string', ['length' => 36]);
        $table->addColumn('entity_id', 'integer', ['notnull' => false]);
        $table->addColumn('unique_ids', 'simple_array', ['notnull' => false]);
        $table->addColumn('changed_ids', 'simple_array', ['notnull' => false]);
        $table->addColumn('started_at', 'datetime', ['notnull' => false]);
        $table->addColumn('finished_at', 'datetime', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['flow_id'], 'aaxis_ontology_data_events_flow_idx');
        $table->addIndex(['entity_id'], 'aaxis_ontology_data_events_entity_idx');
        $table->addIndex(['started_at'], 'aaxis_ontology_data_events_started_at_idx');
    }

    private function createFlowTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_ontology_flow');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('name', 'string', ['length' => 128]);
        $table->addColumn('enabled', 'boolean', ['default' => true]);
        $table->addColumn('type', 'string', ['length' => 16, 'default' => 'subflow']);
        $table->addColumn('steps', 'json', ['notnull' => false, 'columnDefinition' => self::JSONB_NULL]);
        $table->addColumn('design', 'json', ['notnull' => false, 'columnDefinition' => self::JSONB_NULL]);
        $table->addColumn('last_executed', 'datetime', ['notnull' => false]);
        $table->addColumn('last_modified', 'datetime', []);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], 'aaxis_ontology_flow_name_uidx');
    }

    private function addForeignKeys(Schema $schema): void
    {
        $schema->getTable('aaxis_ontology_system')->addForeignKeyConstraint(
            $schema->getTable('oro_attachment_file'),
            ['logo_id'],
            ['id'],
            ['onDelete' => self::FK_SET_NULL, 'onUpdate' => null]
        );
        $schema->getTable('aaxis_ontology_entity')->addForeignKeyConstraint(
            $schema->getTable('aaxis_ontology_system'),
            ['system_id'],
            ['id'],
            ['onDelete' => self::FK_CASCADE, 'onUpdate' => null]
        );
        $schema->getTable('aaxis_ontology_connector')->addForeignKeyConstraint(
            $schema->getTable('aaxis_ontology_system'),
            ['system_id'],
            ['id'],
            ['onDelete' => self::FK_CASCADE, 'onUpdate' => null]
        );
        $schema->getTable('aaxis_ontology_entity_attribute')->addForeignKeyConstraint(
            $schema->getTable('aaxis_ontology_entity'),
            ['entity_id'],
            ['id'],
            ['onDelete' => self::FK_CASCADE, 'onUpdate' => null]
        );
        $schema->getTable('aaxis_ontology_data')->addForeignKeyConstraint(
            $schema->getTable('aaxis_ontology_entity'),
            ['entity_id'],
            ['id'],
            ['onDelete' => self::FK_CASCADE, 'onUpdate' => null]
        );
        $schema->getTable('aaxis_ontology_data_history')->addForeignKeyConstraint(
            $schema->getTable('aaxis_ontology_entity'),
            ['entity_id'],
            ['id'],
            ['onDelete' => self::FK_CASCADE, 'onUpdate' => null]
        );
    }
}
