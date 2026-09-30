<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\DependencyInjection;

use Aaxis\Bundle\OntologyBundle\DependencyInjection\AaxisOntologyExtension;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Aaxis\Bundle\OntologyBundle\Controller\OntologyConfigController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AaxisOntologyExtensionTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        (new AaxisOntologyExtension())->load([], $this->container);
    }

    public function testAliasMatchesTheSettingKeyPrefix(): void
    {
        self::assertSame('aaxis_ontology', (new AaxisOntologyExtension())->getAlias());
    }

    public function testExposesTheBundleDirectoryForTheTypeScriptBuild(): void
    {
        $dir = $this->container->getParameter('aaxis_ontology.bundle_dir');

        self::assertIsString($dir);
        self::assertFileExists($dir . '/Resources/js-src/tsconfig.json');
    }

    public function testTheSettingsDefaultsArePrependedForOroSystemConfiguration(): void
    {
        $prepended = $this->container->getExtensionConfig('aaxis_ontology');

        self::assertNotEmpty($prepended);
        $settings = $prepended[0]['settings'];
        self::assertSame(['value' => true, 'scope' => 'app'], $settings['enabled']);
        self::assertSame(200, $settings['api_query_max_page_size']['value']);
        self::assertSame(false, $settings['use_bucket_for_entity_data']['value']);
    }

    /**
     * @dataProvider serviceProvider
     */
    public function testRegistersTheServicesAndControllers(string $id): void
    {
        self::assertTrue($this->container->hasDefinition($id), $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serviceProvider(): iterable
    {
        yield 'connector tester' => [ConnectorTester::class];
        yield 'file connector transfer' => [FileConnectorTransfer::class];
        yield 'data api manager' => [OntologyDataApiManager::class];
        yield 'config controller' => [OntologyConfigController::class];
        yield 'typescript compiler' => ['aaxis_ontology.typescript_compiler'];
    }

    public function testEveryRegisteredServiceClassExists(): void
    {
        foreach ($this->container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (str_contains((string) $class, '%')) {
                continue;
            }
            self::assertTrue(
                class_exists($class) || interface_exists($class),
                sprintf('service "%s" points at a class that does not exist: %s', $id, $class)
            );
        }
    }
}
