<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyEntityController;
use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDataHistory;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\DwlOutputFormatter;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptGuard;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityReader;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\EntityBundle\Provider\EntityFieldProvider;
use Oro\Bundle\EntityBundle\Provider\EntityProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OntologyEntityControllerTest extends TestCase
{
    private const string VIEW_DATA = 'VIEW|entity:Aaxis\\Bundle\\OntologyBundle\\Entity\\OntologyData';
    private const string DELETE_DATA = 'DELETE|entity:Aaxis\\Bundle\\OntologyBundle\\Entity\\OntologyData';

    private ControllerKit $kit;
    private BucketEntityDataStore&MockObject $store;
    private EntityProvider&MockObject $entityProvider;
    private EntityFieldProvider&MockObject $fieldProvider;
    private OroEntityReader&MockObject $reader;
    private DwlScriptGuard&MockObject $guard;
    private DwlOutputFormatter&MockObject $formatter;
    private DwlTransformer&MockObject $dwl;
    private OntologyEntityController $controller;

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->store = $this->createMock(BucketEntityDataStore::class);
        $this->store->method('isEnabled')->willReturn(false);
        $this->entityProvider = $this->createMock(EntityProvider::class);
        $this->fieldProvider = $this->createMock(EntityFieldProvider::class);
        $this->reader = $this->createMock(OroEntityReader::class);
        $this->guard = $this->createMock(DwlScriptGuard::class);
        $this->formatter = $this->createMock(DwlOutputFormatter::class);
        $this->dwl = $this->createMock(DwlTransformer::class);
        foreach ([
            BucketEntityDataStore::class => $this->store,
            EntityProvider::class => $this->entityProvider,
            EntityFieldProvider::class => $this->fieldProvider,
            OroEntityReader::class => $this->reader,
            DwlScriptGuard::class => $this->guard,
            DwlOutputFormatter::class => $this->formatter,
            DwlTransformer::class => $this->dwl,
        ] as $id => $service) {
            $this->kit->set($id, $service);
        }
        $this->controller = $this->kit->wire(new OntologyEntityController());
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

    /**
     * @param array<string, array{string, bool}|string> $attributes name => datatype | [datatype, required]
     */
    private static function entity(int $id, string $name, ?OntologySystem $system, array $attributes = [], bool $forceDb = false): OntologyEntity
    {
        $entity = self::withId(
            (new OntologyEntity())->setName($name)->setSystem($system)->setUniqueAttribute('id')->setForceDbStorage($forceDb),
            $id
        );
        foreach ($attributes as $attributeName => $spec) {
            [$datatype, $required] = \is_array($spec) ? $spec : [$spec, false];
            $entity->addAttribute((new OntologyEntityAttribute())->setName((string) $attributeName)->setDatatype($datatype)->setRequired($required));
        }

        return $entity;
    }

    private static function post(mixed $payload, string $method = 'POST'): Request
    {
        return Request::create('/', $method, [], [], [], [], \is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testTheIndexPageHasNoData(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    // --- list ---------------------------------------------------------------------------------

    public function testTheListCarriesEveryEntityWithItsCountsLabelsAndTheDatatypeOptions(): void
    {
        $erp = self::system(1, 'ERP');
        $oro = self::system(2, 'OroCommerce', false);
        $order = self::entity(10, 'Order', $erp, ['id' => [OntologyEntityAttribute::TYPE_TEXT, true], 'qty' => OntologyEntityAttribute::TYPE_NUMBER, ' ' => 'text']);
        $product = self::entity(11, 'Oro\\Bundle\\ProductBundle\\Entity\\Product', $oro, ['sku' => 'text']);
        $ghost = self::entity(12, 'Oro\\Bundle\\Ghost', $oro);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->with([], ['name' => 'ASC'])->willReturn([$order, $product, $ghost]);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->with([], ['name' => 'ASC'])->willReturn([$erp, $oro]);
        $this->kit->orm->queryResult = static function (string $dql): array|int {
            return match (true) {
                str_contains($dql, 'GROUP BY d.entity') => [['entityId' => '10', 'cnt' => '7']],
                str_contains($dql, 'SELECT COUNT(e) FROM Oro\\Bundle\\ProductBundle\\Entity\\Product') => 250,
                default => throw new \RuntimeException('not managed'),
            };
        };
        $this->entityProvider->method('getEntity')->willReturnCallback(static function (string $class): array {
            if ($class === 'Oro\\Bundle\\ProductBundle\\Entity\\Product') {
                return ['label' => 'Product'];
            }
            if ($class === 'Oro\\Bundle\\Ghost') {
                return ['label' => null];
            }
            throw new \RuntimeException('unknown');
        });
        $this->reader->method('searchableFields')->willReturnCallback(static function (OntologyEntity $entity): array {
            if ($entity->getName() === 'Oro\\Bundle\\Ghost') {
                throw new \RuntimeException('unreadable');
            }

            return ['id', 'sku', 'name'];
        });

        $body = ControllerKit::decode($this->controller->listAction());

        [$o, $p, $g] = $body['entities'];
        self::assertSame(
            [
                'id' => 10, 'name' => 'Order', 'displayName' => 'Order', 'uniqueAttribute' => 'id', 'enabled' => true, 'forceDbStorage' => false,
                'systemId' => 1, 'systemName' => 'ERP', 'attributeCount' => 3,
                'attributes' => [
                    ['name' => 'id', 'datatype' => 'text', 'required' => true],
                    ['name' => 'qty', 'datatype' => 'number', 'required' => false],
                    ['name' => ' ', 'datatype' => 'text', 'required' => false],
                ],
                'readerAttributes' => ['id', 'qty'],
                'recordCount' => 7, 'flowCount' => 0,
            ],
            $o
        );
        // An internal entity shows the Oro label, reads the Oro fields and counts its own table.
        self::assertSame(['Product', ['id', 'sku', 'name'], 250], [$p['displayName'], $p['readerAttributes'], $p['recordCount']]);
        // An unreadable/unlabelled one falls back to the class, the configured names and a zero count.
        self::assertSame(['Oro\\Bundle\\Ghost', [], 0], [$g['displayName'], $g['readerAttributes'], $g['recordCount']]);
        self::assertSame([['id' => 1, 'name' => 'ERP', 'external' => true], ['id' => 2, 'name' => 'OroCommerce', 'external' => false]], $body['systems']);
        self::assertSame(
            array_map(static fn (string $type): array => ['value' => $type, 'label' => 'aaxis.ontology.entity_attribute.datatype.' . $type], OntologyEntityAttribute::TYPES),
            $body['datatypes']
        );
    }

    public function testAnInternalEntityWhoseOroLabelCannotBeResolvedShowsItsClass(): void
    {
        $oro = self::system(2, 'OroCommerce', false);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([self::entity(11, 'Oro\\Unknown', $oro)]);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->willReturn([$oro]);
        $this->kit->orm->queryResult = static fn (string $dql): array|int => str_contains($dql, 'GROUP BY') ? [] : 0;
        $this->entityProvider->method('getEntity')->willThrowException(new \RuntimeException('Unknown entity'));
        $this->reader->method('searchableFields')->willReturn(['id']);

        self::assertSame('Oro\\Unknown', ControllerKit::decode($this->controller->listAction())['entities'][0]['displayName']);
    }

    public function testAnExternalEntityWithoutStoredRecordsCountsZero(): void
    {
        $erp = self::system(1, 'ERP');
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([self::entity(10, 'Order', $erp)]);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->willReturn([$erp]);
        $this->kit->orm->queryResult = static fn (): array => [];

        self::assertSame(0, ControllerKit::decode($this->controller->listAction())['entities'][0]['recordCount']);
    }

    public function testBucketBackedEntitiesAreCountedByTheirKeyListing(): void
    {
        $store = $this->createMock(BucketEntityDataStore::class);
        $store->method('isEnabled')->willReturn(true);
        $store->method('countLatest')->willReturn(12);
        $this->kit->set(BucketEntityDataStore::class, $store);
        $erp = self::system(1, 'ERP');
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([
            self::entity(10, 'Order', $erp),
            self::entity(11, 'Legacy', $erp, [], true),
            self::entity(12, 'NoSystem', null),
        ]);
        $this->kit->orm->repository(OntologySystem::class)->method('findBy')->willReturn([$erp]);
        $this->kit->orm->queryResult = static fn (): array => [['entityId' => 11, 'cnt' => 3]];

        $entities = ControllerKit::decode($this->controller->listAction())['entities'];

        self::assertSame(12, $entities[0]['recordCount']);
        self::assertSame(3, $entities[1]['recordCount'], 'an entity that forces db storage keeps the table count');
        self::assertSame(0, $entities[2]['recordCount'], 'without a system it is neither internal nor bucket-backed');
        self::assertNull($entities[2]['systemId']);
    }

    // --- Oro entity pickers -------------------------------------------------------------------

    public function testTheOroEntityPickerListsClassesWithTheirLabels(): void
    {
        $this->entityProvider->expects(self::once())->method('getEntities')->with(false)->willReturn([
            ['name' => 'Oro\\A', 'label' => 'Alpha'],
            ['name' => 'Oro\\B', 'label' => 7],
        ]);

        self::assertSame(
            ['entities' => [['value' => 'Oro\\A', 'label' => 'Alpha'], ['value' => 'Oro\\B', 'label' => '7']]],
            ControllerKit::decode($this->controller->oroEntitiesAction())
        );
    }

    public function testTheOroFieldPickerMapsFieldTypesToOntologyDatatypes(): void
    {
        $this->fieldProvider->expects(self::once())->method('getEntityFields')->with(
            'Oro\\A',
            EntityFieldProvider::OPTION_WITH_RELATIONS | EntityFieldProvider::OPTION_APPLY_EXCLUSIONS | EntityFieldProvider::OPTION_TRANSLATE
        )->willReturn([
            ['name' => 'active', 'label' => 'Active', 'type' => 'boolean'],
            ['name' => 'qty', 'label' => 'Quantity', 'type' => 'smallint'],
            ['name' => 'price', 'type' => 'money'],
            ['name' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['name' => 'born', 'label' => 'Born', 'type' => 'date'],
            ['name' => 'opens', 'label' => 'Opens', 'type' => 'time'],
            ['name' => 'stamp', 'label' => 'Stamp', 'type' => 'datetimetz'],
            ['name' => 'meta', 'label' => 'Meta', 'type' => 'json_array'],
            ['name' => 'owner', 'label' => 'Owner', 'type' => 'ref-one'],
            ['name' => 'mystery'],
        ]);

        $fields = ControllerKit::decode($this->controller->oroFieldsAction(Request::create('/?entity=%20Oro%5CA%20')))['fields'];

        self::assertSame(
            ['boolean', 'number', 'number', 'text', 'date', 'time', 'datetime', 'object', 'undefined', 'undefined'],
            array_column($fields, 'datatype')
        );
        self::assertSame(['label' => 'price', 'value' => 'price', 'datatype' => 'number'], ['label' => $fields[2]['label'], 'value' => $fields[2]['value'], 'datatype' => $fields[2]['datatype']], 'a missing label falls back to the name');
    }

    public function testTheOroFieldPickerNeedsAnEntity(): void
    {
        $this->fieldProvider->expects(self::never())->method('getEntityFields');

        self::assertSame(['fields' => []], ControllerKit::decode($this->controller->oroFieldsAction(Request::create('/?entity=%20'))));
        self::assertSame(['fields' => []], ControllerKit::decode($this->controller->oroFieldsAction(Request::create('/'))));
    }

    // --- save ---------------------------------------------------------------------------------

    private function serveLookups(?OntologySystem $system, ?OntologyEntity $existing = null): void
    {
        $this->kit->orm->repository(OntologySystem::class)->method('find')->willReturnCallback(fn (int $id): ?OntologySystem => $system !== null && $system->getId() === $id ? $system : null);
        $this->kit->orm->repository(OntologyEntity::class)->method('findOneBy')->willReturn($existing);
        $this->kit->orm->queryResult = static fn (): array => [];
    }

    public function testCreatingAnEntityWithItsAttributes(): void
    {
        $erp = self::system(1, 'ERP');
        $this->serveLookups($erp);

        $response = $this->controller->apiCreateAction(self::post([
            'name' => '  Order ',
            'systemId' => '1',
            'uniqueAttribute' => ' orderId ',
            'enabled' => false,
            'forceDbStorage' => true,
            'attributes' => [
                ['name' => 'orderId', 'datatype' => 'text'],
                ['name' => ' qty ', 'datatype' => 'number', 'required' => true],
                ['name' => 'weird', 'datatype' => 'not-a-type'],
                ['name' => '  ', 'datatype' => 'text'],
                ['name' => 'plain'],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $entity = ControllerKit::decode($response)['entity'];
        self::assertSame(['Order', 'orderId', false, true, 1], [$entity['name'], $entity['uniqueAttribute'], $entity['enabled'], $entity['forceDbStorage'], $entity['systemId']]);
        self::assertSame([
            ['name' => 'orderId', 'datatype' => 'text', 'required' => true],
            ['name' => 'qty', 'datatype' => 'number', 'required' => true],
            ['name' => 'weird', 'datatype' => 'undefined', 'required' => false],
            ['name' => 'plain', 'datatype' => 'undefined', 'required' => false],
        ], $entity['attributes']);
        self::assertSame(1, $this->kit->orm->flushes);
        self::assertCount(1, $this->kit->orm->persisted);
    }

    public function testUpdatingReplacesTheAttributeCollection(): void
    {
        $erp = self::system(1, 'ERP');
        $entity = self::entity(10, 'Order', $erp, ['old' => 'text', 'id' => 'text']);
        $this->serveLookups($erp, $entity);

        $response = $this->controller->apiUpdateAction($entity, self::post(['name' => 'Order', 'systemId' => 1, 'uniqueAttribute' => 'id', 'attributes' => [['name' => 'fresh', 'datatype' => 'boolean']]], 'PUT'));

        $attributes = ControllerKit::decode($response)['entity']['attributes'];
        self::assertSame(['fresh'], array_column($attributes, 'name'));
        self::assertTrue(ControllerKit::decode($response)['entity']['enabled'], 'enabled defaults to true');
        self::assertFalse(ControllerKit::decode($response)['entity']['forceDbStorage']);
    }

    /**
     * @dataProvider rejectedSaveProvider
     *
     * @param array<string, mixed>|string $payload
     */
    public function testSaveRejections(array|string $payload, int $status, string $message): void
    {
        $erp = self::system(1, 'ERP');
        $this->serveLookups($erp, self::entity(99, 'Order', $erp));

        $response = $this->controller->apiCreateAction(self::post($payload));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => $message], ControllerKit::decode($response));
        self::assertSame([], $this->kit->orm->persisted);
    }

    /**
     * @return array<string, array{array<string, mixed>|string, int, string}>
     */
    public static function rejectedSaveProvider(): array
    {
        $ok = ['name' => 'Order', 'systemId' => 1, 'uniqueAttribute' => 'id'];

        return [
            'not json' => ['{oops', 400, 'Invalid payload.'],
            'no name' => [['name' => ' '] + $ok, 422, 'aaxis.ontology.entity_manager.name_required'],
            'no system' => [['systemId' => 0] + $ok, 422, 'aaxis.ontology.entity_manager.system_required'],
            'unknown system' => [['systemId' => 42] + $ok, 422, 'aaxis.ontology.entity_manager.system_required'],
            'no unique attribute' => [['uniqueAttribute' => '  '] + $ok, 422, 'aaxis.ontology.entity_manager.unique_attribute_required'],
            'dotted unique attribute' => [['uniqueAttribute' => 'a.b'] + $ok, 422, 'aaxis.ontology.entity_manager.unique_attribute_no_dots'],
            'name taken in that system' => [$ok, 422, 'aaxis.ontology.entity_manager.name_unique'],
        ];
    }

    public function testRenamingAnEntityToItsOwnNameIsNotADuplicate(): void
    {
        $erp = self::system(1, 'ERP');
        $entity = self::entity(10, 'Order', $erp);
        $this->serveLookups($erp, $entity);

        $response = $this->controller->apiUpdateAction($entity, self::post(['name' => 'Order', 'systemId' => 1, 'uniqueAttribute' => 'id']));

        self::assertTrue(ControllerKit::decode($response)['success']);
    }

    public function testEntitiesOfAnInternalSystemMustBeRealOroEntitiesWithRealFields(): void
    {
        $oro = self::system(2, 'OroCommerce', false);
        $this->serveLookups($oro);
        $this->entityProvider->method('getEntity')->willReturn(['label' => 'Product']);
        $this->fieldProvider->method('getEntityFields')->willReturnCallback(static fn (string $class): array => $class === 'Oro\\Product'
            ? [['name' => 'id', 'type' => 'integer'], ['name' => 'sku', 'type' => 'string']]
            : []);
        $this->reader->method('searchableFields')->willReturn(['id', 'sku']);
        $base = ['systemId' => 2, 'uniqueAttribute' => 'id'];

        $notOro = $this->controller->apiCreateAction(self::post(['name' => 'Not\\An\\Entity'] + $base));
        self::assertSame(422, $notOro->getStatusCode());
        self::assertSame('aaxis.ontology.entity_manager.entity_not_oro', ControllerKit::decode($notOro)['message']);

        $badField = $this->controller->apiCreateAction(self::post(['name' => 'Oro\\Product', 'attributes' => [['name' => 'sku'], ['name' => ''], ['name' => 'nope']]] + $base));
        self::assertSame(422, $badField->getStatusCode());
        self::assertSame('aaxis.ontology.entity_manager.attribute_not_oro_field|{"{{ name }}":"nope"}', ControllerKit::decode($badField)['message']);

        $good = $this->controller->apiCreateAction(self::post(['name' => 'Oro\\Product', 'attributes' => [['name' => 'sku']]] + $base));
        self::assertSame(200, $good->getStatusCode());
        self::assertSame('Product', ControllerKit::decode($good)['entity']['displayName']);
    }

    public function testDeleteRemovesTheEntity(): void
    {
        $entity = self::entity(10, 'Order', self::system(1, 'ERP'));

        self::assertSame(['successful' => true], ControllerKit::decode($this->controller->deleteAction($entity)));
        self::assertSame([$entity], $this->kit->orm->removed);
    }

    // --- DWL playground -----------------------------------------------------------------------

    private function playgroundEntity(): OntologyEntity
    {
        return self::entity(10, 'Order', self::system(1, 'ERP'));
    }

    public function testThePlaygroundNeedsToReadTheRecordContentToo(): void
    {
        $this->guard->expects(self::never())->method('check');

        $response = $this->controller->dwlAction($this->playgroundEntity(), self::post(['script' => 'payload']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Access denied.'], ControllerKit::decode($response));
    }

    /**
     * @dataProvider badPlaygroundBodyProvider
     */
    public function testThePlaygroundRejectsUnusableBodies(mixed $body, string $error): void
    {
        $this->kit->granted = [self::VIEW_DATA];
        $this->guard->expects(self::never())->method('check');

        $response = $this->controller->dwlAction($this->playgroundEntity(), self::post($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => $error], ControllerKit::decode($response));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badPlaygroundBodyProvider(): array
    {
        return [
            'not json' => ['{oops', 'Invalid request body.'],
            'a scalar' => ['5', 'Invalid request body.'],
            'no script' => [['limit' => 5], 'The script is empty.'],
            'blank script' => [['script' => " \n "], 'The script is empty.'],
        ];
    }

    public function testAScriptTheGuardRefusesIsAScriptLevelResult(): void
    {
        $this->kit->granted = [self::VIEW_DATA];
        $this->guard->method('check')->with('payload map props()')->willReturn('dw::Runtime::props is not available here.');
        $this->dwl->expects(self::never())->method('transform');

        $response = $this->controller->dwlAction($this->playgroundEntity(), self::post(['script' => 'payload map props()']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'dw::Runtime::props is not available here.'], ControllerKit::decode($response));
    }

    public function testARunTransformsTheStoredPayloadsAndReportsTheOutputFormat(): void
    {
        $this->kit->granted = [self::VIEW_DATA];
        $entity = $this->playgroundEntity();
        $this->guard->method('check')->willReturn(null);
        $this->kit->orm->repository(OntologyData::class)->method('count')->with(['entity' => $entity])->willReturn(250);
        $this->kit->orm->queryResult = static fn (): array => [['payload' => ['a' => 1]], ['payload' => 'not an array'], []];
        $this->formatter->method('detect')->with('payload')->willReturn(['format' => 'json', 'mime' => 'application/json', 'extension' => 'json']);
        $this->dwl->expects(self::once())->method('transform')->with('payload', ['payload' => [['a' => 1], [], []]])->willReturn('the result');
        $this->formatter->expects(self::once())->method('serialize')->with('the result', 'json')->willReturn('"rendered"');

        $response = $this->controller->dwlAction($entity, self::post(['script' => 'payload', 'limit' => '100']));

        self::assertSame([
            'success' => true, 'result' => '"rendered"', 'format' => 'json', 'mime' => 'application/json', 'extension' => 'json',
            'rows' => 3, 'total' => 250, 'truncated' => true,
        ], ControllerKit::decode($response));
        self::assertSame(100, $this->kit->orm->queries[0]['maxResults']);
        self::assertSame(['entity' => $entity], $this->kit->orm->queries[0]['parameters']);
    }

    /**
     * @dataProvider limitProvider
     */
    public function testTheRowLimitIsAPositiveNumberOrNoLimit(mixed $limit, ?int $expectedMax, bool $truncated): void
    {
        $this->kit->granted = [self::VIEW_DATA];
        $this->guard->method('check')->willReturn(null);
        $this->kit->orm->repository(OntologyData::class)->method('count')->willReturn(50);
        $this->kit->orm->queryResult = static fn (): array => [];
        $this->formatter->method('detect')->willReturn(['format' => 'json', 'mime' => 'application/json', 'extension' => 'json']);
        $this->formatter->method('serialize')->willReturn('[]');
        $this->dwl->method('transform')->willReturn([]);

        $response = $this->controller->dwlAction($this->playgroundEntity(), self::post(['script' => 'payload', 'limit' => $limit]));

        self::assertSame($expectedMax, $this->kit->orm->queries[0]['maxResults']);
        self::assertSame($truncated, ControllerKit::decode($response)['truncated']);
    }

    /**
     * @return array<string, array{mixed, int|null, bool}>
     */
    public static function limitProvider(): array
    {
        return [
            'a cap below the total truncates' => [10, 10, true],
            'a cap at the total does not' => [50, 50, false],
            'null means no cap' => [null, null, false],
            'zero means no cap' => [0, null, false],
            'negative means no cap' => [-5, null, false],
            'not numeric means no cap' => ['lots', null, false],
        ];
    }

    public function testAScriptErrorComesBackAsAResultWithTheRowCounts(): void
    {
        $this->kit->granted = [self::VIEW_DATA];
        $this->guard->method('check')->willReturn(null);
        $this->kit->orm->repository(OntologyData::class)->method('count')->willReturn(9);
        $this->kit->orm->queryResult = static fn (): array => [['payload' => ['a' => 1]]];
        $this->formatter->method('detect')->willReturn(['format' => 'json', 'mime' => 'application/json', 'extension' => 'json']);
        $this->dwl->method('transform')->willThrowException(new \RuntimeException('Unexpected token at line 1'));

        $response = $this->controller->dwlAction($this->playgroundEntity(), self::post(['script' => 'bad']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Unexpected token at line 1', 'rows' => 1, 'total' => 9], ControllerKit::decode($response));
    }

    public function testABucketBackedEntityFeedsThePlaygroundFromTheBucket(): void
    {
        $store = $this->createMock(BucketEntityDataStore::class);
        $store->method('isEnabled')->willReturn(true);
        $store->method('countLatest')->willReturn(3);
        $store->method('listLatest')->willReturn([
            ['payload' => ['n' => 1]], ['payload' => ['n' => 2]], ['payload' => 'junk'],
        ]);
        $this->kit->set(BucketEntityDataStore::class, $store);
        $this->kit->granted = [self::VIEW_DATA];
        $this->guard->method('check')->willReturn(null);
        $this->formatter->method('detect')->willReturn(['format' => 'json', 'mime' => 'application/json', 'extension' => 'json']);
        $this->formatter->method('serialize')->willReturn('x');
        $this->dwl->expects(self::exactly(2))->method('transform')->willReturn([]);

        $capped = ControllerKit::decode($this->controller->dwlAction($this->playgroundEntity(), self::post(['script' => 'payload', 'limit' => 2])));
        $uncapped = ControllerKit::decode($this->controller->dwlAction($this->playgroundEntity(), self::post(['script' => 'payload'])));

        self::assertSame([2, 3, true], [$capped['rows'], $capped['total'], $capped['truncated']]);
        self::assertSame([3, false], [$uncapped['rows'], $uncapped['truncated']]);
        self::assertSame([], $this->kit->orm->queries, 'the table is never queried for a bucket-backed entity');
    }

    public function testThePlaygroundCountFollowsTheSameRules(): void
    {
        $entity = $this->playgroundEntity();
        self::assertSame(403, $this->controller->dwlCountAction($entity)->getStatusCode());

        $this->kit->granted = [self::VIEW_DATA];
        $this->kit->orm->repository(OntologyData::class)->method('count')->with(['entity' => $entity])->willReturn(41);

        self::assertSame(['success' => true, 'total' => 41], ControllerKit::decode($this->controller->dwlCountAction($entity)));
    }

    // --- erase records ------------------------------------------------------------------------

    public function testErasingNeedsTheDataDeletePermissionOnTopOfTheEntityOne(): void
    {
        $this->kit->granted = [self::VIEW_DATA];

        $response = $this->controller->purgeRecordsAction($this->playgroundEntity());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Access denied.'], ControllerKit::decode($response));
        self::assertSame([], $this->kit->orm->queries);
    }

    public function testErasingDropsHistoryAndRecordsWithoutHydratingAnything(): void
    {
        $this->kit->granted = [self::DELETE_DATA];
        $entity = $this->playgroundEntity();
        $this->kit->orm->queryResult = static fn (string $dql): int => str_contains($dql, OntologyDataHistory::class) ? 40 : 12;

        $response = $this->controller->purgeRecordsAction($entity);

        self::assertSame(['success' => true, 'deleted' => 12], ControllerKit::decode($response), 'only the live records are counted');
        self::assertSame(
            ['DELETE FROM ' . OntologyDataHistory::class . ' h WHERE h.entity = :entity', 'DELETE FROM ' . OntologyData::class . ' d WHERE d.entity = :entity'],
            array_column($this->kit->orm->queries, 'dql')
        );
        self::assertSame(['entity' => $entity], $this->kit->orm->queries[1]['parameters']);
    }

    public function testErasingAnExternalEntityAlsoEmptiesItsBucket(): void
    {
        $store = $this->createMock(BucketEntityDataStore::class);
        $store->method('isEnabled')->willReturn(true);
        $entity = $this->playgroundEntity();
        $store->expects(self::once())->method('purgeEntity')->with($entity)->willReturn(5);
        $this->kit->set(BucketEntityDataStore::class, $store);
        $this->kit->granted = [self::DELETE_DATA];
        $this->kit->orm->queryResult = static fn (): int => 2;

        self::assertSame(['success' => true, 'deleted' => 7], ControllerKit::decode($this->controller->purgeRecordsAction($entity)));
    }

    public function testErasingAnInternalEntityNeverTouchesTheBucket(): void
    {
        $store = $this->createMock(BucketEntityDataStore::class);
        $store->method('isEnabled')->willReturn(true);
        $store->expects(self::never())->method('purgeEntity');
        $this->kit->set(BucketEntityDataStore::class, $store);
        $this->kit->granted = [self::DELETE_DATA];
        $this->kit->orm->queryResult = static fn (): int => 0;

        $this->controller->purgeRecordsAction(self::entity(11, 'Oro\\X', self::system(2, 'OroCommerce', false)));
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyEntityController::getSubscribedServices();

        foreach ([BucketEntityDataStore::class, EntityProvider::class, EntityFieldProvider::class, DwlTransformer::class, DwlOutputFormatter::class, DwlScriptGuard::class, OroEntityReader::class] as $service) {
            self::assertContains($service, $services);
        }
    }
}
