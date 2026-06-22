<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\FormBundle\Model\UpdateHandlerFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared CRUD plumbing for the Ontology entities (save via the standard Oro update
 * handler, delete via the entity manager).
 */
abstract class AbstractOntologyController extends AbstractController
{
    /**
     * @return array<string, mixed>|RedirectResponse
     */
    protected function updateEntity(object $entity, string $formType, string $savedMessageKey, Request $request): array|RedirectResponse
    {
        return $this->container->get(UpdateHandlerFacade::class)->update(
            $entity,
            $this->createForm($formType, $entity),
            $this->container->get(TranslatorInterface::class)->trans($savedMessageKey),
            $request
        );
    }

    protected function deleteEntity(object $entity): JsonResponse
    {
        $em = $this->container->get(ManagerRegistry::class)->getManagerForClass($entity::class);
        $em->remove($entity);
        $em->flush();

        return new JsonResponse(['successful' => true]);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            UpdateHandlerFacade::class,
            TranslatorInterface::class,
            ManagerRegistry::class,
        ]);
    }
}
