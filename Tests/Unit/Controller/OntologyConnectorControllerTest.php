<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyConnectorController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Form\Type\OntologyConnectorType;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorConfigSecrets;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\FormBundle\Model\UpdateHandlerFacade;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

class OntologyConnectorControllerTest extends TestCase
{
    private ControllerKit $kit;
    private ConnectorTester&MockObject $tester;
    private OntologyConnectorController $controller;

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->tester = $this->createMock(ConnectorTester::class);
        $this->kit->set(ConnectorTester::class, $this->tester);
        $this->kit->set(ConnectorConfigSecrets::class, new ConnectorConfigSecrets());
        $this->controller = $this->kit->wire(new OntologyConnectorController());
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private static function connector(int $id, string $name, string $type, ?array $config = null, ?string $system = null): OntologyConnector
    {
        $connector = (new OntologyConnector())->setName($name)->setType($type)->setConfig($config);
        if ($system !== null) {
            $connector->setSystem((new OntologySystem())->setName($system));
        }
        (new \ReflectionProperty(OntologyConnector::class, 'id'))->setValue($connector, $id);

        return $connector;
    }

    private static function post(mixed $payload): Request
    {
        return Request::create('/', 'POST', [], [], [], [], \is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testTheIndexNamesTheConnectorGrid(): void
    {
        self::assertSame(['gridName' => 'aaxis-ontology-connectors-grid'], $this->controller->indexAction());
    }

    public function testTheListIsAPickerFeedWithoutAnyConfig(): void
    {
        $this->kit->orm->repository(OntologyConnector::class)->expects(self::once())->method('findBy')->with([], ['name' => 'ASC'])->willReturn([
            self::connector(1, 'Warehouse', 'database', ['password' => 'secret'], 'ERP'),
            self::connector(2, 'Orphan', 'sftp'),
        ]);

        $records = ControllerKit::decode($this->controller->listJsonAction())['records'];

        self::assertSame([
            ['id' => 1, 'name' => 'Warehouse', 'type' => 'database', 'systemName' => 'ERP'],
            ['id' => 2, 'name' => 'Orphan', 'type' => 'sftp', 'systemName' => null],
        ], $records);
    }

    public function testTheViewPageOnlyEverSeesTheMaskedConfig(): void
    {
        $connector = self::connector(1, 'W', 'database', ['server' => 'db', 'password' => 'hunter2']);

        $view = $this->controller->viewAction($connector);

        self::assertSame($connector, $view['entity']);
        self::assertSame(['server' => 'db', 'password' => '********'], $view['maskedConfig']);
    }

    public function testTheViewOfAConnectorWithoutConfigHasNoMaskedConfig(): void
    {
        self::assertNull($this->controller->viewAction(self::connector(1, 'W', 'sftp'))['maskedConfig']);
    }

    public function testCreateAndUpdateGoThroughTheStandardUpdateHandler(): void
    {
        $request = new Request();
        $existing = self::connector(3, 'W', 'sftp');
        $form = $this->createMock(FormInterface::class);
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->with(OntologyConnectorType::class, self::anything())->willReturn($form);
        $this->kit->set('form.factory', $factory);
        $handler = $this->createMock(UpdateHandlerFacade::class);
        $seen = [];
        $handler->method('update')->willReturnCallback(function (object $entity, FormInterface $form, string $message, Request $request) use (&$seen): array {
            $seen[] = [$entity, $message];

            return ['form' => 'view'];
        });
        $this->kit->set(UpdateHandlerFacade::class, $handler);

        self::assertSame(['form' => 'view'], $this->controller->createAction($request));
        self::assertSame(['form' => 'view'], $this->controller->updateAction($existing, $request));

        self::assertInstanceOf(OntologyConnector::class, $seen[0][0]);
        self::assertNull($seen[0][0]->getId(), 'create edits a brand-new connector');
        self::assertSame($existing, $seen[1][0]);
        self::assertSame(['aaxis.ontology.connector.saved', 'aaxis.ontology.connector.saved'], [$seen[0][1], $seen[1][1]]);
    }

    public function testDeleteRemovesTheConnector(): void
    {
        $connector = self::connector(1, 'W', 'sftp');

        $response = $this->controller->deleteAction($connector);

        self::assertSame(['successful' => true], ControllerKit::decode($response));
        self::assertSame([$connector], $this->kit->orm->removed);
    }

    // --- test buttons -------------------------------------------------------------------------

    public function testTestStoredProbesTheSavedConfigurationOnly(): void
    {
        $result = ['success' => true, 'message' => 'All checks passed.', 'steps' => []];
        $this->tester->expects(self::once())->method('test')->with('database', ['server' => 'db', 'password' => 'saved'])->willReturn($result);

        $response = $this->controller->testStoredAction(self::connector(1, 'W', 'database', ['server' => 'db', 'password' => 'saved']));

        self::assertSame($result, ControllerKit::decode($response));
    }

    public function testTestStoredOfAConnectorWithoutTypeOrConfig(): void
    {
        $connector = (new OntologyConnector())->setType(null);
        $this->tester->expects(self::once())->method('test')->with('', null)->willReturn(['success' => false, 'message' => 'Unknown connector type.', 'steps' => []]);

        $this->controller->testStoredAction($connector);
    }

    public function testTestConfigNeedsACreateOrUpdateGrant(): void
    {
        $this->tester->expects(self::never())->method('test');

        $response = $this->controller->testConfigAction(self::post(['type' => 'sftp', 'config' => []]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Access denied.', 'steps' => []], ControllerKit::decode($response));
    }

    /**
     * @dataProvider grantProvider
     */
    public function testEitherGrantIsEnough(string $grant): void
    {
        $this->kit->granted = [$grant];
        $this->tester->expects(self::once())->method('test')->willReturn(['success' => true, 'message' => 'ok', 'steps' => []]);

        self::assertSame(200, $this->controller->testConfigAction(self::post(['type' => 'sftp', 'config' => ['server' => 'h']]))->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function grantProvider(): array
    {
        return ['create' => ['aaxis_ontology_connector_create'], 'update' => ['aaxis_ontology_connector_update']];
    }

    /**
     * @dataProvider badRequestProvider
     */
    public function testTestConfigRejectsMalformedRequests(mixed $payload, string $message): void
    {
        $this->kit->granted = ['aaxis_ontology_connector_update'];
        $this->tester->expects(self::never())->method('test');

        $response = $this->controller->testConfigAction(self::post($payload));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => $message, 'steps' => []], ControllerKit::decode($response));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badRequestProvider(): array
    {
        return [
            'not json' => ['{oops', 'Invalid request body.'],
            'a json scalar' => ['5', 'Invalid request body.'],
            'no type' => [['config' => []], 'Unknown connector type.'],
            'unknown type' => [['type' => 'carrier_pigeon'], 'Unknown connector type.'],
            'type not a string' => [['type' => ['sftp']], 'Unknown connector type.'],
        ];
    }

    public function testStoredSecretsAreRestoredFromTheSameTypeConnectorBeingEdited(): void
    {
        $this->kit->granted = ['aaxis_ontology_connector_update'];
        $stored = self::connector(7, 'W', 'sftp', ['server' => 'old', 'password' => 'stored-secret']);
        $this->kit->orm->repository(OntologyConnector::class)->expects(self::once())->method('find')->with(7)->willReturn($stored);
        $this->tester->expects(self::once())->method('test')->with('sftp', ['server' => 'new', 'password' => 'stored-secret'])
            ->willReturn(['success' => true, 'message' => 'ok', 'steps' => []]);

        $this->controller->testConfigAction(self::post(['type' => 'sftp', 'id' => '7', 'config' => ['server' => 'new', 'password' => '********']]));
    }

    public function testASecretOfAnotherTypeIsNeverBorrowedAfterATypeSwitch(): void
    {
        $this->kit->granted = ['aaxis_ontology_connector_update'];
        $this->kit->orm->repository(OntologyConnector::class)->method('find')->willReturn(self::connector(7, 'W', 'sftp', ['password' => 'sftp-secret']));
        $this->tester->expects(self::once())->method('test')->with('database', ['password' => ''])
            ->willReturn(['success' => false, 'message' => 'x', 'steps' => []]);

        $this->controller->testConfigAction(self::post(['type' => 'database', 'id' => 7, 'config' => ['password' => '********']]));
    }

    /**
     * @dataProvider unusableIdProvider
     */
    public function testAnIdThatIsNotAWholeNumberIsIgnored(mixed $id): void
    {
        $this->kit->granted = ['aaxis_ontology_connector_update'];
        $this->kit->orm->repository(OntologyConnector::class)->expects(self::never())->method('find');
        $this->tester->expects(self::once())->method('test')->with('sftp', ['password' => ''])
            ->willReturn(['success' => true, 'message' => 'ok', 'steps' => []]);

        $this->controller->testConfigAction(self::post(['type' => 'sftp', 'id' => $id, 'config' => ['password' => '********']]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableIdProvider(): array
    {
        return ['null' => [null], 'word' => ['abc'], 'negative' => ['-3'], 'float' => [1.5], 'array' => [[7]], 'empty string' => ['']];
    }

    public function testAnUnknownConnectorIdRestoresNothing(): void
    {
        $this->kit->granted = ['aaxis_ontology_connector_create'];
        $this->kit->orm->repository(OntologyConnector::class)->method('find')->willReturn(null);
        $this->tester->expects(self::once())->method('test')->with('sftp', ['password' => ''])
            ->willReturn(['success' => true, 'message' => 'ok', 'steps' => []]);

        $this->controller->testConfigAction(self::post(['type' => 'sftp', 'id' => 99, 'config' => ['password' => '********']]));
    }

    public function testAMissingOrNonObjectConfigIsTestedAsNull(): void
    {
        $this->kit->granted = ['aaxis_ontology_connector_create'];
        $this->tester->expects(self::exactly(2))->method('test')->with('file_system', null)
            ->willReturn(['success' => false, 'message' => 'No base path configured.', 'steps' => []]);

        $this->controller->testConfigAction(self::post(['type' => 'file_system']));
        $this->controller->testConfigAction(self::post(['type' => 'file_system', 'config' => 'nope']));
    }

    public function testItSubscribesToItsOwnServicesToo(): void
    {
        $services = OntologyConnectorController::getSubscribedServices();

        self::assertContains(ConnectorConfigSecrets::class, $services);
        self::assertContains(ConnectorTester::class, $services);
        self::assertContains(UpdateHandlerFacade::class, $services);
    }
}
