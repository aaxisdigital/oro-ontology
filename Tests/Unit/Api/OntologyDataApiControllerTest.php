<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Api;

use Aaxis\Bundle\OntologyBundle\Api\OntologyDataApiController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class OntologyDataApiControllerTest extends TestCase
{
    private OntologyDataApiManager&MockObject $manager;
    private OntologyDataApiController $controller;

    /** @var array<string, bool> */
    private array $config = [
        'aaxis_ontology.api_read_enabled' => true,
        'aaxis_ontology.api_upsert_enabled' => true,
        'aaxis_ontology.api_query_enabled' => true,
    ];

    /** @var array<int, string> */
    private array $granted = ['aaxis_ontology_api_access_all'];

    protected function setUp(): void
    {
        $this->manager = $this->createMock(OntologyDataApiManager::class);

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(fn (string $attribute): bool => \in_array($attribute, $this->granted, true));

        $container = new Container();
        $container->set(OntologyDataApiManager::class, $this->manager);
        $container->set(ConfigManager::class, $config);
        $container->set('security.authorization_checker', $checker);
        $this->controller = new OntologyDataApiController();
        $this->controller->setContainer($container);
    }

    private function flow(): OntologyFlow
    {
        return (new OntologyFlow())->setName(OntologyFlow::NAME_REST_API);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function json(string $body, string $method = 'POST'): Request
    {
        return Request::create('/', $method, [], [], [], [], $body);
    }

    // --- read ---------------------------------------------------------------------------------

    public function testReadReturnsThePayloadItself(): void
    {
        $this->granted = ['aaxis_ontology_api_access_read'];
        $this->manager->expects(self::once())->method('requireEnabledFlow')->with('Ontology REST API')->willReturn($this->flow());
        $this->manager->expects(self::once())->method('read')->with('ERP', 'Order', 'SO-1')->willReturn(['id' => 'SO-1', 'qty' => 2]);

        $response = $this->controller->readAction('ERP', 'Order', 'SO-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 'SO-1', 'qty' => 2], self::decode($response));
    }

    /**
     * @dataProvider emptyPayloadProvider
     */
    public function testAnEmptyPayloadRendersAsAnEmptyJsonObject(?array $payload): void
    {
        $this->manager->method('read')->willReturn($payload);

        self::assertSame('{}', $this->controller->readAction('ERP', 'Order', '1')->getContent());
    }

    /**
     * @return array<string, array{array<int, mixed>|null}>
     */
    public static function emptyPayloadProvider(): array
    {
        return ['null' => [null], 'empty' => [[]]];
    }

    public function testReadIsA404WhenTheEndpointIsSwitchedOff(): void
    {
        $this->config['aaxis_ontology.api_read_enabled'] = false;
        $this->manager->expects(self::never())->method('read');

        $response = $this->controller->readAction('ERP', 'Order', '1');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Not found.', 'code' => 'endpoint_disabled'], self::decode($response));
    }

    /**
     * @dataProvider readGrantProvider
     *
     * @param array<int, string> $granted
     */
    public function testReadNeedsTheReadOrTheAllCapability(array $granted, int $status): void
    {
        $this->granted = $granted;
        $this->manager->method('read')->willReturn(['x' => 1]);

        $response = $this->controller->readAction('ERP', 'Order', '1');

        self::assertSame($status, $response->getStatusCode());
        if ($status === 403) {
            self::assertSame(['error' => 'Access denied.', 'code' => 'forbidden'], self::decode($response));
        }
    }

    /**
     * @return array<string, array{array<int, string>, int}>
     */
    public static function readGrantProvider(): array
    {
        return [
            'read' => [['aaxis_ontology_api_access_read'], 200],
            'all' => [['aaxis_ontology_api_access_all'], 200],
            'flow only' => [['aaxis_ontology_api_access_flow'], 403],
            'nothing' => [[], 403],
        ];
    }

    public function testBusinessErrorsKeepTheirStatusAndCode(): void
    {
        $this->manager->method('requireEnabledFlow')->willThrowException(OntologyApiException::flowDisabled('Ontology REST API'));

        $response = $this->controller->readAction('ERP', 'Order', '1');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'The "Ontology REST API" flow is disabled.', 'code' => 'flow_disabled'], self::decode($response));
    }

    public function testAMissingRecordIsA404(): void
    {
        $this->manager->method('read')->willThrowException(OntologyApiException::recordNotFound('9'));

        $response = $this->controller->readAction('ERP', 'Order', '9');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('record_not_found', self::decode($response)['code']);
    }

    // --- upsert -------------------------------------------------------------------------------

    public function testUpsertOfAnObjectIsASingleRecordBatchAnswered202(): void
    {
        $flow = $this->flow();
        $this->manager->expects(self::once())->method('requireEnabledFlow')->willReturn($flow);
        $this->manager->expects(self::once())->method('upsert')->with('ERP', 'Order', [['id' => 'A']], $flow)->willReturn('uuid-1');

        $response = $this->controller->upsertAction(self::json('{"id":"A"}'), 'ERP', 'Order');

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['success' => true, 'uuid' => 'uuid-1', 'count' => 1], self::decode($response));
    }

    public function testUpsertOfAListIsABatch(): void
    {
        $this->manager->method('requireEnabledFlow')->willReturn($this->flow());
        $this->manager->expects(self::once())->method('upsert')->with('ERP', 'Order', [['id' => 'A'], ['id' => 'B']], self::anything())->willReturn('uuid-2');

        $response = $this->controller->upsertAction(self::json('[{"id":"A"},{"id":"B"}]'), 'ERP', 'Order');

        self::assertSame(['success' => true, 'uuid' => 'uuid-2', 'count' => 2], self::decode($response));
    }

    /**
     * @dataProvider unusableBodyProvider
     */
    public function testUpsertRejectsABodyThatIsNotAnObjectOrList(string $body): void
    {
        $this->manager->expects(self::never())->method('upsert');

        $response = $this->controller->upsertAction(self::json($body), 'ERP', 'Order');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'The request body must be a JSON object or array of objects.', 'code' => 'invalid_payload'],
            self::decode($response)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableBodyProvider(): array
    {
        return ['empty' => [''], 'garbage' => ['{oops'], 'a scalar' => ['"text"'], 'a number' => ['5'], 'null' => ['null']];
    }

    public function testUpsertNeedsTheAllCapabilityNotJustRead(): void
    {
        $this->granted = ['aaxis_ontology_api_access_read'];
        $this->manager->expects(self::never())->method('upsert');

        $response = $this->controller->upsertAction(self::json('{"id":"A"}'), 'ERP', 'Order');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::decode($response)['code']);
    }

    public function testUpsertIsA404WhenSwitchedOffEvenForABadBody(): void
    {
        $this->config['aaxis_ontology.api_upsert_enabled'] = false;

        $response = $this->controller->upsertAction(self::json('garbage'), 'ERP', 'Order');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('endpoint_disabled', self::decode($response)['code']);
    }

    public function testUpsertValidationErrorsAreRendered(): void
    {
        $this->manager->method('requireEnabledFlow')->willReturn($this->flow());
        $this->manager->method('upsert')->willThrowException(OntologyApiException::invalidPayload('Record #1 is invalid.'));

        $response = $this->controller->upsertAction(self::json('{"id":"A"}'), 'ERP', 'Order');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Record #1 is invalid.', 'code' => 'invalid_payload'], self::decode($response));
    }

    // --- query --------------------------------------------------------------------------------

    public function testQueryPassesFiltersOrderAndPagingAndWrapsTheItems(): void
    {
        $this->granted = ['aaxis_ontology_api_access_read'];
        $this->manager->method('requireEnabledFlow')->willReturn($this->flow());
        $this->manager->expects(self::once())->method('query')
            ->with('ERP', 'Order', [['attribute' => 'status', 'value' => 'open']], 'created DESC', 3, 25)
            ->willReturn([['id' => 'A']]);

        $request = Request::create('/?page=3&page_size=25', 'POST', [], [], [], [], '{"filter":[{"attribute":"status","value":"open"}],"orderBy":"created DESC"}');
        $response = $this->controller->queryAction($request, 'ERP', 'Order');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['items' => [['id' => 'A']], 'page' => 3, 'page_size' => 25], self::decode($response));
    }

    public function testQueryDefaultsToPageOneOfFiftyWithNoFilterForAnEmptyBody(): void
    {
        $this->manager->method('requireEnabledFlow')->willReturn($this->flow());
        $this->manager->expects(self::once())->method('query')->with('ERP', 'Order', [], null, 1, 50)->willReturn([]);

        $response = $this->controller->queryAction(self::json(''), 'ERP', 'Order');

        self::assertSame(['items' => [], 'page' => 1, 'page_size' => 50], self::decode($response));
    }

    public function testQueryIgnoresAFilterThatIsNotAListAndFloorsThePaging(): void
    {
        $this->manager->method('requireEnabledFlow')->willReturn($this->flow());
        $this->manager->expects(self::once())->method('query')->with('ERP', 'Order', [], '5', 1, 1)->willReturn([]);

        $request = Request::create('/?page=-4&page_size=0', 'POST', [], [], [], [], '{"filter":"nope","orderBy":5}');
        $response = $this->controller->queryAction($request, 'ERP', 'Order');

        self::assertSame(['items' => [], 'page' => 1, 'page_size' => 1], self::decode($response));
    }

    /**
     * @dataProvider unusableQueryBodyProvider
     */
    public function testQueryRejectsABodyThatIsNotAnObject(string $body): void
    {
        $this->manager->expects(self::never())->method('query');

        $response = $this->controller->queryAction(self::json($body), 'ERP', 'Order');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'The request body must be a JSON object.', 'code' => 'invalid_query'], self::decode($response));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableQueryBodyProvider(): array
    {
        return ['garbage' => ['{oops'], 'a scalar' => ['"text"'], 'null' => ['null']];
    }

    public function testQueryIsGuardedLikeRead(): void
    {
        $this->config['aaxis_ontology.api_query_enabled'] = false;
        self::assertSame(404, $this->controller->queryAction(self::json('{}'), 'ERP', 'Order')->getStatusCode());

        $this->config['aaxis_ontology.api_query_enabled'] = true;
        $this->granted = [];
        self::assertSame(403, $this->controller->queryAction(self::json('{}'), 'ERP', 'Order')->getStatusCode());
    }

    public function testQueryErrorsAreRendered(): void
    {
        $this->manager->method('requireEnabledFlow')->willReturn($this->flow());
        $this->manager->method('query')->willThrowException(OntologyApiException::invalidQuery('Unsupported filter compare "!=".'));

        $response = $this->controller->queryAction(self::json('{}'), 'ERP', 'Order');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Unsupported filter compare "!=".', 'code' => 'invalid_query'], self::decode($response));
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyDataApiController::getSubscribedServices();

        self::assertContains(OntologyDataApiManager::class, $services);
        self::assertContains(ConfigManager::class, $services);
    }
}
