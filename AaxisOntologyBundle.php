<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle;

use Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler\InvokableServicesCleanupPass;
use Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler\InvokableServicesPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The AaxisOntologyBundle adds the "Ontology" section (systems, entities, connectors, flows,
 * events and data) to the back-office (admin) application menu, under the shared "Aaxis" group.
 */
class AaxisOntologyBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Builds the "Invoke PHP" step's callable universe (every service, by class) — and, after
        // every other bundle's removals, strips locator entries whose services vanished.
        // The collect pass runs LAST in its phase (lower priority = later): other bundles create
        // services in their own passes (Oro's per-scope config managers, e.g. oro_config.global),
        // and collecting earlier would read references to them as dangling.
        $container->addCompilerPass(new InvokableServicesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -2048);
        // Priority far below 0: within the phase, LOWER priority runs LATER — this must see
        // every other bundle's removals.
        $container->addCompilerPass(new InvokableServicesCleanupPass(), PassConfig::TYPE_AFTER_REMOVING, -2048);
    }
}
