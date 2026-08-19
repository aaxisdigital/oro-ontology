<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler;

use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Runs at TYPE_AFTER_REMOVING, after every other bundle's passes: strips from the "Invoke PHP"
 * service locator (built by {@see InvokableServicesPass}) the entries whose services were
 * REMOVED later in compilation (feature-toggle passes etc. call removeDefinition regardless of
 * references) — the PhpDumper would otherwise fail on the dangling factory.
 */
class InvokableServicesCleanupPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PhpMethodInvoker::class)) {
            return;
        }
        // The locator may still be a Reference to its own definition — or already INLINED into
        // the invoker's argument as a Definition (InlineServiceDefinitionsPass ran earlier in
        // this phase). Handle both shapes.
        $argument = $container->getDefinition(PhpMethodInvoker::class)->getArgument(0);
        $locator = null;
        if ($argument instanceof Definition) {
            $locator = $argument;
        } elseif ($argument instanceof Reference && $container->hasDefinition((string) $argument)) {
            $locator = $container->getDefinition((string) $argument);
        }
        if ($locator === null) {
            return;
        }
        $map = $locator->getArgument(0);
        if (!\is_array($map)) {
            return;
        }
        foreach ($map as $class => $argument) {
            $target = $argument instanceof ServiceClosureArgument ? ($argument->getValues()[0] ?? null) : $argument;
            $id = $target instanceof Reference ? (string) $target : null;
            if ($id === null || (!$container->hasDefinition($id) && !$container->hasAlias($id))) {
                unset($map[$class]);
            }
        }
        $locator->replaceArgument(0, $map);
    }
}
