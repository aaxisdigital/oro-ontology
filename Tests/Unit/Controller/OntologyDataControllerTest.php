<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyDataController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDataHistory;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OntologyDataControllerTest extends TestCase
{
    private ControllerKit $kit;
    private BucketEntityDataStore&MockObject $store;
    private OntologyDataApiManager&MockObject $manager;
    private OntologyDataController $controller;

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->store = $this->createMock(BucketEntityDataStore::class);
        $this->store->method('isEnabled')->willReturn(false);
        $this->manager = $this->createMock(OntologyDataApiManager::class);
        $this->kit->set(BucketEntityDataStore::class, $this->store);
        $this->kit->set(OntologyDataApiManager::class, $this->manager);
        $this->controller = $this->kit->wire(new OntologyDataController());
    }

    private static function withId(object $object, int $id): object
    {
        (new \ReflectionProperty($object::class, 'id'))->setValue($object, $id);

        return $object;
    }

    private static function system(int $id, string $name, bool $external = true): OntologySystem
    {
        return self::withId((new OntologySystem())->setName($name)->setExternal($external), $id);
    }

    private static function entity(int $id, string $name, OntologySystem $system, bool $forceDb = false): OntologyEntity
    {
        return self::withId((new OntologyEntity())->setName($name)->setSystem($system)->setUniqueAttribute('id')->setForceDbStorage($forceDb), $id);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function record(int $id, OntologyEntity $entity, string $uniqueId, int $version, ?array $payload, string $updatedAt = '2026-03-04 05:06:07'): OntologyData
    {
        return self::withId(
            (new OntologyData())->setEntity($entity)->setUniqueId($uniqueId)->setUuid('uuid-' . $id)->setVersion($version)
                ->setPayload($payload)->setUpdatedAt(new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'))),
            $id
        );
    }

    private static function post(mixed $payload): Request
    {
        return Request::create('/', 'POST', [], [], [], [], \is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testTheIndexPageHasNoData(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    // --- list: table backend ------------------------------------------------------------------

    public function testTheListServesTheTableRowsWithThePickerLists(): void
    {
        $erp = self::system(1, 'ERP');
        $order = self::entity(10, 'Order', $erp);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->with([], ['name' => 'ASC'])->willReturn([$erp]);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->with([], ['name' => 'ASC'])->willReturn([$order]);
        $this->kit->orm->repository(OntologyData::class)->expects(self::once())->method('findBy')->with([], ['updatedAt' => 'DESC'])->willReturn([
            self::record(5, $order, 'SO-1', 3, ['qty' => 2]),
            self::record(6, $order, 'SO-2', 1, null),
        ]);

        $body = ControllerKit::decode($this->controller->listAction());

        self::assertSame([
            'id' => 5, 'system' => 'ERP', 'entity' => 'Order', 'systemId' => 1, 'entityId' => 10, 'uniqueId' => 'SO-1',
            'uuid' => 'uuid-5', 'version' => 3, 'payload' => '{"qty":2}', 'updatedAt' => '2026-03-04T05:06:07+00:00',
        ], $body['records'][0]);
        self::assertSame('', $body['records'][1]['payload'], 'an empty payload is an empty string, not "null"');
        self::assertSame([['id' => 1, 'name' => 'ERP']], $body['systems']);
        self::assertSame([['id' => 10, 'name' => 'Order', 'systemId' => 1, 'uniqueAttribute' => 'id']], $body['entities']);
    }

    // --- list: bucket backend -----------------------------------------------------------------

    public function testTheListServesBucketRecordsForExternalEntitiesWhileTheToggleIsOn(): void
    {
        $store = $this->createMock(BucketEntityDataStore::class);
        $store->method('isEnabled')->willReturn(true);
        $this->kit->set(BucketEntityDataStore::class, $store);
        $erp = self::system(1, 'ERP');
        $oro = self::system(2, 'OroCommerce', false);
        $orders = self::entity(10, 'Order', $erp);
        $legacy = self::entity(11, 'Legacy', $erp, true);
        $products = self::entity(12, 'Product', $oro);
        $orphan = self::withId((new OntologyEntity())->setName('Orphan'), 13);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->willReturn([$erp, $oro]);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([$orders, $legacy, $products, $orphan]);
        $this->kit->orm->repository(OntologyData::class)->method('findBy')->with(['entity' => $legacy])->willReturn([
            self::record(7, $legacy, 'L-1', 2, ['x' => 1], '2026-03-04 05:00:00'),
        ]);
        $store->method('listLatest')->with($orders)->willReturn([
            ['entity' => 'Order', 'uniqueId' => 'SO-1', 'version' => 4, 'uuid' => 'u-1', 'updatedAt' => '2026-03-05T10:00:00+00:00', 'payload' => ['qty' => 9]],
            ['entity' => 'Order', 'uniqueId' => 'SO-2', 'version' => 1, 'uuid' => 'u-2', 'updatedAt' => '2026-03-01T10:00:00+00:00', 'payload' => []],
        ]);

        $body = ControllerKit::decode($this->controller->listAction());

        // Newest first across both stores; internal and system-less entities contribute nothing.
        self::assertSame(['SO-1', 'L-1', 'SO-2'], array_column($body['records'], 'uniqueId'));
        self::assertSame([
            'id' => 'bucket:10:SO-1', 'bucket' => true, 'system' => 'ERP', 'entity' => 'Order', 'systemId' => 1, 'entityId' => 10,
            'uniqueId' => 'SO-1', 'uuid' => 'u-1', 'version' => 4, 'payload' => '{"qty":9}', 'updatedAt' => '2026-03-05T10:00:00+00:00',
        ], $body['records'][0]);
        self::assertSame(7, $body['records'][1]['id'], 'a force-DB entity still reads its table rows');
        self::assertSame([1, 2], array_column($body['systems'], 'id'));
        self::assertSame([10, 11, 12, 13], array_column($body['entities'], 'id'));
        self::assertNull($body['entities'][3]['systemId']);
    }

    // --- versions (table backend) -------------------------------------------------------------

    public function testAnUnknownRecordHasNoVersions(): void
    {
        $this->kit->orm->repository(OntologyData::class)->method('find')->with(99)->willReturn(null);

        $response = $this->controller->versionsAction(99);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Record not found.'], ControllerKit::decode($response));
    }

    public function testEveryPastSnapshotIsRebuiltByRevertingTheArchivedDiffsNewestFirst(): void
    {
        $order = self::entity(10, 'Order', self::system(1, 'ERP'));
        $current = self::record(5, $order, 'SO-1', 3, [
            'a' => 3,
            'b' => ['x' => 1, 'y' => 5],
            'list' => [1, 9, 7],
            'items' => [['n' => 2]],
            'gone' => 'x',
            's' => 'now',
        ], '2026-03-06 00:00:00');
        $this->kit->orm->repository(OntologyData::class)->method('find')->with(5)->willReturn($current);
        $v2 = (new OntologyDataHistory())->setVersion(2)->setUuid('h2')->setUpdatedAt(new \DateTimeImmutable('2026-03-05 00:00:00', new \DateTimeZone('UTC')))->setPayload([
            'a' => 2,
            'b' => ['y' => 4],
            'list' => ['__1_' => 8, '__2_' => null],
            'items' => ['__0_' => ['n' => 1]],
            's' => null,
        ]);
        $v1 = (new OntologyDataHistory())->setVersion(1)->setUuid('h1')->setUpdatedAt(null)->setPayload([
            'a' => 1,
            'gone' => null,
            'b' => 'scalar-before',
        ]);
        $this->kit->orm->repository(OntologyDataHistory::class)->expects(self::once())->method('findBy')
            ->with(['entity' => $order, 'uniqueId' => 'SO-1'], ['version' => 'DESC'])
            ->willReturn([$v2, $v1]);

        $versions = ControllerKit::decode($this->controller->versionsAction(5))['versions'];

        self::assertSame([3, 2, 1], array_column($versions, 'version'));
        self::assertSame([true, false, false], array_column($versions, 'current'));
        self::assertSame(['uuid-5', 'h2', 'h1'], array_column($versions, 'uuid'));
        self::assertSame(['2026-03-06T00:00:00+00:00', '2026-03-05T00:00:00+00:00', null], array_column($versions, 'updatedAt'));
        self::assertSame(
            ['a' => 2, 'b' => ['x' => 1, 'y' => 4], 'list' => [1, 8], 'items' => [['n' => 1]], 'gone' => 'x'],
            $versions[1]['payload']
        );
        self::assertSame(
            ['a' => 1, 'b' => 'scalar-before', 'list' => [1, 8], 'items' => [['n' => 1]]],
            $versions[2]['payload']
        );
    }

    public function testARecordWithoutHistoryHasJustItsCurrentVersion(): void
    {
        $order = self::entity(10, 'Order', self::system(1, 'ERP'));
        $this->kit->orm->repository(OntologyData::class)->method('find')->willReturn(self::record(5, $order, 'SO-1', 1, null));
        $this->kit->orm->repository(OntologyDataHistory::class)->method('findBy')->willReturn([]);

        $versions = ControllerKit::decode($this->controller->versionsAction(5))['versions'];

        self::assertSame([['version' => 1, 'uuid' => 'uuid-5', 'updatedAt' => '2026-03-04T05:06:07+00:00', 'current' => true, 'payload' => []]], $versions);
    }

    public function testAnArrayPatchAddsAndRemovesElementsAndKeepsThemInOrder(): void
    {
        $order = self::entity(10, 'Order', self::system(1, 'ERP'));
        $this->kit->orm->repository(OntologyData::class)->method('find')->willReturn(self::record(5, $order, 'SO-1', 2, ['list' => ['a', 'b', 'c'], 'notlist' => 'scalar']));
        $this->kit->orm->repository(OntologyDataHistory::class)->method('findBy')->willReturn([
            (new OntologyDataHistory())->setVersion(1)->setPayload([
                'list' => ['__0_' => null, '__3_' => 'was-last', '__1_' => 'B'],
                // a patch against a value that is not a list starts from an empty list
                'notlist' => ['__0_' => 'first'],
            ]),
        ]);

        $versions = ControllerKit::decode($this->controller->versionsAction(5))['versions'];

        self::assertSame(['list' => ['B', 'c', 'was-last'], 'notlist' => ['first']], $versions[1]['payload']);
    }

    public function testAnObjectDiffAgainstAScalarIsRestoredVerbatim(): void
    {
        $order = self::entity(10, 'Order', self::system(1, 'ERP'));
        $this->kit->orm->repository(OntologyData::class)->method('find')->willReturn(self::record(5, $order, 'SO-1', 2, ['v' => 'flat', 'w' => ['a' => 1], 'e' => []]));
        $this->kit->orm->repository(OntologyDataHistory::class)->method('findBy')->willReturn([
            (new OntologyDataHistory())->setVersion(1)->setPayload(['v' => ['was' => 'object'], 'w' => ['a' => 0], 'e' => ['__0_' => 'x']]),
        ]);

        $versions = ControllerKit::decode($this->controller->versionsAction(5))['versions'];

        self::assertSame(['v' => ['was' => 'object'], 'w' => ['a' => 0], 'e' => ['x']], $versions[1]['payload']);
    }

    // --- versions (bucket backend) ------------------------------------------------------------

    /**
     * @dataProvider unknownKeyProvider
     */
    public function testBucketVersionsOfAnUnknownRecordAre404(string $query): void
    {
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturn(null);
        $this->store->expects(self::never())->method('versions');

        $response = $this->controller->versionsByKeyAction(Request::create('/?' . $query));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Record not found.'], ControllerKit::decode($response));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownKeyProvider(): array
    {
        return [
            'no parameters' => [''],
            'entity id zero' => ['entityId=0&uniqueId=A'],
            'unknown entity' => ['entityId=99&uniqueId=A'],
        ];
    }

    public function testBucketVersionsNeedAUniqueId(): void
    {
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturn(self::entity(10, 'Order', self::system(1, 'ERP')));

        self::assertSame(404, $this->controller->versionsByKeyAction(Request::create('/?entityId=10'))->getStatusCode());
    }

    public function testBucketVersionsAreServedAsStored(): void
    {
        $order = self::entity(10, 'Order', self::system(1, 'ERP'));
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->with(10)->willReturn($order);
        $versions = [['version' => 2, 'uuid' => 'u', 'updatedAt' => 't', 'current' => true, 'payload' => ['a' => 1]]];
        $this->store->expects(self::once())->method('versions')->with($order, 'SO 1')->willReturn($versions);

        $response = $this->controller->versionsByKeyAction(Request::create('/?entityId=10&uniqueId=SO+1'));

        self::assertSame(['versions' => $versions], ControllerKit::decode($response));
    }

    public function testABucketRecordWithoutAnyVersionIsA404(): void
    {
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturn(self::entity(10, 'Order', self::system(1, 'ERP')));
        $this->store->method('versions')->willReturn([]);

        self::assertSame(404, $this->controller->versionsByKeyAction(Request::create('/?entityId=10&uniqueId=A'))->getStatusCode());
    }

    // --- create (Add Data modal) --------------------------------------------------------------

    private function withEntity(): OntologyEntity
    {
        $order = self::entity(10, 'Order', self::system(1, 'ERP'));
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturnCallback(fn (int $id): ?OntologyEntity => $id === 10 ? $order : null);

        return $order;
    }

    public function testCreateQueuesTheDecodedRecordsThroughTheSharedManager(): void
    {
        $order = $this->withEntity();
        $flow = (new OntologyFlow())->setName(OntologyFlow::NAME_MANUAL);
        $this->manager->expects(self::once())->method('requireEnabledFlow')->with('Manual')->willReturn($flow);
        $this->manager->expects(self::once())->method('upsertRecords')->with($order, [['id' => 'A', 'q' => 1]], $flow);

        $response = $this->controller->createAction(self::post(['entityId' => 10, 'payload' => '{"id":"A","q":1}']));

        self::assertSame(['success' => true], ControllerKit::decode($response));
    }

    /**
     * @dataProvider formatProvider
     *
     * @param array<int, array<string, mixed>> $expectedRecords
     */
    public function testCreateDecodesEveryFormatIntoRecords(string $format, string $raw, array $expectedRecords): void
    {
        $order = $this->withEntity();
        $this->manager->method('requireEnabledFlow')->willReturn(new OntologyFlow());
        $this->manager->expects(self::once())->method('upsertRecords')->with($order, $expectedRecords, self::anything());

        $response = $this->controller->createAction(self::post(['entityId' => 10, 'format' => $format, 'payload' => $raw]));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string, string, array<int, array<string, mixed>>}>
     */
    public static function formatProvider(): array
    {
        return [
            'json object' => ['json', '{"id":"A"}', [['id' => 'A']]],
            'json list' => ['json', '[{"id":"A"},{"id":"B"}]', [['id' => 'A'], ['id' => 'B']]],
            'json scalar is wrapped' => ['json', '42', [['value' => 42]]],
            'csv rows' => ['csv', "id,name\nA,Alpha\r\n\r\nB\n", [['id' => 'A', 'name' => 'Alpha'], ['id' => 'B', 'name' => '']]],
            'csv with quoting' => ['csv', "id,note\nA,\"x, y\"", [['id' => 'A', 'note' => 'x, y']]],
            'csv header only yields no records' => ['csv', "id,name\n", []],
            'xml' => ['xml', '<order><id>A</id><qty>2</qty></order>', [['id' => 'A', 'qty' => '2']]],
            'blank payload yields no records' => ['json', "  \n ", []],
        ];
    }

    public function testTheFormatDefaultsToJsonWhenOmitted(): void
    {
        $order = $this->withEntity();
        $this->manager->method('requireEnabledFlow')->willReturn(new OntologyFlow());
        $this->manager->expects(self::once())->method('upsertRecords')->with($order, [['id' => 'A']], self::anything());

        $this->controller->createAction(self::post(['entityId' => '10', 'payload' => '{"id":"A"}']));
    }

    /**
     * @dataProvider rejectedRequestProvider
     */
    public function testCreateRejectsWhatItCannotUse(mixed $payload, int $status, string $message): void
    {
        $this->withEntity();
        $this->manager->expects(self::never())->method('upsertRecords');

        $response = $this->controller->createAction(self::post($payload));

        self::assertSame($status, $response->getStatusCode());
        $body = ControllerKit::decode($response);
        self::assertFalse($body['success']);
        self::assertStringStartsWith($message, $body['message']);
    }

    /**
     * @return array<string, array{mixed, int, string}>
     */
    public static function rejectedRequestProvider(): array
    {
        return [
            'body not json' => ['{oops', 400, 'Invalid payload.'],
            'body a scalar' => ['5', 400, 'Invalid payload.'],
            'no entity' => [['payload' => '{}'], 422, 'A valid entity is required.'],
            'entity id zero' => [['entityId' => 0], 422, 'A valid entity is required.'],
            'unknown entity' => [['entityId' => 77, 'payload' => '{}'], 422, 'A valid entity is required.'],
            'broken json' => [['entityId' => 10, 'payload' => '{oops'], 422, 'Syntax error'],
            'unsupported format' => [['entityId' => 10, 'format' => 'yaml', 'payload' => 'a: 1'], 422, 'Unsupported format.'],
            'broken xml' => [['entityId' => 10, 'format' => 'xml', 'payload' => '<a><b></a>'], 422, 'The XML payload is not valid.'],
        ];
    }

    public function testBusinessErrorsKeepTheirStatus(): void
    {
        $this->withEntity();
        $this->manager->method('requireEnabledFlow')->willThrowException(OntologyApiException::flowDisabled('Manual'));

        $response = $this->controller->createAction(self::post(['entityId' => 10, 'payload' => '{"id":"A"}']));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'The "Manual" flow is disabled.'], ControllerKit::decode($response));
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyDataController::getSubscribedServices();

        self::assertContains(OntologyDataApiManager::class, $services);
        self::assertContains(BucketEntityDataStore::class, $services);
        self::assertContains(\Doctrine\Persistence\ManagerRegistry::class, $services);
    }
}
