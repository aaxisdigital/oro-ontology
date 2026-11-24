<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyFlowController;
use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\FlowImportException;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor;
use Aaxis\Bundle\OntologyBundle\Manager\FlowHistoryArchiver;
use Aaxis\Bundle\OntologyBundle\Manager\FlowPortability;
use Aaxis\Bundle\OntologyBundle\Manager\FlowStepValidator;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\SampleService;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

class OntologyFlowControllerTest extends TestCase
{
    private const string UUID = '123e4567-e89b-42d3-a456-426614174000';
    private const string CACHE_KEY = 'aaxis_ontology_debug_ctx.' . self::UUID;

    private ControllerKit $kit;
    private FlowDebugExecutor&MockObject $executor;
    private FlowPortability&MockObject $portability;
    private FlowStepValidator&MockObject $validator;
    private FlowHistoryArchiver&MockObject $archiver;
    private OntologyFlowEventEmitter&MockObject $events;
    private PhpMethodInvoker&MockObject $invoker;
    private DwlTransformer&MockObject $dwl;
    private ArrayAdapter $cache;
    private OntologyFlowController $controller;

    /** @var array<string, mixed> */
    private array $config = [
        'aaxis_ontology.flow_editor_grid_spacing' => 20,
        'aaxis_ontology.flow_editor_step_size_factor' => 6,
        'aaxis_ontology.flow_debug_autoclose_seconds' => 15,
        'aaxis_ontology.flow_debug_timeout_minutes' => 10,
    ];

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->executor = $this->createMock(FlowDebugExecutor::class);
        $this->portability = $this->createMock(FlowPortability::class);
        $this->validator = $this->createMock(FlowStepValidator::class);
        $this->validator->method('problemReport')->willReturn(['errors' => [], 'fields' => []]);
        $this->archiver = $this->createMock(FlowHistoryArchiver::class);
        $this->events = $this->createMock(OntologyFlowEventEmitter::class);
        $this->invoker = $this->createMock(PhpMethodInvoker::class);
        $this->dwl = $this->createMock(DwlTransformer::class);
        $this->cache = new ArrayAdapter();
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);

        foreach ([
            FlowDebugExecutor::class => $this->executor,
            FlowPortability::class => $this->portability,
            FlowStepValidator::class => $this->validator,
            FlowHistoryArchiver::class => $this->archiver,
            OntologyFlowEventEmitter::class => $this->events,
            PhpMethodInvoker::class => $this->invoker,
            DwlTransformer::class => $this->dwl,
            ConfigManager::class => $config,
            CacheItemPoolInterface::class => $this->cache,
        ] as $id => $service) {
            $this->kit->set($id, $service);
        }
        $this->controller = $this->kit->wire(new OntologyFlowController());
    }

    // --- helpers ------------------------------------------------------------------------------

    private static function flow(?int $id, string $name, string $type = OntologyFlow::TYPE_FLOW, ?array $steps = null): OntologyFlow
    {
        $flow = (new OntologyFlow())->setName($name)->setType($type)->setSteps($steps);
        if ($id !== null) {
            (new \ReflectionProperty(OntologyFlow::class, 'id'))->setValue($flow, $id);
        }

        return $flow;
    }

    private static function post(mixed $payload, string $method = 'POST'): Request
    {
        return Request::create('/', $method, [], [], [], [], \is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return ControllerKit::decode($response);
    }

    private function user(string $first = '', string $last = '', string $email = '', string $identifier = 'jdoe'): UserInterface
    {
        return new class ($first, $last, $email, $identifier) implements UserInterface {
            public function __construct(private readonly string $first, private readonly string $last, private readonly string $email, private readonly string $identifier)
            {
            }

            public function getFirstName(): string
            {
                return $this->first;
            }

            public function getLastName(): string
            {
                return $this->last;
            }

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function debugPayload(array $override = []): array
    {
        return $override + [
            'steps' => [
                ['id' => 's1', 'type' => 'cron', 'name' => 'Nightly', 'config' => ['enabled' => true]],
                ['id' => 's2', 'type' => 'logger', 'name' => 'Log', 'config' => null],
            ],
            'links' => [['from' => 's1', 'fromPort' => 0, 'to' => 's2']],
            'input' => ['x' => 1],
        ];
    }

    private function storeBlob(array $blob, string $key = self::UUID): void
    {
        $item = $this->cache->getItem('aaxis_ontology_debug_ctx.' . strtolower($key));
        $item->set($blob);
        $this->cache->save($item);
    }

    // --- pages + lists ------------------------------------------------------------------------

    public function testTheIndexPageHasNoData(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    public function testTheEditorOpensAnEmptyCanvasForANewFlow(): void
    {
        $view = $this->controller->editorAction();

        self::assertSame(['flow' => null, 'gridSpacing' => 20, 'stepSizeFactor' => 6, 'debugAutoCloseSeconds' => 15], $view);
    }

    public function testTheEditorLoadsAnExistingUserFlow(): void
    {
        $flow = self::flow(5, 'Nightly', OntologyFlow::TYPE_FLOW, [['type' => 'cron', 'name' => 'Nightly']]);
        $this->kit->orm->repository(OntologyFlow::class)->method('find')->with(5)->willReturn($flow);

        $view = $this->controller->editorAction(5);

        self::assertSame(5, $view['flow']['id']);
        self::assertSame('Nightly', $view['flow']['name']);
    }

    public function testTheEditorRefusesUnknownAndBuiltInFlows(): void
    {
        $repository = $this->kit->orm->repository(OntologyFlow::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?OntologyFlow => $id === 7 ? self::flow(7, 'Manual', OntologyFlow::TYPE_NATIVE) : null);

        try {
            $this->controller->editorAction(99);
            self::fail('a NotFoundHttpException was expected');
        } catch (NotFoundHttpException $e) {
            self::assertSame('Flow not found.', $e->getMessage());
        }
        try {
            $this->controller->editorAction(7);
            self::fail('an AccessDeniedHttpException was expected');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame('aaxis.ontology.flow_manager.edit_builtin_forbidden', $e->getMessage());
        }
    }

    /**
     * @dataProvider editorSettingProvider
     */
    public function testTheEditorSettingsAreClampedToSaneRanges(mixed $spacing, mixed $factor, mixed $autoClose, int $expectedSpacing, int $expectedFactor, int $expectedAutoClose): void
    {
        $this->config['aaxis_ontology.flow_editor_grid_spacing'] = $spacing;
        $this->config['aaxis_ontology.flow_editor_step_size_factor'] = $factor;
        $this->config['aaxis_ontology.flow_debug_autoclose_seconds'] = $autoClose;

        $view = $this->controller->editorAction();

        self::assertSame([$expectedSpacing, $expectedFactor, $expectedAutoClose], [$view['gridSpacing'], $view['stepSizeFactor'], $view['debugAutoCloseSeconds']]);
    }

    /**
     * @return array<string, array{mixed, mixed, mixed, int, int, int}>
     */
    public static function editorSettingProvider(): array
    {
        return [
            'unset falls back to the defaults' => [null, null, null, 10, 8, 0],
            'zero falls back to the defaults' => [0, 0, 0, 10, 8, 0],
            'too small' => [1, 1, -4, 4, 2, 0],
            'too large' => [500, 99, 30, 100, 16, 30],
            'in range' => ['25', '5', '7', 25, 5, 7],
        ];
    }

    public function testTheListServesEveryFlowByName(): void
    {
        $this->kit->orm->repository(OntologyFlow::class)->expects(self::once())->method('findBy')->with([], ['name' => 'ASC'])->willReturn([
            self::flow(1, 'A'), self::flow(2, 'B', OntologyFlow::TYPE_SUBFLOW),
        ]);

        $records = self::decode($this->controller->listAction())['records'];

        self::assertSame(['A', 'B'], array_column($records, 'name'));
        self::assertSame(['flow', 'subflow'], array_column($records, 'type'));
    }

    public function testTheSerializedFlowCarriesItsRunningStateAndInvalidSteps(): void
    {
        $validator = $this->createMock(FlowStepValidator::class);
        $validator->method('problemReport')->with([['type' => 'cron']])->willReturn([
            'errors' => ['Broken' => 'Step "Broken" is not configured.', 'Bad code' => 'bad DWL'],
            'fields' => ['Bad code' => ['code' => 'bad DWL']],
        ]);
        $this->kit->set(FlowStepValidator::class, $validator);
        $flow = self::flow(1, 'A', OntologyFlow::TYPE_FLOW, [['type' => 'cron']])
            ->setLastExecuted(new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('UTC')))
            ->setLastModified(new \DateTimeImmutable('2026-03-03 00:00:00', new \DateTimeZone('UTC')))
            ->setDesign(['version' => 2]);
        $this->kit->orm->repository(OntologyFlow::class)->method('findBy')->willReturn([$flow]);

        $record = self::decode($this->controller->listAction())['records'][0];

        self::assertEquals(
            ['lastExecuted' => '2026-03-04T05:06:07+00:00', 'lastFinished' => null, 'running' => true, 'lastModified' => '2026-03-03T00:00:00+00:00', 'invalidSteps' => ['Broken', 'Bad code'], 'design' => ['version' => 2], 'invalidFields' => ['Bad code' => ['code' => 'bad DWL']]],
            array_intersect_key($record, array_flip(['lastExecuted', 'lastFinished', 'running', 'lastModified', 'invalidSteps', 'design', 'invalidFields']))
        );
    }

    public function testAFlowWithoutFieldProblemsSerializesThemAsAnEmptyObject(): void
    {
        $this->kit->orm->repository(OntologyFlow::class)->method('findBy')->willReturn([self::flow(1, 'A', OntologyFlow::TYPE_FLOW, [])]);

        $json = (string) $this->controller->listAction()->getContent();

        self::assertStringContainsString('"invalidFields":{}', $json);
    }

    // --- Invoke PHP type-aheads ---------------------------------------------------------------

    public function testTheClassTypeAheadListsTheInvokableClasses(): void
    {
        $this->invoker->method('invokableClasses')->willReturn(['App\\A', 'App\\B']);

        self::assertSame(['classes' => ['App\\A', 'App\\B']], self::decode($this->controller->phpClassesAction()));
    }

    public function testTheMethodTypeAheadDescribesThePublicMethodsOfAnInvokableClass(): void
    {
        $this->invoker->expects(self::once())->method('isInvokable')->with(SampleService::class)->willReturn(true);

        $response = $this->controller->phpMethodsAction(Request::create('/?class=' . urlencode(' \\' . SampleService::class . ' ')));

        self::assertSame(['alpha', 'mike', 'zulu'], array_column(self::decode($response)['methods'], 'name'));
        self::assertSame([
            ['name' => 'name', 'type' => 'string', 'required' => true],
            ['name' => 'id', 'type' => 'string|int', 'required' => true],
            ['name' => 'untyped', 'type' => 'mixed', 'required' => true],
            ['name' => 'options', 'type' => '?array', 'required' => false],
        ], self::decode($response)['methods'][0]['params']);
        self::assertSame([['name' => 'flag', 'type' => 'bool', 'required' => true]], self::decode($response)['methods'][1]['params']);
        self::assertSame([], self::decode($response)['methods'][2]['params']);
    }

    public function testTheMethodTypeAheadRefusesAClassThatIsNotInvokable(): void
    {
        $this->invoker->method('isInvokable')->with('Evil\\Class')->willReturn(false);

        $response = $this->controller->phpMethodsAction(Request::create('/?class=Evil%5CClass'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Unknown class.'], self::decode($response));
    }

    // --- create / update / delete -------------------------------------------------------------

    private function acceptSteps(?array $normalized): void
    {
        $this->validator->method('normalize')->willReturnCallback(static fn (mixed $raw): ?array => $normalized ?? (\is_array($raw) ? $raw : null));
        $this->validator->method('structuralErrors')->willReturn([]);
    }

    public function testCreatingAFlowDerivesItsNameTypeTriggerAndEnabledStateFromTheSteps(): void
    {
        $steps = [
            ['id' => 's1', 'type' => 'cron', 'name' => '  Nightly sync ', 'config' => ['enabled' => true]],
            ['id' => 's2', 'type' => 'logger', 'name' => 'Log'],
        ];
        $this->acceptSteps(null);
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->with(['name' => 'Nightly sync'])->willReturn(null);
        $this->archiver->expects(self::never())->method('archiveIfExecuted');

        $response = $this->controller->apiCreateAction(self::post(['steps' => $steps, 'design' => ['version' => 2], 'name' => 'ignored', 'type' => 'native', 'enabled' => false]));

        $flow = self::decode($response)['flow'];
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Nightly sync', 'flow', true], [$flow['name'], $flow['type'], $flow['enabled']]);
        self::assertSame(['version' => 2], $flow['design']);
        self::assertCount(1, $this->kit->orm->persisted);
        self::assertSame(1, $this->kit->orm->flushes);
        $saved = $this->kit->orm->persisted[0];
        self::assertSame('cron', $saved->getTriggerType());
        self::assertGreaterThanOrEqual(time() - 5, $saved->getLastModified()->getTimestamp());
        self::assertSame('UTC', $saved->getLastModified()->getTimezone()->getName());
    }

    public function testAFlowWithoutATriggerIsADisabledSubflowKeepingItsStoredName(): void
    {
        $this->acceptSteps(null);
        $existing = self::flow(4, 'Stored name');
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->willReturn($existing);

        $response = $this->controller->apiUpdateAction($existing, self::post(['steps' => [['id' => 's1', 'type' => 'logger', 'name' => 'Log']]], 'PUT'));

        $flow = self::decode($response)['flow'];
        self::assertSame(['Stored name', 'subflow', false], [$flow['name'], $flow['type'], $flow['enabled']]);
        self::assertNull($existing->getTriggerType());
    }

    public function testAnEmptyStepListClearsTheStepsAndABrandNewFlowGetsAPlaceholderName(): void
    {
        $this->acceptSteps([]);
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->willReturn(null);

        $flow = self::decode($this->controller->apiCreateAction(self::post(['steps' => []])))['flow'];

        self::assertNull($flow['steps']);
        self::assertMatchesRegularExpression('/^new_flow_[0-9a-f]{6}$/', $flow['name']);
    }

    public function testASavePayloadWithoutStepsOrDesignLeavesThemAlone(): void
    {
        $this->validator->expects(self::never())->method('normalize');
        $existing = self::flow(4, 'Keep', OntologyFlow::TYPE_FLOW, [['type' => 'cron', 'name' => 'Keep', 'config' => ['enabled' => true]]])->setDesign(['version' => 2]);
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->willReturn($existing);

        $flow = self::decode($this->controller->apiUpdateAction($existing, self::post(['name' => 'x'], 'PUT')))['flow'];

        self::assertSame(['Keep', ['version' => 2]], [$flow['name'], $flow['design']]);
        self::assertSame('cron', $flow['steps'][0]['type']);
    }

    public function testAnExecutedRevisionIsArchivedBeforeItIsReplaced(): void
    {
        $this->acceptSteps(null);
        $oldSteps = [['type' => 'cron', 'name' => 'Old', 'config' => ['enabled' => true]]];
        $existing = self::flow(4, 'Old', OntologyFlow::TYPE_FLOW, $oldSteps)->setDesign(['version' => 2, 'old' => true]);
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->willReturn($existing);
        $newSteps = [['id' => 's1', 'type' => 'cron', 'name' => 'New', 'config' => ['enabled' => true]]];
        $this->archiver->expects(self::once())->method('archiveIfExecuted')->with(
            4,
            'Old',
            $oldSteps,
            ['version' => 2, 'old' => true],
            $newSteps,
            ['version' => 2, 'new' => true]
        );

        $this->controller->apiUpdateAction($existing, self::post(['steps' => $newSteps, 'design' => ['version' => 2, 'new' => true]], 'PUT'));
    }

    /**
     * @dataProvider rejectedSaveProvider
     *
     * @param array<string, mixed>|string $payload
     */
    public function testSaveRejections(array|string $payload, int $status, string $message, ?array $normalized = null, array $structural = []): void
    {
        $this->validator->method('normalize')->willReturn($normalized);
        $this->validator->method('structuralErrors')->willReturn($structural);
        $taken = self::flow(9, 'Nightly');
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->willReturn($taken);

        $response = $this->controller->apiCreateAction(self::post($payload));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => $message], self::decode($response));
        self::assertSame([], $this->kit->orm->persisted);
    }

    /**
     * @return array<string, array{array<string, mixed>|string, int, string, 4?: array<int, mixed>|null, 5?: array<int, string>}>
     */
    public static function rejectedSaveProvider(): array
    {
        return [
            'not json' => ['{oops', 400, 'Invalid payload.'],
            'design is a scalar' => [['design' => 'canvas'], 400, 'Invalid design.'],
            'steps the validator cannot normalize' => [['steps' => 'nope'], 400, 'Invalid steps.', null],
            'a structural problem blocks the save' => [['steps' => [['a']]], 422, 'Step names must be unique.', [['a']], ['Step names must be unique.', 'second']],
            'the derived name is taken' => [['steps' => [['type' => 'cron', 'name' => 'Nightly']]], 422, 'aaxis.ontology.flow_manager.name_unique', [['type' => 'cron', 'name' => 'Nightly']], []],
        ];
    }

    public function testANullDesignClearsIt(): void
    {
        $existing = self::flow(4, 'Keep')->setDesign(['version' => 2]);
        $this->kit->orm->repository(OntologyFlow::class)->method('findOneBy')->willReturn($existing);

        $flow = self::decode($this->controller->apiUpdateAction($existing, self::post(['design' => null], 'PUT')))['flow'];

        self::assertNull($flow['design']);
    }

    public function testBuiltInFlowsCannotBeUpdatedOrDeleted(): void
    {
        $native = self::flow(1, 'Manual', OntologyFlow::TYPE_NATIVE);

        $update = $this->controller->apiUpdateAction($native, self::post(['steps' => []], 'PUT'));
        $delete = $this->controller->deleteAction($native);

        self::assertSame(422, $update->getStatusCode());
        self::assertSame('aaxis.ontology.flow_manager.edit_builtin_forbidden', self::decode($update)['message']);
        self::assertSame(422, $delete->getStatusCode());
        self::assertSame('aaxis.ontology.flow_manager.delete_builtin_forbidden', self::decode($delete)['message']);
        self::assertSame([], $this->kit->orm->removed);
        self::assertSame([], $this->kit->orm->persisted);
    }

    public function testDeletingAUserFlow(): void
    {
        $flow = self::flow(5, 'Mine');

        self::assertSame(['successful' => true], self::decode($this->controller->deleteAction($flow)));
        self::assertSame([$flow], $this->kit->orm->removed);
    }

    // --- history, export, import --------------------------------------------------------------

    public function testTheHistoryListsArchivedRevisionsNewestFirst(): void
    {
        $this->kit->orm->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('FROM aaxis_ontology_flow_history'), [5])
            ->willReturn([
                ['version' => '2', 'name' => 'B', 'steps' => '[{"type":"cron"}]', 'design' => '{"version":2}', 'last_executed' => '2026-03-04 05:06:07', 'archived_at' => '2026-03-05 00:00:00'],
                ['version' => 1, 'name' => 'A', 'steps' => null, 'design' => 'not json', 'last_executed' => null, 'archived_at' => '2026-03-01 00:00:00'],
            ]);

        $versions = self::decode($this->controller->historyAction(self::flow(5, 'X')))['versions'];

        self::assertSame(
            ['version' => 2, 'name' => 'B', 'steps' => [['type' => 'cron']], 'design' => ['version' => 2], 'lastExecuted' => '2026-03-04T05:06:07+00:00', 'archivedAt' => '2026-03-05T00:00:00+00:00'],
            $versions[0]
        );
        self::assertSame(['version' => 1, 'name' => 'A', 'steps' => null, 'design' => null, 'lastExecuted' => null, 'archivedAt' => '2026-03-01T00:00:00+00:00'], $versions[1]);
    }

    public function testExportingAFlowReturnsTheDocumentAndAFilesystemFriendlyName(): void
    {
        $flow = self::flow(5, 'Nightly / Sync (v2)!');
        $this->portability->expects(self::once())->method('export')->with($flow)->willReturn(['format' => 'x']);

        $body = self::decode($this->controller->exportAction($flow));

        self::assertSame(['success' => true, 'document' => ['format' => 'x'], 'filename' => 'flow-nightly-sync-v2.json'], $body);
    }

    public function testAFlowWhoseNameHasNoLettersExportsAsFlowExport(): void
    {
        $this->portability->method('export')->willReturn([]);

        self::assertSame('flow-export.json', self::decode($this->controller->exportAction(self::flow(5, '***')))['filename']);
    }

    public function testBuiltInFlowsAreNotExported(): void
    {
        $this->portability->expects(self::never())->method('export');

        $response = $this->controller->exportAction(self::flow(1, 'Manual', OntologyFlow::TYPE_NATIVE));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'errors' => ['aaxis.ontology.flow_portability.export_builtin_forbidden']], self::decode($response));
    }

    public function testAnExportProblemIsReportedInFull(): void
    {
        $this->portability->method('export')->willThrowException(new FlowImportException(['First problem.', 'Second problem.']));

        $response = $this->controller->exportAction(self::flow(5, 'X'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'errors' => ['First problem.', 'Second problem.']], self::decode($response));
    }

    public function testImportingADocumentCreatesTheFlow(): void
    {
        $document = ['format' => 'aaxis-flow', 'steps' => []];
        $imported = self::flow(8, 'Imported');
        $this->portability->expects(self::once())->method('import')->with($document)->willReturn($imported);

        $response = $this->controller->importAction(self::post(['document' => json_encode($document)]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'flow' => self::decode($response)['flow']], self::decode($response));
        self::assertSame('Imported', self::decode($response)['flow']['name']);
    }

    /**
     * @dataProvider invalidImportProvider
     */
    public function testImportRejectsWhatIsNotAFlowDocument(mixed $body, string $error): void
    {
        $this->portability->expects(self::never())->method('import');

        $response = $this->controller->importAction(self::post($body));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'errors' => [$error]], self::decode($response));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidImportProvider(): array
    {
        $tooLarge = json_encode(['document' => '"' . str_repeat('x', 2 * 1024 * 1024) . '"'], JSON_THROW_ON_ERROR);

        return [
            'body not json' => ['{oops', 'aaxis.ontology.flow_portability.invalid_format'],
            'no document' => [['other' => 1], 'aaxis.ontology.flow_portability.invalid_format'],
            'document not a string' => [['document' => ['a' => 1]], 'aaxis.ontology.flow_portability.invalid_format'],
            'blank document' => [['document' => "  \n"], 'aaxis.ontology.flow_portability.invalid_format'],
            'document not json' => [['document' => '{oops'], 'aaxis.ontology.flow_portability.invalid_format'],
            'document nested too deep' => [['document' => str_repeat('[', 70) . str_repeat(']', 70)], 'aaxis.ontology.flow_portability.invalid_format'],
            'document too large' => [$tooLarge, 'aaxis.ontology.flow_portability.too_large'],
        ];
    }

    public function testAnImportProblemIsReportedInFull(): void
    {
        $this->portability->method('import')->willThrowException(new FlowImportException(['Duplicate name.', 'Unknown connector "X".']));

        $response = $this->controller->importAction(self::post(['document' => '{"a":1}']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'errors' => ['Duplicate name.', 'Unknown connector "X".']], self::decode($response));
    }

    // --- Run Now ------------------------------------------------------------------------------

    public function testRunNowExecutesTheEditorStateAndStoresTheFinalContext(): void
    {
        $this->kit->user = $this->user('Jane', 'Doe', 'jane@example.com');
        $output = ['flowUuid' => self::UUID, 'payload' => [1]];
        $this->executor->expects(self::once())->method('execute')->with(
            [
                ['id' => 's1', 'type' => 'cron', 'name' => 'Nightly', 'config' => ['enabled' => true]],
                ['id' => 's2', 'type' => 'logger', 'name' => 'Log', 'config' => null],
            ],
            [['from' => 's1', 'fromPort' => 0, 'to' => 's2']],
            ['x' => 1],
            null,
            null,
            null,
            ['trigger' => 'debug', 'user' => ['name' => 'Jane Doe', 'email' => 'jane@example.com']]
        )->willReturn($output);
        $this->executor->method('lastExecutedIds')->willReturn(['s1', 's2']);

        $response = $this->controller->debugAction(self::post(self::debugPayload()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'output' => $output, 'truncated' => [], 'executedIds' => ['s1', 's2'], 'contextKey' => self::UUID], self::decode($response));
        self::assertSame($output, $this->cache->getItem(self::CACHE_KEY)->get());
    }

    public function testRunNowShipsTheVariablesPaneAPreviewButStoresTheWholeContext(): void
    {
        $output = ['flowUuid' => self::UUID, 'rows' => range(1, 250)];
        $this->executor->method('execute')->willReturn($output);
        $this->executor->method('lastExecutedIds')->willReturn([]);

        $body = self::decode($this->controller->debugAction(self::post(self::debugPayload())));

        self::assertSame(range(1, 100), $body['output']['rows']);
        self::assertSame(['/rows' => 250], $body['truncated']);
        self::assertSame($output, $this->cache->getItem(self::CACHE_KEY)->get());
    }

    public function testARunWhoseContextHasNoUuidStoresNothing(): void
    {
        $this->executor->method('execute')->willReturn(['payload' => 1]);
        $this->executor->method('lastExecutedIds')->willReturn([]);

        $body = self::decode($this->controller->debugAction(self::post(self::debugPayload())));

        self::assertNull($body['contextKey']);
        self::assertFalse($this->cache->hasItem(self::CACHE_KEY));
    }

    public function testTheRunInfoFallsBackToTheUserIdentifier(): void
    {
        $this->kit->user = $this->user('', '', '', 'svc');
        $this->executor->expects(self::once())->method('execute')->with(
            self::anything(), self::anything(), self::anything(), null, null, null,
            ['trigger' => 'debug', 'user' => ['name' => 'svc', 'email' => '']]
        )->willReturn([]);
        $this->executor->method('lastExecutedIds')->willReturn([]);

        $this->controller->debugAction(self::post(self::debugPayload()));
    }

    public function testAFailedStepPaintsTheTrailOnTheCanvas(): void
    {
        $this->executor->method('execute')->willThrowException(new FlowStepFailure('Step "Log": boom', 's2', ['s1']));

        $response = $this->controller->debugAction(self::post(self::debugPayload()));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Step "Log": boom', 'failedStepId' => 's2', 'executedIds' => ['s1']], self::decode($response));
    }

    public function testAnyOtherRuntimeFailureIsA422WithItsMessage(): void
    {
        $this->executor->method('execute')->willThrowException(new \RuntimeException('no trigger'));

        $response = $this->controller->debugAction(self::post(self::debugPayload()));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'no trigger'], self::decode($response));
    }

    // --- the shared debug payload -------------------------------------------------------------

    /**
     * @dataProvider invalidDebugPayloadProvider
     *
     * @param array<string, mixed>|string $payload
     */
    public function testBothDebugEndpointsRejectMalformedPayloads(array|string $payload, int $status, string $message): void
    {
        $this->executor->expects(self::never())->method('execute');
        $this->executor->expects(self::never())->method('debugWalk');

        foreach (['debugAction', 'debugStepAction'] as $action) {
            $response = $this->controller->$action(self::post($payload));

            self::assertSame($status, $response->getStatusCode(), $action);
            self::assertSame(['success' => false, 'message' => $message], self::decode($response), $action);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>|string, int, string}>
     */
    public static function invalidDebugPayloadProvider(): array
    {
        $step = ['id' => 's1', 'type' => 'cron', 'name' => 'N'];

        return [
            'not json' => ['{oops', 400, 'Invalid payload.'],
            'a step that is not an object' => [['steps' => ['nope']], 400, 'Invalid debug steps.'],
            'a step without an id' => [['steps' => [['type' => 'cron', 'name' => 'N']]], 400, 'Invalid debug steps.'],
            'a blank id' => [['steps' => [['id' => ''] + $step]], 400, 'Invalid debug steps.'],
            'a repeated id' => [['steps' => [$step, $step]], 400, 'Invalid debug steps.'],
            'an unknown step type' => [['steps' => [['type' => 'teleport'] + $step]], 400, 'Invalid debug steps.'],
            'a step name that is not a string' => [['steps' => [['name' => 5] + $step]], 400, 'Invalid debug steps.'],
            'a config that is a list' => [['steps' => [$step + ['config' => [1, 2]]]], 400, 'Invalid debug steps.'],
            'a config that is a scalar' => [['steps' => [$step + ['config' => 'x']]], 400, 'Invalid debug steps.'],
            'a link that is not an object' => [['steps' => [$step], 'links' => ['nope']], 400, 'Invalid debug links.'],
            'a link to nowhere' => [['steps' => [$step], 'links' => [['from' => 's1', 'fromPort' => 0, 'to' => 'ghost']]], 400, 'Invalid debug links.'],
            'a link from nowhere' => [['steps' => [$step], 'links' => [['from' => 'ghost', 'fromPort' => 0, 'to' => 's1']]], 400, 'Invalid debug links.'],
            'a link port that is not an int' => [['steps' => [$step], 'links' => [['from' => 's1', 'fromPort' => '0', 'to' => 's1']]], 400, 'Invalid debug links.'],
        ];
    }

    public function testTheInputIsAnObjectOrEmptyAndStepConfigsAreNormalized(): void
    {
        $captured = [];
        $this->executor->method('execute')->willReturnCallback(function (array $steps, array $links, array $input) use (&$captured): array {
            $captured = [$steps, $links, $input];

            return [];
        });
        $this->executor->method('lastExecutedIds')->willReturn([]);

        $this->controller->debugAction(self::post([
            'steps' => [['id' => 's1', 'type' => 'cron', 'name' => 'N', 'extra' => 'dropped']],
            'links' => [['from' => 's1', 'fromPort' => 1, 'to' => 's1', 'extra' => 'dropped']],
            'input' => [1, 2, 3],
        ]));

        self::assertSame([[['id' => 's1', 'type' => 'cron', 'name' => 'N', 'config' => null]], [['from' => 's1', 'fromPort' => 1, 'to' => 's1']], []], $captured);
    }

    public function testWritersAreStampedWithTheFlowBeingDebugged(): void
    {
        $flow = self::flow(5, 'Mine');
        $this->kit->orm->repository(OntologyFlow::class)->expects(self::once())->method('find')->with(5)->willReturn($flow);
        $this->executor->expects(self::once())->method('execute')->with(self::anything(), self::anything(), self::anything(), $flow)->willReturn([]);
        $this->executor->method('lastExecutedIds')->willReturn([]);

        $this->controller->debugAction(self::post(self::debugPayload(['flowId' => 5])));
    }

    /**
     * @dataProvider unknownFlowProvider
     */
    public function testADebuggedFlowThatDoesNotExistIsRefused(mixed $flowId): void
    {
        $this->kit->orm->repository(OntologyFlow::class)->method('find')->willReturn(null);
        $this->executor->expects(self::never())->method('execute');

        $response = $this->controller->debugAction(self::post(self::debugPayload(['flowId' => $flowId])));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'The flow being debugged no longer exists.'], self::decode($response));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unknownFlowProvider(): array
    {
        return ['deleted' => [99], 'not an integer' => ['5'], 'a float' => [5.5]];
    }

    // --- step-by-step debugger ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function walkResult(): array
    {
        return [
            'blob' => ['context' => ['flowUuid' => self::UUID, 'x' => 1], 'frames' => [['flowId' => 5, 'flowName' => 'Mine']], 'done' => false],
            'response' => ['step' => 's1', 'index' => 0, 'total' => 2, 'done' => false, 'output' => ['x' => 1]],
        ];
    }

    public function testTheFirstStepStartsAFreshSessionAndStoresItsWalkState(): void
    {
        $this->kit->user = $this->user('Jane', 'Doe', 'j@x.io');
        $this->executor->expects(self::once())->method('debugWalk')->with(
            self::anything(),
            self::anything(),
            ['x' => 1],
            null,
            null,
            false,
            ['trigger' => 'debug', 'user' => ['name' => 'Jane Doe', 'email' => 'j@x.io']],
            false,
            false
        )->willReturn(self::walkResult());

        $response = $this->controller->debugStepAction(self::post(self::debugPayload()));

        self::assertSame(200, $response->getStatusCode());
        $body = self::decode($response);
        self::assertSame(['success' => true, 'contextKey' => self::UUID, 'step' => 's1', 'index' => 0, 'total' => 2, 'done' => false], array_intersect_key($body, array_flip(['success', 'contextKey', 'step', 'index', 'total', 'done'])));
        $stored = $this->cache->getItem(self::CACHE_KEY)->get();
        self::assertSame(self::walkResult()['blob']['frames'], $stored['frames']);
        self::assertEqualsWithDelta(time(), $stored['lastTickAt'], 5, 'every tick refreshes the inactivity clock');
    }

    public function testAStepCarriesTheBreakpointsAndShipsAPreviewOfTheContext(): void
    {
        $result = self::walkResult();
        $result['response']['context'] = ['rows' => range(1, 150)];
        $this->executor->expects(self::once())->method('debugWalk')->with(
            self::anything(), self::anything(), self::anything(), null, null, true, self::anything(), false, false,
            // Malformed entries are dropped.
            ['root' => ['s2'], '20' => ['a', 'b']]
        )->willReturn($result);

        $body = self::decode($this->controller->debugStepAction(self::post(self::debugPayload([
            'runAll' => true,
            'breakpoints' => ['root' => ['s2', '', 3], '20' => ['a', 'b'], '21' => 'nope', '22' => []],
        ]))));

        self::assertCount(100, $body['context']['rows']);
        self::assertSame(['/rows' => 150], $body['truncated']);
    }

    public function testTheModeFlagsAreOnlyHonouredWhenExactlyTrue(): void
    {
        $this->executor->expects(self::once())->method('debugWalk')->with(
            self::anything(), self::anything(), self::anything(), null, null, true, self::anything(), true, true
        )->willReturn(self::walkResult());
        $this->controller->debugStepAction(self::post(self::debugPayload(['runAll' => true, 'stepInto' => true, 'stepOut' => true])));

        $lax = $this->createMock(FlowDebugExecutor::class);
        $lax->expects(self::once())->method('debugWalk')->with(
            self::anything(), self::anything(), self::anything(), null, null, false, self::anything(), false, false
        )->willReturn(self::walkResult());
        $this->kit->set(FlowDebugExecutor::class, $lax);
        $this->controller->debugStepAction(self::post(self::debugPayload(['runAll' => 1, 'stepInto' => 'yes', 'stepOut' => 'true'])));
    }

    public function testASubsequentStepResumesTheStoredWalk(): void
    {
        $blob = ['context' => ['flowUuid' => self::UUID], 'frames' => [['flowId' => 5, 'flowName' => 'Mine']], 'done' => false, 'lastTickAt' => time() - 30];
        $this->storeBlob($blob);
        $flow = self::flow(5, 'Mine');
        $this->kit->orm->repository(OntologyFlow::class)->method('find')->willReturn($flow);
        $this->executor->expects(self::once())->method('debugWalk')->with(self::anything(), self::anything(), self::anything(), $blob, $flow, false, self::anything(), false, false)->willReturn(self::walkResult());

        $response = $this->controller->debugStepAction(self::post(self::debugPayload(['contextKey' => strtoupper(self::UUID), 'flowId' => 5])));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @dataProvider unresumableSessionProvider
     *
     * @param array<string, mixed>|null $stored
     */
    public function testAnUnresumableSessionIsRefused(mixed $contextKey, ?array $stored, string $message): void
    {
        if ($stored !== null) {
            $this->storeBlob($stored);
        }
        $this->executor->expects(self::never())->method('debugWalk');

        $response = $this->controller->debugStepAction(self::post(self::debugPayload(['contextKey' => $contextKey])));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => $message], self::decode($response));
    }

    /**
     * @return array<string, array{mixed, array<string, mixed>|null, string}>
     */
    public static function unresumableSessionProvider(): array
    {
        $expired = 'The debug session expired — restart the debug.';

        return [
            'nothing stored under the key' => [self::UUID, null, $expired],
            'a key that is not a uuid' => ['../../etc/passwd', null, $expired],
            'a legacy plain context (no frames)' => [self::UUID, ['flowUuid' => self::UUID], $expired],
            'a session ended by timeout' => [self::UUID, ['context' => [], 'frames' => [[]], 'terminated' => 'debug-timeout'], 'The debug session was terminated by timeout — restart the debug.'],
            'a session the user stopped' => [self::UUID, ['context' => [], 'frames' => [[]], 'terminated' => 'debug-stop'], 'The debug session was stopped — restart the debug.'],
        ];
    }

    public function testAnIdleSessionIsTerminatedWithAFlowExceptionEvent(): void
    {
        $blob = ['context' => ['flowUuid' => self::UUID], 'frames' => [['flowId' => 5, 'flowName' => 'Mine']], 'done' => false, 'lastTickAt' => time() - 3600];
        $this->storeBlob($blob);
        $this->executor->expects(self::never())->method('debugWalk');
        $this->events->expects(self::once())->method('emitRaw')->with(5, 'Mine', self::UUID, 'flow-exception', ['message' => 'debug-timeout']);

        $response = $this->controller->debugStepAction(self::post(self::debugPayload(['contextKey' => self::UUID])));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'The debug session was terminated by timeout — restart the debug.'], self::decode($response));
        // The walk blob is marked terminated so the next call is refused without a second event.
        self::assertSame('debug-timeout', $this->cache->getItem(self::CACHE_KEY)->get()['terminated']);
    }

    public function testAnIdleSessionWithoutAFlowStillClosesItsRun(): void
    {
        $this->storeBlob(['context' => ['flowUuid' => self::UUID], 'frames' => [[]], 'lastTickAt' => 1]);
        $this->events->expects(self::once())->method('emitRaw')->with(null, null, self::UUID, 'flow-exception', ['message' => 'debug-timeout']);

        self::assertSame(422, $this->controller->debugStepAction(self::post(self::debugPayload(['contextKey' => self::UUID])))->getStatusCode());
    }

    /**
     * @dataProvider noTimeoutProvider
     */
    public function testTheInactivityTimeoutCanBeSwitchedOffAndNeedsATick(mixed $minutes, ?int $lastTickAt): void
    {
        $this->config['aaxis_ontology.flow_debug_timeout_minutes'] = $minutes;
        $blob = ['context' => ['flowUuid' => self::UUID], 'frames' => [[]], 'done' => false] + ($lastTickAt !== null ? ['lastTickAt' => $lastTickAt] : []);
        $this->storeBlob($blob);
        $this->events->expects(self::never())->method('emitRaw');
        $this->executor->expects(self::once())->method('debugWalk')->willReturn(self::walkResult());

        self::assertSame(200, $this->controller->debugStepAction(self::post(self::debugPayload(['contextKey' => self::UUID])))->getStatusCode());
    }

    /**
     * @return array<string, array{mixed, int|null}>
     */
    public static function noTimeoutProvider(): array
    {
        return [
            'timeout off' => [0, 1],
            'negative timeout' => [-5, 1],
            'unset timeout' => [null, 1],
            'a session that never ticked' => [10, null],
        ];
    }

    public function testAStepFailureNamesTheStepAndTheSubflowTrails(): void
    {
        $this->executor->method('debugWalk')->willThrowException(new FlowStepFailure('Step "Log": boom', 's2', ['s1']));
        $this->executor->method('subflowTrails')->willReturn(['sf1' => ['a', 'b']]);

        $response = $this->controller->debugStepAction(self::post(self::debugPayload()));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['success' => false, 'message' => 'Step "Log": boom', 'failedStepId' => 's2', 'executedIds' => ['s1'], 'subflowTrails' => ['sf1' => ['a', 'b']]],
            self::decode($response)
        );
    }

    public function testAFailedTickMarksTheResumedWalkSoAStopCannotCloseTheRunTwice(): void
    {
        $this->storeBlob(['context' => ['flowUuid' => self::UUID], 'frames' => [['flowId' => 5]], 'done' => false, 'lastTickAt' => time()]);
        $this->executor->method('debugWalk')->willThrowException(new FlowStepFailure('Step "Log": boom', 's2', ['s1']));

        self::assertSame(422, $this->controller->debugStepAction(self::post(self::debugPayload(['contextKey' => self::UUID])))->getStatusCode());
        self::assertSame('failed', $this->cache->getItem(self::CACHE_KEY)->get()['terminated']);

        $this->events->expects(self::never())->method('emitRaw');
        self::assertSame(['success' => true, 'stopped' => false], self::decode($this->controller->debugStopAction(self::post(['contextKey' => self::UUID]))));
    }

    public function testStoppingAnOpenWalkClosesItsRunWithADebugStopEvent(): void
    {
        $this->storeBlob(['context' => ['flowUuid' => self::UUID], 'frames' => [['flowId' => 5, 'flowName' => 'Mine'], ['flowId' => 9]], 'done' => false]);
        $this->events->expects(self::once())->method('emitRaw')->with(5, 'Mine', self::UUID, 'flow-exception', ['message' => 'debug-stop']);

        $response = $this->controller->debugStopAction(self::post(['contextKey' => self::UUID]));

        self::assertSame(['success' => true, 'stopped' => true], self::decode($response));
        self::assertSame('debug-stop', $this->cache->getItem(self::CACHE_KEY)->get()['terminated']);
        // A second stop (the stop button, then leaving the page) emits nothing more.
        self::assertSame(['success' => true, 'stopped' => false], self::decode($this->controller->debugStopAction(self::post(['contextKey' => self::UUID]))));
    }

    /**
     * @dataProvider unstoppableProvider
     *
     * @param array<string, mixed>|null $stored
     */
    public function testStopLeavesClosedOrUnknownSessionsAlone(mixed $body, ?array $stored): void
    {
        if ($stored !== null) {
            $this->storeBlob($stored);
        }
        $this->events->expects(self::never())->method('emitRaw');

        $response = $this->controller->debugStopAction(self::post($body));

        self::assertSame(['success' => true, 'stopped' => false], self::decode($response));
    }

    /**
     * @return array<string, array{mixed, array<string, mixed>|null}>
     */
    public static function unstoppableProvider(): array
    {
        $walk = ['context' => ['flowUuid' => self::UUID], 'frames' => [['flowId' => 5]]];

        return [
            'a finished walk' => [['contextKey' => self::UUID], $walk + ['done' => true]],
            'a timed-out walk' => [['contextKey' => self::UUID], $walk + ['terminated' => 'debug-timeout']],
            'Run Now (a plain context)' => [['contextKey' => self::UUID], ['flowUuid' => self::UUID]],
            'nothing stored' => [['contextKey' => self::UUID], null],
            'a key that is not a uuid' => [['contextKey' => '../x'], null],
            'no key' => [[], null],
            'not an object' => ['nope', null],
        ];
    }

    public function testAnyOtherWalkFailureIsA422(): void
    {
        $this->executor->method('debugWalk')->willThrowException(new \RuntimeException('walk failed'));

        $response = $this->controller->debugStepAction(self::post(self::debugPayload()));

        self::assertSame(['success' => false, 'message' => 'walk failed'], self::decode($response));
    }

    public function testAWalkWhoseContextHasNoUuidHasNoKeyToResumeWith(): void
    {
        $result = self::walkResult();
        $result['blob']['context'] = ['x' => 1];
        $this->executor->method('debugWalk')->willReturn($result);

        self::assertNull(self::decode($this->controller->debugStepAction(self::post(self::debugPayload())))['contextKey']);
    }

    // --- the debugger's evaluator -------------------------------------------------------------

    public function testTheEvaluatorRunsAnExpressionAgainstTheSessionsCurrentVariables(): void
    {
        $this->storeBlob(['context' => ['flowUuid' => self::UUID, 'total' => 3], 'frames' => [[]], 'done' => false]);
        $this->dwl->expects(self::once())->method('transform')->with('total * 2', ['flowUuid' => self::UUID, 'total' => 3])->willReturn(6);

        $response = $this->controller->debugEvalAction(self::post(['expression' => '  total * 2 ', 'contextKey' => self::UUID]));

        self::assertSame(['success' => true, 'output' => 6], self::decode($response));
    }

    public function testTheEvaluatorAlsoReadsALegacyPlainContext(): void
    {
        $this->storeBlob(['flowUuid' => self::UUID, 'total' => 3]);
        $this->dwl->expects(self::once())->method('transform')->with('total', ['flowUuid' => self::UUID, 'total' => 3])->willReturn(3);

        self::assertTrue(self::decode($this->controller->debugEvalAction(self::post(['expression' => 'total', 'contextKey' => self::UUID])))['success']);
    }

    /**
     * @dataProvider unusableEvaluationProvider
     */
    public function testTheEvaluatorRefusesWhatItCannotEvaluate(mixed $body, int $status, string $message): void
    {
        $this->dwl->expects(self::never())->method('transform');

        $response = $this->controller->debugEvalAction(self::post($body));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => $message], self::decode($response));
    }

    /**
     * @return array<string, array{mixed, int, string}>
     */
    public static function unusableEvaluationProvider(): array
    {
        $expired = 'The debug session expired — restart the debug.';

        return [
            'not json' => ['{oops', 400, 'Invalid payload.'],
            'no expression' => [['contextKey' => self::UUID], 400, 'Invalid payload.'],
            'blank expression' => [['expression' => '  ', 'contextKey' => self::UUID], 400, 'Invalid payload.'],
            'expression not a string' => [['expression' => 5], 400, 'Invalid payload.'],
            'no session key' => [['expression' => 'x'], 422, $expired],
            'unknown session' => [['expression' => 'x', 'contextKey' => self::UUID], 422, $expired],
            'a session key that is not a uuid' => [['expression' => 'x', 'contextKey' => 'nope'], 422, $expired],
        ];
    }

    public function testAnExpressionErrorIsA422WithItsMessage(): void
    {
        $this->storeBlob(['context' => ['flowUuid' => self::UUID], 'frames' => [[]]]);
        $this->dwl->method('transform')->willThrowException(new \RuntimeException('Unknown variable "nope"'));

        $response = $this->controller->debugEvalAction(self::post(['expression' => 'nope', 'contextKey' => self::UUID]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Unknown variable "nope"'], self::decode($response));
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyFlowController::getSubscribedServices();

        foreach ([FlowDebugExecutor::class, FlowPortability::class, FlowStepValidator::class, FlowHistoryArchiver::class, OntologyFlowEventEmitter::class, PhpMethodInvoker::class, DwlTransformer::class, CacheItemPoolInterface::class, ConfigManager::class] as $service) {
            self::assertContains($service, $services);
        }
    }
}
