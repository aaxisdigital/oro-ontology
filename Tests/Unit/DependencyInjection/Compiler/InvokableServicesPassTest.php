<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\DependencyInjection\Compiler;

use Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler\InvokableServicesPass;
use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class InvokableServicesPassTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('shared.class', \ArrayObject::class);
        $this->container->register(PhpMethodInvoker::class, PhpMethodInvoker::class)->addArgument(null);
    }

    /**
     * Runs the pass and returns the locator's map: class name => service id it points at.
     *
     * @return array<string, string>
     */
    private function collect(): array
    {
        (new InvokableServicesPass())->process($this->container);

        $argument = $this->container->getDefinition(PhpMethodInvoker::class)->getArgument(0);
        self::assertInstanceOf(Reference::class, $argument);
        $locator = $this->container->getDefinition((string) $argument);

        $map = [];
        foreach ($locator->getArgument(0) as $class => $closure) {
            self::assertInstanceOf(ServiceClosureArgument::class, $closure);
            $reference = $closure->getValues()[0];
            self::assertInstanceOf(Reference::class, $reference);
            // Services removed by later passes must drop out of the locator, not fail the reference check.
            self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
            $map[$class] = (string) $reference;
        }

        return $map;
    }

    public function testEveryServiceWithAnExistingClassIsCollectedByClassNameSorted(): void
    {
        $this->container->register('zeta.service', \stdClass::class);
        $this->container->register('alpha.service', \ArrayObject::class);

        $map = $this->collect();

        // (=== on arrays also compares the ORDER: the locator is keyed and sorted by class name)
        self::assertSame(
            [PhpMethodInvoker::class => PhpMethodInvoker::class, 'ArrayObject' => 'alpha.service', 'stdClass' => 'zeta.service'],
            $map
        );
    }

    public function testTheClassDefaultsToTheServiceIdAndParametersAreResolved(): void
    {
        $this->container->setDefinition(\SplStack::class, new Definition());
        $this->container->register('via.parameter', '%shared.class%');
        $this->container->register('leading.slash', '\\' . \SplQueue::class);

        $map = $this->collect();

        self::assertSame(\SplStack::class, $map['SplStack']);
        self::assertSame('via.parameter', $map['ArrayObject']);
        self::assertSame('leading.slash', $map['SplQueue']);
    }

    public function testTheFirstDefinitionOfAClassWins(): void
    {
        $this->container->register('first', \stdClass::class);
        $this->container->register('second', \stdClass::class);

        self::assertSame('first', $this->collect()['stdClass']);
    }

    /**
     * @dataProvider skippedDefinitionProvider
     */
    public function testUnusableDefinitionsAreSkipped(callable $configure): void
    {
        $configure($this->container);

        self::assertArrayNotHasKey('stdClass', $this->collect());
    }

    /**
     * @return array<string, array{callable}>
     */
    public static function skippedDefinitionProvider(): array
    {
        return [
            'abstract' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->setAbstract(true)],
            'synthetic' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->setSynthetic(true)],
            'hidden id' => [static fn (ContainerBuilder $c) => $c->register('.hidden.service', \stdClass::class)],
            'decorator' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->setDecoratedService('other')],
            'empty class parameter' => [static function (ContainerBuilder $c): void {
                $c->setParameter('nothing', '');
                $c->register('a', '%nothing%');
            }],
            'class that does not exist' => [static fn (ContainerBuilder $c) => $c->register('a', 'No\\Such\\ClassAnywhere')],
        ];
    }

    public function testAClassWhoseAutoloadingBlowsUpIsSkippedNotFatal(): void
    {
        $autoloader = static function (string $class): void {
            if ($class === 'Aaxis\\Fake\\Exploding') {
                throw new \Error('parent class removed');
            }
        };
        spl_autoload_register($autoloader);
        try {
            $this->container->register('boom', 'Aaxis\\Fake\\Exploding');
            $this->container->register('fine', \stdClass::class);

            $map = $this->collect();
        } finally {
            spl_autoload_unregister($autoloader);
        }

        self::assertArrayNotHasKey('Aaxis\\Fake\\Exploding', $map);
        self::assertSame('fine', $map['stdClass']);
    }

    /**
     * @dataProvider danglingProvider
     */
    public function testDefinitionsWithDanglingRequiredReferencesAreSkipped(callable $configure): void
    {
        $configure($this->container);
        $this->container->register('healthy', \ArrayObject::class);

        $map = $this->collect();

        self::assertArrayNotHasKey('stdClass', $map);
        self::assertSame('healthy', $map['ArrayObject']);
    }

    /**
     * @return array<string, array{callable}>
     */
    public static function danglingProvider(): array
    {
        $missing = static fn (): Reference => new Reference('does.not.exist');

        return [
            'in an argument' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->addArgument($missing())],
            'in a nested array' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->addArgument(['x' => ['y' => $missing()]])],
            'in a method call' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->addMethodCall('set', [$missing()])],
            'in a property' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->setProperty('p', $missing())],
            'in the factory' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->setFactory([$missing(), 'make'])],
            'in the configurator' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->setConfigurator([$missing(), 'configure'])],
            'inside an inline definition' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->addArgument((new Definition(\stdClass::class))->addArgument($missing()))],
            'an abstract argument' => [static fn (ContainerBuilder $c) => $c->register('a', \stdClass::class)->addArgument(new AbstractArgument('to be configured'))],
        ];
    }

    public function testReferencesThatResolveOrAreOptionalDoNotDisqualify(): void
    {
        $this->container->register('dependency', \ArrayObject::class);
        $this->container->register('a', \stdClass::class)
            ->addArgument(new Reference('dependency'))
            ->addArgument(new Reference('optional.missing', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))
            ->addArgument(new Reference('nullable.missing', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addArgument(new Reference('service_container'))
            ->addArgument(new Reference('an.alias'));
        $this->container->setAlias('an.alias', 'dependency');

        self::assertSame('a', $this->collect()['stdClass']);
    }

    public function testTheDanglingCheckStopsLookingAfterEightLevelsOfNesting(): void
    {
        $nested = new Reference('does.not.exist');
        for ($i = 0; $i < 9; ++$i) {
            $nested = [$nested];
        }
        $this->container->register('deep', \stdClass::class)->addArgument($nested);

        self::assertSame('deep', $this->collect()['stdClass']);
    }

    public function testScalarsInDefinitionsAreIgnored(): void
    {
        $this->container->register('a', \stdClass::class)->addArgument('text')->addArgument(5)->addArgument(null);

        self::assertSame('a', $this->collect()['stdClass']);
    }
}
