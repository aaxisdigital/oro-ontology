<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\FlowImportException;
use Aaxis\Bundle\OntologyBundle\Manager\FlowPortability;
use Aaxis\Bundle\OntologyBundle\Manager\FlowStepValidator;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class FlowPortabilityTest extends TestCase
{
    private ManagerRegistry&MockObject $doctrine;
    private FlowStepValidator&MockObject $validator;
    private ObjectManager&MockObject $em;
    private FlowPortability $portability;

    /** @var array<int, OntologyConnector> */
    private array $connectors = [];

    /** @var array<string, OntologyFlow> flows by name */
    private array $flows = [];

    /** @var array<string, OntologyEntity> "system/entity" => entity */
    private array $entities = [];

    protected function setUp(): void
    {
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->doctrine->method('getRepository')->willReturnCallback(fn (string $class): ObjectRepository => match ($class) {
            OntologyConnector::class => $this->connectorRepository(),
            OntologyFlow::class => $this->flowRepository(),
            OntologySystem::class => $this->systemRepository(),
            OntologyEntity::class => $this->entityRepository(),
        });
        $this->em = $this->createMock(ObjectManager::class);
        $this->doctrine->method('getManagerForClass')->with(OntologyFlow::class)->willReturn($this->em);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params): string {
            $key = str_replace('aaxis.ontology.flow_portability.', '', $id);

            return $params === [] ? $key : $key . ' ' . json_encode($params, JSON_UNESCAPED_SLASHES);
        });
        $this->validator = $this->createMock(FlowStepValidator::class);
        $this->validator->method('normalize')->willReturnArgument(0);
        $this->validator->method('validate')->willReturn([]);

        $this->portability = new FlowPortability($this->doctrine, $translator, $this->validator);
    }

    private function connectorRepository(): ObjectRepository
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?OntologyConnector => $this->connectors[$id] ?? null);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria): array => array_values(array_filter(
            $this->connectors,
            static fn (OntologyConnector $c): bool => $c->getName() === $criteria['name']
        )));

        return $repository;
    }

    private function flowRepository(): ObjectRepository
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturnCallback(function (mixed $id): ?OntologyFlow {
            foreach ($this->flows as $flow) {
                if ($flow->getId() === $id) {
                    return $flow;
                }
            }

            return null;
        });
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?OntologyFlow => $this->flows[$criteria['name']] ?? null);

        return $repository;
    }

    private function systemRepository(): ObjectRepository
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?OntologySystem {
            foreach ($this->entities as $entity) {
                if ($entity->getSystem()->getName() === $criteria['name']) {
                    return $entity->getSystem();
                }
            }

            return null;
        });

        return $repository;
    }

    private function entityRepository(): ObjectRepository
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?OntologyEntity => $this->entities[$criteria['system']->getName() . '/' . $criteria['name']] ?? null
        );

        return $repository;
    }

    private function addConnector(int $id, string $name, string $type, string $system): void
    {
        $connector = (new OntologyConnector())->setName($name)->setType($type)->setSystem((new OntologySystem())->setName($system));
        (new \ReflectionProperty(OntologyConnector::class, 'id'))->setValue($connector, $id);
        $this->connectors[$id] = $connector;
    }

    private function addFlow(int $id, string $name, string $type = OntologyFlow::TYPE_SUBFLOW): OntologyFlow
    {
        $flow = (new OntologyFlow())->setName($name)->setType($type);
        (new \ReflectionProperty(OntologyFlow::class, 'id'))->setValue($flow, $id);

        return $this->flows[$name] = $flow;
    }

    private function addEntity(string $system, string $name, string $uniqueAttribute): void
    {
        $this->entities[$system . '/' . $name] = (new OntologyEntity())
            ->setSystem((new OntologySystem())->setName($system))
            ->setName($name)
            ->setUniqueAttribute($uniqueAttribute);
    }

    private static function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (FlowImportException $e) {
            return $e->getErrors();
        }
        self::fail('Expected a FlowImportException.');
    }

    // --- export --------------------------------------------------------------------------------

    public function testExportReplacesLocalIdsWithPortableDescriptors(): void
    {
        $this->addConnector(3, 'Erp api', 'rest_api', 'Erp');
        $this->addFlow(9, 'Enrich');
        $this->addEntity('Erp', 'Order', 'number');
        $steps = [
            ['type' => 'cron', 'name' => 'Nightly', 'config' => ['enabled' => true]],
            ['type' => 'invoke', 'name' => 'Call', 'config' => ['connector' => '3', 'path' => '/x']],
            ['type' => 'sub_flow', 'name' => 'Sub', 'config' => ['subflow' => 9]],
            ['type' => 'entity_read', 'name' => 'Read', 'config' => ['system' => ' Erp ', 'entity' => 'Order']],
            ['type' => 'entity_write', 'name' => 'Write', 'config' => ['system' => 'Crm', 'entity' => 'Lead']],
            ['type' => 'logger', 'name' => 'Log'],
        ];
        $flow = (new OntologyFlow())->setName('Nightly')->setType(OntologyFlow::TYPE_FLOW)->setSteps($steps)
            ->setDesign(['version' => 2, 'steps' => $steps, 'links' => [['from' => 'a', 'to' => 'b']]]);

        $document = $this->portability->export($flow);

        self::assertSame('aaxis-ontology-flow', $document['format']);
        self::assertSame(1, $document['version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $document['exportedAt']);
        self::assertSame('Nightly', $document['flow']['name']);
        self::assertSame(OntologyFlow::TYPE_FLOW, $document['flow']['type']);
        $expectedSteps = [
            $steps[0],
            ['type' => 'invoke', 'name' => 'Call', 'config' => ['path' => '/x', 'connectorRef' => ['name' => 'Erp api', 'type' => 'rest_api', 'system' => 'Erp']]],
            ['type' => 'sub_flow', 'name' => 'Sub', 'config' => ['subflowRef' => ['name' => 'Enrich']]],
            $steps[3],
            $steps[4],
            $steps[5],
        ];
        self::assertSame($expectedSteps, $document['flow']['steps']);
        self::assertSame($expectedSteps, $document['flow']['design']['steps']);
        self::assertSame([['from' => 'a', 'to' => 'b']], $document['flow']['design']['links']);
        self::assertSame([
            ['system' => 'Erp', 'entity' => 'Order', 'uniqueAttribute' => 'number'],
            ['system' => 'Crm', 'entity' => 'Lead', 'uniqueAttribute' => null],
        ], $document['entities']);
    }

    public function testComputedEntityNamesAreNeitherListedNorChecked(): void
    {
        $steps = [
            ['type' => 'entity_read', 'name' => 'Dynamic', 'config' => ['system' => 'payload.s', 'system_dwl' => true, 'entity' => 'Order']],
            ['type' => 'entity_write', 'name' => 'Half', 'config' => ['system' => 'Erp', 'entity' => 'payload.e', 'entity_dwl' => true]],
        ];

        $document = $this->portability->export((new OntologyFlow())->setName('Dyn')->setSteps($steps));

        self::assertSame([], $document['entities']);
    }

    public function testExportOfAnEmptyFlow(): void
    {
        $document = $this->portability->export((new OntologyFlow())->setName('Empty'));

        self::assertSame([], $document['flow']['steps']);
        self::assertNull($document['flow']['design']);
        self::assertSame([], $document['entities']);
    }

    public function testExportRefusesABrokenFlowListingEveryProblemOnce(): void
    {
        $this->addFlow(5, 'Regular', OntologyFlow::TYPE_FLOW);
        $steps = [
            'junk',
            ['type' => 'invoke', 'name' => 'Call', 'config' => ['connector' => 77]],
            ['type' => 'sub_flow', 'name' => 'Gone', 'config' => ['subflow' => 404]],
            ['type' => 'sub_flow', 'config' => ['subflow' => 5]],
        ];
        $flow = (new OntologyFlow())->setName('Broken')->setSteps($steps)->setDesign(['steps' => $steps]);

        self::assertSame([
            'malformed_step',
            'export_connector_missing {"{{ name }}":"Call"}',
            'export_subflow_missing {"{{ name }}":"Gone"}',
            'export_subflow_missing {"{{ name }}":"?"}',
        ], self::errorsOf(fn () => $this->portability->export($flow)));
    }

    // --- import: document shape ------------------------------------------------------------------

    /**
     * @dataProvider unreadableDocuments
     */
    public function testImportRefusesUnreadableDocuments(mixed $document, string $error): void
    {
        self::assertSame([$error], self::errorsOf(fn () => $this->portability->import($document)));
    }

    public static function unreadableDocuments(): array
    {
        $head = ['format' => 'aaxis-ontology-flow', 'version' => 1];

        return [
            'not an object' => ['flow', 'invalid_format'],
            'a list' => [[1, 2], 'invalid_format'],
            'wrong format' => [['format' => 'other', 'version' => 1], 'invalid_format'],
            'wrong version' => [['format' => 'aaxis-ontology-flow', 'version' => 2], 'unsupported_version {"{{ version }}":"2","{{ supported }}":"1"}'],
            'unreadable version' => [['format' => 'aaxis-ontology-flow', 'version' => [1]], 'unsupported_version {"{{ version }}":"?","{{ supported }}":"1"}'],
            'no flow' => [$head, 'invalid_format'],
            'flow as list' => [$head + ['flow' => [1]], 'invalid_format'],
        ];
    }

    /**
     * @dataProvider invalidFlows
     *
     * @param array<int, string> $errors
     */
    public function testImportCollectsEveryProblemOfTheFlow(array $flow, array $errors, array $manifest = []): void
    {
        $this->addFlow(1, 'Taken', OntologyFlow::TYPE_FLOW);
        $this->addFlow(2, 'Regular', OntologyFlow::TYPE_FLOW);
        $this->addConnector(10, 'Twin', 'rest_api', 'A');
        $this->addConnector(11, 'Twin', 'rest_api', 'A');
        $this->addConnector(12, 'Other', 'sftp', 'B');
        $this->addEntity('Erp', 'Order', 'number');
        $document = ['format' => 'aaxis-ontology-flow', 'version' => 1, 'flow' => $flow, 'entities' => $manifest];

        self::assertSame($errors, self::errorsOf(fn () => $this->portability->import($document)));
    }

    public static function invalidFlows(): array
    {
        $design = static fn (array $steps): array => ['version' => 2, 'steps' => $steps];
        $step = static fn (array $config, string $name = 'S'): array => ['type' => 'logger', 'name' => $name, 'config' => $config];

        return [
            'blank name' => [['name' => ' '], ['invalid_name']],
            'overlong name' => [['name' => str_repeat('n', 129)], ['invalid_name']],
            'non-string name' => [['name' => 5], ['invalid_name']],
            'taken name' => [['name' => 'Taken'], ['name_taken {"{{ name }}":"Taken"}']],
            'scalar steps' => [['name' => 'N', 'steps' => 'x'], ['invalid_format']],
            'list design' => [['name' => 'N', 'design' => [1]], ['invalid_format']],
            'scalar design' => [['name' => 'N', 'design' => 'x'], ['invalid_format']],
            'other design version' => [
                ['name' => 'N', 'design' => ['version' => 1, 'steps' => []]],
                ['unsupported_design {"{{ version }}":"1","{{ supported }}":"2"}'],
            ],
            'unreadable design version' => [
                ['name' => 'N', 'design' => ['steps' => []]],
                ['unsupported_design {"{{ version }}":"?","{{ supported }}":"2"}'],
            ],
            'steps disagree with the design' => [
                ['name' => 'N', 'steps' => [$step([], 'A')], 'design' => $design([$step([], 'B')])],
                ['steps_design_mismatch'],
            ],
            'raw ids are reported once each' => [
                ['name' => 'N', 'steps' => [$step(['connector' => 1]), $step(['connector' => 2], 'T'), $step(['subflow' => 3], 'U')]],
                ['raw_connector_id', 'raw_subflow_id'],
            ],
            'connector references' => [
                ['name' => 'N', 'steps' => [
                    $step(['connectorRef' => ['name' => 'Twin', 'type' => 'rest_api']], 'A'),
                    $step(['connectorRef' => ['type' => 'rest_api', 'system' => 'A']], 'B'),
                    $step(['connectorRef' => ['name' => 'Twin', 'type' => 'rest_api', 'system' => 'A']], 'C'),
                    $step(['connectorRef' => ['name' => 'Other', 'type' => 'rest_api', 'system' => 'B']], 'D'),
                    $step(['connectorRef' => ['name' => 'Ghost', 'type' => 'sftp', 'system' => 'B']], 'E'),
                    $step(['connectorRef' => 'not a ref'], 'F'),
                ]],
                [
                    'incomplete_connector_ref {"{{ name }}":"Twin"}',
                    'incomplete_connector_ref {"{{ name }}":"?"}',
                    'connector_ambiguous {"{{ name }}":"Twin"}',
                    'connector_missing {"{{ name }}":"Other","{{ type }}":"rest_api"}',
                    'connector_missing {"{{ name }}":"Ghost","{{ type }}":"sftp"}',
                ],
            ],
            'subflow references' => [
                ['name' => 'N', 'steps' => [
                    $step(['subflowRef' => ['name' => ' ']], 'A'),
                    $step(['subflowRef' => ['name' => 'Nope']], 'B'),
                    $step(['subflowRef' => ['name' => 'Regular']], 'C'),
                    $step(['subflowRef' => 'x'], 'D'),
                ]],
                ['incomplete_subflow_ref', 'subflow_missing {"{{ name }}":"Nope"}', 'subflow_not_subflow {"{{ name }}":"Regular"}'],
            ],
            'entities' => [
                ['name' => 'N', 'steps' => [
                    $step(['system' => 'Erp', 'entity' => 'Order'], 'A'),
                    $step(['system' => 'Erp', 'entity' => 'Invoice'], 'B'),
                    $step(['system' => 'Ghost', 'entity' => 'Order'], 'C'),
                ]],
                [
                    'entity_attribute_mismatch {"{{ entity }}":"Order","{{ system }}":"Erp","{{ expected }}":"id","{{ actual }}":"number"}',
                    'entity_missing {"{{ entity }}":"Invoice","{{ system }}":"Erp"}',
                    'entity_missing {"{{ entity }}":"Order","{{ system }}":"Ghost"}',
                ],
                [['system' => 'Erp', 'entity' => 'Order', 'uniqueAttribute' => ' id '], 'junk', ['system' => 'Erp', 'entity' => 'Invoice']],
            ],
            'duplicate problems are reported once' => [
                ['name' => 'N', 'steps' => [$step(['subflowRef' => ['name' => 'Nope']], 'A')], 'design' => $design([$step(['subflowRef' => ['name' => 'Nope']], 'A')])],
                ['subflow_missing {"{{ name }}":"Nope"}'],
            ],
        ];
    }

    // --- import: success -----------------------------------------------------------------------

    public function testImportResolvesReferencesAndStoresADisabledFlow(): void
    {
        $this->addConnector(10, 'Erp api', 'rest_api', 'Erp');
        $this->addFlow(20, 'Enrich');
        $this->addEntity('Erp', 'Order', 'number');
        $steps = [
            ['type' => 'cron', 'name' => ' Imported nightly ', 'config' => ['enabled' => true, 'expression' => '* * * * *']],
            ['type' => 'invoke', 'name' => 'Call', 'config' => ['connectorRef' => ['name' => 'Erp api', 'type' => 'rest_api', 'system' => 'Erp']]],
            ['type' => 'invoke', 'name' => 'Call again', 'config' => ['connectorRef' => ['name' => 'Erp api', 'type' => 'rest_api', 'system' => 'Erp']]],
            ['type' => 'sub_flow', 'name' => 'Sub', 'config' => ['subflowRef' => ['name' => 'Enrich']]],
            ['type' => 'sub_flow', 'name' => 'Sub again', 'config' => ['subflowRef' => ['name' => 'Enrich']]],
            ['type' => 'entity_read', 'name' => 'Read', 'config' => ['system' => 'Erp', 'entity' => 'Order']],
        ];
        $design = ['version' => 2, 'steps' => $steps, 'links' => []];
        $persisted = null;
        $this->em->expects(self::once())->method('persist')->willReturnCallback(static function (OntologyFlow $flow) use (&$persisted): void {
            $persisted = $flow;
        });
        $this->em->expects(self::once())->method('flush');

        $flow = $this->portability->import([
            'format' => 'aaxis-ontology-flow',
            'version' => 1,
            'flow' => ['name' => 'Document name', 'steps' => $steps, 'design' => $design],
            'entities' => [['system' => 'Erp', 'entity' => 'Order', 'uniqueAttribute' => 'number']],
        ]);

        self::assertSame($persisted, $flow);
        self::assertSame('Imported nightly', $flow->getName());
        self::assertFalse($flow->isEnabled());
        self::assertSame(OntologyFlow::TYPE_FLOW, $flow->getType());
        self::assertSame('cron', $flow->getTriggerType());
        self::assertEqualsWithDelta(time(), $flow->getLastModified()->getTimestamp(), 5);
        $expected = [
            ['type' => 'cron', 'name' => ' Imported nightly ', 'config' => ['enabled' => false, 'expression' => '* * * * *']],
            ['type' => 'invoke', 'name' => 'Call', 'config' => ['connector' => '10']],
            ['type' => 'invoke', 'name' => 'Call again', 'config' => ['connector' => '10']],
            ['type' => 'sub_flow', 'name' => 'Sub', 'config' => ['subflow' => '20']],
            ['type' => 'sub_flow', 'name' => 'Sub again', 'config' => ['subflow' => '20']],
            $steps[5],
        ];
        self::assertSame($expected, $flow->getSteps());
        self::assertSame($expected, $flow->getDesign()['steps']);
    }

    public function testATriggerlessSubflowFileKeepsTheDocumentName(): void
    {
        $flow = $this->portability->import([
            'format' => 'aaxis-ontology-flow',
            'version' => 1,
            'flow' => ['name' => ' Legacy ', 'steps' => [], 'design' => null],
        ]);

        self::assertSame('Legacy', $flow->getName());
        self::assertNull($flow->getSteps());
        self::assertNull($flow->getDesign());
        self::assertSame(OntologyFlow::TYPE_SUBFLOW, $flow->getType());
        self::assertNull($flow->getTriggerType());
    }

    public function testAnUnconfiguredTriggerIsDisabledToo(): void
    {
        $flow = $this->portability->import([
            'format' => 'aaxis-ontology-flow',
            'version' => 1,
            'flow' => ['name' => 'N', 'steps' => [['type' => 'subflow', 'name' => 'Entry'], 'kept']],
        ]);

        self::assertSame([['type' => 'subflow', 'name' => 'Entry', 'config' => ['enabled' => false]], 'kept'], $flow->getSteps());
    }

    public function testTheTriggerNameMustBeFreeToo(): void
    {
        $this->addFlow(1, 'Existing', OntologyFlow::TYPE_FLOW);

        self::assertSame(['name_taken {"{{ name }}":"Existing"}'], self::errorsOf(fn () => $this->portability->import([
            'format' => 'aaxis-ontology-flow',
            'version' => 1,
            'flow' => ['name' => 'Fresh', 'steps' => [['type' => 'cron', 'name' => 'Existing']]],
        ])));
    }

    public function testStepsTheValidatorCannotNormalizeAreRefused(): void
    {
        $validator = $this->createMock(FlowStepValidator::class);
        $validator->method('normalize')->willReturn(null);
        $portability = new FlowPortability($this->doctrine, $this->createMock(TranslatorInterface::class), $validator);

        $this->expectException(FlowImportException::class);

        $portability->import(['format' => 'aaxis-ontology-flow', 'version' => 1, 'flow' => ['name' => 'N', 'steps' => ['junk']]]);
    }

    public function testStepValidationProblemsAreReportedAsIs(): void
    {
        $validator = $this->createMock(FlowStepValidator::class);
        $validator->method('normalize')->willReturnArgument(0);
        $validator->expects(self::once())->method('validate')->willReturn(['first', 'second']);
        $portability = new FlowPortability($this->doctrine, $this->createMock(TranslatorInterface::class), $validator);

        self::assertSame(['first', 'second'], self::errorsOf(static fn () => $portability->import([
            'format' => 'aaxis-ontology-flow',
            'version' => 1,
            'flow' => ['name' => 'N', 'steps' => [['type' => 'logger', 'name' => 'L']]],
        ])));
    }
}
