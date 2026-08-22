<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the configuration tree for the bundle ("aaxis_ontology"), including its
 * System Configuration settings.
 */
class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('aaxis_ontology');
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append($rootNode, [
            'enabled' => ['type' => 'boolean', 'value' => true],

            // Retention / bucket archiving (CONFIG ONLY for now — the purge/archive logic
            // consuming these is still to come). bucket_base_path is the key prefix inside the
            // bucket under which archives land.
            'bucket_base_path' => ['type' => 'string', 'value' => 'aaxis-ontology'],
            'flow_execution_history_days' => ['type' => 'integer', 'value' => 30],
            'flow_version_history_days' => ['type' => 'integer', 'value' => 365],
            'entity_version_history_days' => ['type' => 'integer', 'value' => 365],
            // Bucket (S3-compatible object storage). The two keys are stored ENCRYPTED by
            // OroEncodedPlaceholderPasswordType — consumers must decrypt them via
            // SymmetricCrypterInterface (service oro_security.encoder.default, the same one that
            // form type encrypts with), like Oro's own integration settings.
            // use_bucket_for_entity_data: store entity data record content in the bucket.
            'use_bucket_for_entity_data' => ['type' => 'boolean', 'value' => false],
            // Full URL like the DevTools Bucket Viewer's (scheme://host[:port]; port defaults
            // from the scheme) — NOT the connector-style separate server/port pair.
            'bucket_endpoint_url' => ['type' => 'string', 'value' => ''],
            'bucket_access_key' => ['type' => 'string', 'value' => ''],
            'bucket_secret_key' => ['type' => 'string', 'value' => ''],
            'bucket_name' => ['type' => 'string', 'value' => ''],

            // Flow editor: spacing (px) of the canvas dot-matrix background.
            'flow_editor_grid_spacing' => ['type' => 'integer', 'value' => 10],
            // Flow editor: step tile size, as a multiple of the dot spacing (tile = factor × spacing px).
            'flow_editor_step_size_factor' => ['type' => 'integer', 'value' => 8],
            // Debugger: seconds before a FINISHED debug pane closes itself (0 disables it).
            'flow_debug_autoclose_seconds' => ['type' => 'integer', 'value' => 15],
            // Debugger: minutes of INACTIVITY before a stepwise session is terminated (the run
            // gets a flow-exception "debug-timeout" event; 0 disables the timeout).
            'flow_debug_timeout_minutes' => ['type' => 'integer', 'value' => 10],
            // "Invoke PHP" flow step: namespace prefixes whose container services may be called
            // (one per line). The compile-time locator holds EVERY service; this runtime filter
            // decides what flows may actually reach.
            'invoke_php_namespaces' => ['type' => 'string', 'value' => 'Aaxis\\'],

            // Data HTTP API (read / upsert / query). Disabled by default — opt-in per environment.
            'api_read_enabled' => ['type' => 'boolean', 'value' => false],
            'api_upsert_enabled' => ['type' => 'boolean', 'value' => false],
            'api_query_enabled' => ['type' => 'boolean', 'value' => false],
            // When enabled, an upsert for an unknown system/entity creates them on the fly.
            'api_auto_create' => ['type' => 'boolean', 'value' => false],
            // unique_attribute assigned to entities auto-created by the API.
            'api_auto_create_unique_attribute' => ['type' => 'string', 'value' => 'id'],
            // Hard upper bound on the query endpoint's page_size.
            'api_query_max_page_size' => ['type' => 'integer', 'value' => 200],

            // Flow API: kill switches for the Endpoint-trigger API, split by trigger kind.
            'flow_api_secure_enabled' => ['type' => 'boolean', 'value' => true],
            'flow_api_public_enabled' => ['type' => 'boolean', 'value' => true],

            // Flow Elements: per-element toolbox visibility (hidden elements stay valid in
            // stored flows — the editor still needs their metadata, see editor.html.twig).
            'flow_element_cron' => ['type' => 'boolean', 'value' => true],
            'flow_element_endpoint' => ['type' => 'boolean', 'value' => true],
            'flow_element_entity_change' => ['type' => 'boolean', 'value' => false],
            'flow_element_subflow' => ['type' => 'boolean', 'value' => true],
            'flow_element_choice' => ['type' => 'boolean', 'value' => true],
            'flow_element_sub_flow' => ['type' => 'boolean', 'value' => true],
            'flow_element_foreach' => ['type' => 'boolean', 'value' => true],
            'flow_element_dwl_transform' => ['type' => 'boolean', 'value' => true],
            'flow_element_entity_read' => ['type' => 'boolean', 'value' => true],
            'flow_element_entity_write' => ['type' => 'boolean', 'value' => true],
            'flow_element_invoke' => ['type' => 'boolean', 'value' => true],
            'flow_element_sql_query' => ['type' => 'boolean', 'value' => true],
            'flow_element_invoke_php' => ['type' => 'boolean', 'value' => true],
            'flow_element_file_read' => ['type' => 'boolean', 'value' => true],
            'flow_element_file_write' => ['type' => 'boolean', 'value' => true],
            'flow_element_file_list' => ['type' => 'boolean', 'value' => true],
            'flow_element_file_delete' => ['type' => 'boolean', 'value' => true],
            'flow_element_file_rename' => ['type' => 'boolean', 'value' => true],
            'flow_element_logger' => ['type' => 'boolean', 'value' => true],
            'flow_element_event' => ['type' => 'boolean', 'value' => true],
            'flow_element_email' => ['type' => 'boolean', 'value' => false],
            'flow_element_ms_teams' => ['type' => 'boolean', 'value' => true],
        ]);

        return $treeBuilder;
    }
}
