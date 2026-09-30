<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\DependencyInjection\Compiler;

use Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler\InvokableServicesCleanupPass;
use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class InvokableServicesCleanupPassTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
    }

    /**
     * @return array<string, mixed>
     */
    private static function locatorMap(): array
    {
        return [
            'App\\Kept' => new ServiceClosureArgument(new Reference('kept.service')),
            'App\\ViaAlias' => new ServiceClosureArgument(new Reference('kept.alias')),
            'App\\Removed' => new ServiceClosureArgument(new Reference('removed.service')),
            'App\\PlainReference' => new Reference('removed.plain'),
            'App\\KeptPlain' => new Reference('kept.service'),
            'App\\Junk' => 'not a reference',
            'App\\EmptyClosure' => new ServiceClosureArgument(null),
        ];
    }

    private function registerSurvivors(): void
    {
        $this->container->register('kept.service', \stdClass::class);
        $this->container->setAlias('kept.alias', 'kept.service');
    }

    public function testItIsANoOpWithoutTheInvoker(): void
    {
        (new InvokableServicesCleanupPass())->process($this->container);

        self::assertFalse($this->container->hasDefinition(PhpMethodInvoker::class));
    }

    public function testEntriesOfRemovedServicesAreStrippedFromALocatorReferencedByTheInvoker(): void
    {
        $this->registerSurvivors();
        $locator = $this->container->register('.service_locator.abc', 'Symfony\\Component\\DependencyInjection\\ServiceLocator')->addArgument(self::locatorMap());
        $this->container->register(PhpMethodInvoker::class, PhpMethodInvoker::class)->addArgument(new Reference('.service_locator.abc'));

        (new InvokableServicesCleanupPass())->process($this->container);

        self::assertSame(['App\\Kept', 'App\\ViaAlias', 'App\\KeptPlain'], array_keys($locator->getArgument(0)));
    }

    public function testItAlsoHandlesALocatorInlinedIntoTheInvokersArgument(): void
    {
        $this->registerSurvivors();
        $locator = new Definition('Symfony\\Component\\DependencyInjection\\ServiceLocator', [self::locatorMap()]);
        $this->container->register(PhpMethodInvoker::class, PhpMethodInvoker::class)->addArgument($locator);

        (new InvokableServicesCleanupPass())->process($this->container);

        self::assertSame(['App\\Kept', 'App\\ViaAlias', 'App\\KeptPlain'], array_keys($locator->getArgument(0)));
    }

    /**
     * @dataProvider untouchableArgumentProvider
     */
    public function testItLeavesAnythingElseAlone(mixed $argument): void
    {
        $this->container->register(PhpMethodInvoker::class, PhpMethodInvoker::class)->addArgument($argument);

        (new InvokableServicesCleanupPass())->process($this->container);

        self::assertSame($argument, $this->container->getDefinition(PhpMethodInvoker::class)->getArgument(0));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function untouchableArgumentProvider(): array
    {
        return [
            'a reference to nothing' => [new Reference('.service_locator.missing')],
            'a plain value' => ['locator'],
            'null' => [null],
        ];
    }

    public function testALocatorWhoseArgumentIsNotAMapIsLeftAlone(): void
    {
        $locator = new Definition('Symfony\\Component\\DependencyInjection\\ServiceLocator', ['not a map']);
        $this->container->register(PhpMethodInvoker::class, PhpMethodInvoker::class)->addArgument($locator);

        (new InvokableServicesCleanupPass())->process($this->container);

        self::assertSame('not a map', $locator->getArgument(0));
    }
}
