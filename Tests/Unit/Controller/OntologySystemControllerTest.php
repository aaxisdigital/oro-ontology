<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologySystemController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OntologySystemControllerTest extends TestCase
{
    private ControllerKit $kit;
    private OntologySystemController $controller;

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->controller = $this->kit->wire(new OntologySystemController());
    }

    private static function system(string $name, int $id, bool $external = true, bool $enabled = true): OntologySystem
    {
        $system = (new OntologySystem())->setName($name)->setExternal($external)->setEnabled($enabled);
        (new \ReflectionProperty(OntologySystem::class, 'id'))->setValue($system, $id);

        return $system;
    }

    private static function json(string $body, string $method = 'POST'): Request
    {
        return Request::create('/', $method, [], [], [], [], $body);
    }

    public function testTheIndexPageHasNoData(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    // --- list ---------------------------------------------------------------------------------

    public function testTheListCarriesGroupedCountsForEverySystem(): void
    {
        $erp = self::system('ERP', 1);
        $crm = self::system('CRM', 2);
        $oro = self::system('OroCommerce', 3, false);
        $this->kit->orm->repository(OntologySystem::class)->expects(self::once())->method('findBy')->with([], ['name' => 'ASC'])->willReturn([$erp, $crm, $oro]);
        $oroEntities = [
            (new OntologyEntity())->setName('Oro\\Bundle\\ProductBundle\\Entity\\Product'),
            (new OntologyEntity())->setName('Oro\\Bundle\\Ghost'),
        ];
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->with(['system' => $oro])->willReturn($oroEntities);
        $this->kit->orm->queryResult = static function (string $dql): array|int {
            return match (true) {
                str_contains($dql, 'COUNT(e.id) AS cnt') => [['systemId' => '1', 'cnt' => '3'], ['systemId' => 2, 'cnt' => 1]],
                str_contains($dql, 'COUNT(d.id) AS cnt') => [['systemId' => '1', 'cnt' => '40']],
                str_contains($dql, 'Oro\\Bundle\\ProductBundle\\Entity\\Product') => 25,
                default => throw new \RuntimeException('not a managed entity'),
            };
        };

        $response = $this->controller->listAction();

        self::assertSame([
            ['id' => 1, 'name' => 'ERP', 'enabled' => true, 'external' => true, 'entityCount' => 3, 'recordCount' => 40, 'flowCount' => 0],
            ['id' => 2, 'name' => 'CRM', 'enabled' => true, 'external' => true, 'entityCount' => 1, 'recordCount' => 0, 'flowCount' => 0],
            // Internal: the record total is summed from the OroCommerce tables (the unreadable one counts 0),
            // and a system with no entity row counts 0 entities.
            ['id' => 3, 'name' => 'OroCommerce', 'enabled' => true, 'external' => false, 'entityCount' => 0, 'recordCount' => 25, 'flowCount' => 0],
        ], ControllerKit::decode($response)['systems']);
    }

    // --- save ---------------------------------------------------------------------------------

    public function testCreatingASystem(): void
    {
        $this->kit->orm->repository(OntologySystem::class)->method('findOneBy')->with(['name' => 'ERP'])->willReturn(null);
        $this->kit->orm->repository(OntologyEntity::class)->method('count')->willReturn(0);
        $this->kit->orm->queryResult = static fn (): int => 0;

        $response = $this->controller->apiCreateAction(self::json('{"name":"  ERP ","enabled":false}'));

        self::assertSame(200, $response->getStatusCode());
        $body = ControllerKit::decode($response);
        self::assertTrue($body['success']);
        self::assertSame(['name' => 'ERP', 'enabled' => false, 'external' => true, 'entityCount' => 0, 'recordCount' => 0], array_intersect_key($body['system'], array_flip(['name', 'enabled', 'external', 'entityCount', 'recordCount'])));
        self::assertCount(1, $this->kit->orm->persisted);
        self::assertSame(1, $this->kit->orm->flushes);
    }

    public function testASystemIsEnabledByDefaultAndItsExternalFlagIsNeverWritten(): void
    {
        $internal = self::system('OroCommerce', 3, false);
        $this->kit->orm->repository(OntologySystem::class)->method('findOneBy')->willReturn($internal);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([]);
        $this->kit->orm->repository(OntologyEntity::class)->method('count')->willReturn(7);

        $response = $this->controller->apiUpdateAction($internal, self::json('{"name":"OroCommerce","external":true}', 'PUT'));

        $body = ControllerKit::decode($response)['system'];
        self::assertTrue($body['enabled']);
        self::assertFalse($body['external'], 'the payload cannot flip a system to external');
        self::assertSame(7, $body['entityCount']);
        self::assertSame(0, $body['recordCount']);
    }

    public function testRenamingASystemToItsOwnNameIsNotADuplicate(): void
    {
        $system = self::system('ERP', 1);
        $this->kit->orm->repository(OntologySystem::class)->method('findOneBy')->willReturn($system);
        $this->kit->orm->repository(OntologyEntity::class)->method('count')->willReturn(0);
        $this->kit->orm->queryResult = static fn (): int => 5;

        $response = $this->controller->apiUpdateAction($system, self::json('{"name":"ERP"}', 'PUT'));

        self::assertTrue(ControllerKit::decode($response)['success']);
        self::assertSame(5, ControllerKit::decode($response)['system']['recordCount']);
    }

    public function testSaveRejections(): void
    {
        $existing = self::system('ERP', 1);
        $other = self::system('CRM', 2);
        $this->kit->orm->repository(OntologySystem::class)->method('findOneBy')->willReturn($existing);

        $garbage = $this->controller->apiCreateAction(self::json('not json'));
        self::assertSame(400, $garbage->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Invalid payload.'], ControllerKit::decode($garbage));

        $blank = $this->controller->apiCreateAction(self::json('{"name":"   "}'));
        self::assertSame(422, $blank->getStatusCode());
        self::assertSame('aaxis.ontology.system_manager.name_required', ControllerKit::decode($blank)['message']);

        $duplicate = $this->controller->apiUpdateAction($other, self::json('{"name":"ERP"}'));
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertSame('aaxis.ontology.system_manager.name_unique', ControllerKit::decode($duplicate)['message']);

        self::assertSame([], $this->kit->orm->persisted);
    }

    // --- delete -------------------------------------------------------------------------------

    public function testInternalSystemsCannotBeDeleted(): void
    {
        $response = $this->controller->deleteAction(self::system('OroCommerce', 3, false));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['successful' => false, 'message' => 'aaxis.ontology.system_manager.delete_internal_forbidden'],
            ControllerKit::decode($response)
        );
        self::assertSame([], $this->kit->orm->removed);
    }

    public function testDeletingAnExternalSystem(): void
    {
        $system = self::system('ERP', 1);

        $response = $this->controller->deleteAction($system);

        self::assertSame(['successful' => true], ControllerKit::decode($response));
        self::assertSame([$system], $this->kit->orm->removed);
        self::assertSame(1, $this->kit->orm->flushes);
    }

    // --- the shared plumbing ------------------------------------------------------------------

    public function testAFailingCountOfAnInternalSystemNeverBreaksThePage(): void
    {
        $oro = self::system('OroCommerce', 3, false);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->willReturn([$oro]);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([(new OntologyEntity())->setName('Oro\\Bundle\\Anything')]);
        $this->kit->orm->queryResult = static function (string $dql): array {
            if (str_contains($dql, 'GROUP BY')) {
                return [];
            }
            throw new \RuntimeException('database is down');
        };

        $systems = ControllerKit::decode($this->controller->listAction())['systems'];

        self::assertSame(0, $systems[0]['recordCount']);
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologySystemController::getSubscribedServices();

        foreach ([\Doctrine\Persistence\ManagerRegistry::class, \Symfony\Contracts\Translation\TranslatorInterface::class, \Oro\Bundle\FormBundle\Model\UpdateHandlerFacade::class] as $service) {
            self::assertContains($service, $services);
        }
    }
}
