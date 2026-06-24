<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Data\ORM;

use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds the built-in internal system "OroCommerce" (external = false).
 *
 * Internal systems cannot be deleted and their entities/attributes are constrained to the real
 * OroCommerce entity model (see the Entities UI). Idempotent: an existing system with this name is
 * left untouched.
 */
class LoadOntologySystems implements FixtureInterface
{
    public const string NAME_OROCOMMERCE = 'OroCommerce';

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(OntologySystem::class);
        if ($repository->findOneBy(['name' => self::NAME_OROCOMMERCE]) !== null) {
            return;
        }

        $system = (new OntologySystem())
            ->setName(self::NAME_OROCOMMERCE)
            ->setEnabled(true)
            ->setExternal(false);

        $manager->persist($system);
        $manager->flush();
    }
}
