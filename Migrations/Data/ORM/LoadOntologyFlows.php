<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Data\ORM;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds the two built-in flows the data entry points gate on:
 *  - {@see OntologyFlow::NAME_MANUAL} — used by the back-office "Add Data" button (Data View);
 *  - {@see OntologyFlow::NAME_REST_API} — used by the Ontology REST API endpoints.
 *
 * Both are created enabled and native (type = native) — unlike user-created flows, the
 * built-ins cannot be edited. Disabling either in the Flows admin blocks the respective calls.
 * Idempotent: existing flows (by name) are left untouched.
 */
class LoadOntologyFlows implements FixtureInterface
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(OntologyFlow::class);

        foreach ([OntologyFlow::NAME_MANUAL, OntologyFlow::NAME_REST_API] as $name) {
            if ($repository->findOneBy(['name' => $name]) !== null) {
                continue;
            }
            $manager->persist((new OntologyFlow())->setName($name)->setEnabled(true)->setType(OntologyFlow::TYPE_NATIVE));
        }

        $manager->flush();
    }
}
