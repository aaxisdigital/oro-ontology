<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\DependencyInjection;

use Aaxis\Bundle\OntologyBundle\DependencyInjection\AaxisOntologyExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Loads the bundle's real service YAML and checks every definition against the class it names. A
 * refactor that renames a class or changes a constructor without touching services.yml only blows up
 * when the application container compiles; this catches it in the unit suite instead.
 */
class ServiceWiringTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        (new AaxisOntologyExtension())->load([], $this->container);
    }

    public function testEveryServiceNamesAnExistingClass(): void
    {
        foreach ($this->plainDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?: $id;
            self::assertTrue(class_exists($class) || interface_exists($class), sprintf('%s -> %s', $id, $class));
        }
    }

    public function testEveryServiceArgumentListFitsItsConstructor(): void
    {
        foreach ($this->plainDefinitions() as $id => $definition) {
            $constructor = (new \ReflectionClass($definition->getClass() ?: $id))->getConstructor();
            $arguments = $definition->getArguments();
            if ($constructor === null) {
                self::assertSame([], $arguments, $id . ' passes arguments to a class without a constructor');
                continue;
            }

            $named = array_filter(array_keys($arguments), 'is_string');
            if ($named !== []) {
                $parameters = array_map(static fn (\ReflectionParameter $p): string => '$' . $p->getName(), $constructor->getParameters());
                foreach ($named as $name) {
                    self::assertContains($name, $parameters, sprintf('%s: unknown constructor argument %s', $id, $name));
                }
                continue;
            }

            self::assertLessThanOrEqual($constructor->getNumberOfParameters(), \count($arguments), $id . ' passes too many arguments');
            if (!$definition->isAutowired()) {
                self::assertGreaterThanOrEqual($constructor->getNumberOfRequiredParameters(), \count($arguments), $id . ' passes too few arguments');
            }
        }
    }

    /**
     * @return array<string, \Symfony\Component\DependencyInjection\Definition>
     */
    private function plainDefinitions(): array
    {
        return array_filter(
            $this->container->getDefinitions(),
            static fn ($definition, string $id): bool => $definition->getFactory() === null
                && !$definition->isAbstract()
                && !str_starts_with($id, '.'),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
