<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\DependencyInjection;

use Aaxis\Bundle\OntologyBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<string, array{value: mixed, scope: string}>
     */
    private function settings(array $configs = []): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs)['settings'];
    }

    public function testTheRootNodeIsTheBundleAlias(): void
    {
        self::assertSame('aaxis_ontology', (new Configuration())->getConfigTreeBuilder()->buildTree()->getName());
    }

    /**
     * @dataProvider defaultProvider
     */
    public function testSettingDefaults(string $key, mixed $expected): void
    {
        $settings = $this->settings();

        self::assertArrayHasKey($key, $settings);
        self::assertSame($expected, $settings[$key]['value'], $key);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function defaultProvider(): iterable
    {
        yield 'enabled' => ['enabled', true];
        yield 'bucket base path' => ['bucket_base_path', 'aaxis-ontology'];
        yield 'flow history days' => ['flow_execution_history_days', 30];
        yield 'entity version days' => ['entity_version_history_days', 365];
        yield 'bucket off for entity data' => ['use_bucket_for_entity_data', false];
        yield 'bucket off for flow events' => ['use_bucket_for_flow_events', false];
        yield 'bucket endpoint blank' => ['bucket_endpoint_url', ''];
        yield 'grid spacing' => ['flow_editor_grid_spacing', 10];
        yield 'debug timeout' => ['flow_debug_timeout_minutes', 10];
        yield 'invoke php namespaces' => ['invoke_php_namespaces', 'Aaxis\\'];
        yield 'api endpoints are opt-in' => ['api_read_enabled', false];
        yield 'api auto create off' => ['api_auto_create', false];
        yield 'api unique attribute' => ['api_auto_create_unique_attribute', 'id'];
        yield 'api page size cap' => ['api_query_max_page_size', 200];
        yield 'secure flow api on' => ['flow_api_secure_enabled', true];
        yield 'public flow api on' => ['flow_api_public_enabled', true];
        yield 'cron element visible' => ['flow_element_cron', true];
        yield 'entity change hidden' => ['flow_element_entity_change', false];
        yield 'email hidden' => ['flow_element_email', false];
        yield 'teams visible' => ['flow_element_ms_teams', true];
    }

    public function testEveryFlowStepTypeHasAToolboxVisibilitySetting(): void
    {
        $settings = $this->settings();

        foreach (\Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow::STEP_TYPES as $type) {
            self::assertArrayHasKey('flow_element_' . $type, $settings, $type);
            self::assertIsBool($settings['flow_element_' . $type]['value']);
        }
    }

    public function testAdministratorsOverridesWin(): void
    {
        $settings = $this->settings([['settings' => ['api_query_max_page_size' => ['value' => 50]]]]);

        self::assertSame(50, $settings['api_query_max_page_size']['value']);
        self::assertSame(true, $settings['enabled']['value']);
    }
}
