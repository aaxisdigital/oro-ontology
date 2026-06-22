<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Data\ORM;

use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\DistributionBundle\Handler\ApplicationState;
use Oro\Bundle\SecurityBundle\Migrations\Data\ORM\AbstractUpdatePermissions;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Grants the full Ontology Data API capability ("aaxis_ontology_api_access_all") to the
 * Administrator role.
 */
class LoadAaxisOntologyApiAdminPermissions extends AbstractUpdatePermissions
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        if (!$this->container->get(ApplicationState::class)->isInstalled()) {
            return;
        }

        $aclManager = $this->getAclManager();
        if (!$aclManager->isAclEnabled()) {
            return;
        }

        $this->enableActions(
            $aclManager,
            $this->getRole($manager, User::ROLE_ADMINISTRATOR),
            ['aaxis_ontology_api_access_all']
        );

        $aclManager->flush();
    }
}
