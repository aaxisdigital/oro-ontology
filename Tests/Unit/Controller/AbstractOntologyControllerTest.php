<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\AbstractOntologyController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\FormBundle\Model\UpdateHandlerFacade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class AbstractOntologyControllerTest extends TestCase
{
    private ControllerKit $kit;
    private AbstractOntologyController $controller;

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->controller = $this->kit->wire(new class () extends AbstractOntologyController {
            public function update(object $entity, string $formType, string $message, Request $request): array|RedirectResponse
            {
                return $this->updateEntity($entity, $formType, $message, $request);
            }

            public function remove(object $entity): JsonResponse
            {
                return $this->deleteEntity($entity);
            }

            public function count(string $class): int
            {
                return $this->oroEntityRecordCount($class);
            }
        });
    }

    public function testUpdateDelegatesToTheStandardUpdateHandlerWithATranslatedMessage(): void
    {
        $entity = new OntologySystem();
        $request = new Request();
        $form = $this->createMock(FormInterface::class);
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('App\\FormType', $entity)->willReturn($form);
        $this->kit->set('form.factory', $factory);
        $handler = $this->createMock(UpdateHandlerFacade::class);
        $handler->expects(self::once())->method('update')
            ->with($entity, $form, 'aaxis.ontology.system.saved', $request)
            ->willReturn(['entity' => $entity]);
        $this->kit->set(UpdateHandlerFacade::class, $handler);

        self::assertSame(['entity' => $entity], $this->controller->update($entity, 'App\\FormType', 'aaxis.ontology.system.saved', $request));
    }

    public function testDeleteRemovesThroughTheEntitysOwnManager(): void
    {
        $entity = new OntologySystem();

        $response = $this->controller->remove($entity);

        self::assertSame(['successful' => true], ControllerKit::decode($response));
        self::assertSame([$entity], $this->kit->orm->removed);
        self::assertSame(1, $this->kit->orm->flushes);
    }

    public function testRecordCountCountsRowsOfTheEntityClass(): void
    {
        $this->kit->orm->queryResult = static fn (string $dql): string => str_contains($dql, 'SELECT COUNT(e) FROM App\\Entity\\Order e') ? '42' : '-1';

        self::assertSame(42, $this->controller->count('App\\Entity\\Order'));
    }

    public function testRecordCountIsZeroForAClassDoctrineDoesNotManage(): void
    {
        $this->kit->orm->managesClasses = false;

        self::assertSame(0, $this->controller->count('App\\Nope'));
        self::assertSame([], $this->kit->orm->queries);
    }

    public function testRecordCountIsZeroWheneverTheLookupFails(): void
    {
        $this->kit->orm->queryResult = static function (): never {
            throw new \RuntimeException('relation does not exist');
        };
        self::assertSame(0, $this->controller->count('App\\Entity\\Order'));

        $this->kit->orm->managerFailure = new \RuntimeException('no such manager');
        self::assertSame(0, $this->controller->count('App\\Entity\\Order'));
    }

    public function testItSubscribesToTheSharedServices(): void
    {
        $services = AbstractOntologyController::getSubscribedServices();

        self::assertContains(UpdateHandlerFacade::class, $services);
        self::assertContains(TranslatorInterface::class, $services);
        self::assertContains(\Doctrine\Persistence\ManagerRegistry::class, $services);
    }
}
