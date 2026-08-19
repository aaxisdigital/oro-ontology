<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler;

use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects EVERY container service with a resolvable, existing class into a service locator
 * keyed by CLASS NAME — the compile-time universe of the "Invoke PHP" flow step. What flows may
 * actually call is decided at RUNTIME by the `aaxis_ontology.invoke_php_namespaces` System
 * Configuration setting ({@see PhpMethodInvoker::invokableClasses()}), so admins can widen the
 * exposure (Edge\, a specific Oro bundle, …) without a container rebuild. Going through a
 * locator keeps PRIVATE services reachable without making them public.
 *
 * One class provided by several services keeps the FIRST definition encountered; hidden
 * (dot-prefixed) ids and abstract/synthetic definitions are skipped.
 */
class InvokableServicesPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $refs = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic() || str_starts_with((string) $id, '.')) {
                continue;
            }
            // DECORATORS are skipped: this pass runs before decoration is resolved, and holding a
            // reference to a decorator's own id leaves its ".inner" dangling at compile time.
            if ($definition->getDecoratedService() !== null) {
                continue;
            }
            $class = $definition->getClass() ?? (string) $id;
            $class = ltrim((string) $container->getParameterBag()->resolveValue($class), '\\');
            if ($class === '' || isset($refs[$class])) {
                continue;
            }
            // class_exists AUTOLOADS: some vendor classes fatally reference removed parents
            // (e.g. LiipImagine's templating helper) — those are skipped, not fatal here.
            try {
                if (!class_exists($class)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            // Some framework definitions are DESIGNED to be pruned as unused and carry required
            // references that never resolve (e.g. translator.logging keeps ".inner" when
            // translation logging is off). Referencing them from the locator would keep them
            // alive and fail the final reference check — skip anything with a dangling ref.
            if (self::hasDanglingRequiredReference($container, $definition)) {
                continue;
            }
            // IGNORE on invalid: other bundles' passes may still REMOVE definitions after this
            // one runs (feature toggles etc.) — their classes then drop out of the locator
            // instead of failing the final reference check.
            $refs[$class] = new Reference((string) $id, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        }
        ksort($refs);

        $container->getDefinition(PhpMethodInvoker::class)
            ->replaceArgument(0, ServiceLocatorTagPass::register($container, $refs));
    }

    /**
     * Whether a definition (arguments, calls, properties, factory, configurator — recursively)
     * holds a throw-on-invalid Reference to a service id that does not exist yet. References
     * created by LATER passes (a real decorator's ".inner") never reach this: decorators are
     * skipped before this check runs.
     */
    private static function hasDanglingRequiredReference(ContainerBuilder $container, mixed $value, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        // An AbstractArgument means "unusable until further configuration" (e.g. Symfony's
        // name_based_uuid.factory) — normally pruned as unused, undumpable if kept alive.
        if ($value instanceof AbstractArgument) {
            return true;
        }
        if ($value instanceof Reference) {
            if ($value->getInvalidBehavior() !== ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE) {
                return false;
            }
            $id = (string) $value;

            return $id !== 'service_container' && !$container->hasDefinition($id) && !$container->hasAlias($id);
        }
        if ($value instanceof Definition) {
            return self::hasDanglingRequiredReference($container, [
                $value->getArguments(),
                $value->getProperties(),
                $value->getMethodCalls(),
                $value->getFactory(),
                $value->getConfigurator(),
            ], $depth + 1);
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (self::hasDanglingRequiredReference($container, $item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
