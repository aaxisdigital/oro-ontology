<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyDataPlaygroundController;
use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDwlScript;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\DwlOutputFormatter;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptGuard;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptManager;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class OntologyDataPlaygroundControllerTest extends TestCase
{
    private const string VIEW_DATA = 'VIEW|entity:' . OntologyData::class;
    private const string VIEW_ENTITIES = 'VIEW|entity:' . OntologyEntity::class;
    private const string KEY = 'aaxis.ontology.data_playground.';

    private ControllerKit $kit;
    private DwlScriptManager&MockObject $scripts;
    private DwlTransformer&MockObject $dwl;
    private DwlScriptGuard&MockObject $guard;
    private OntologyDataPlaygroundController $controller;
    private User $user;

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->scripts = $this->createMock(DwlScriptManager::class);
        $this->scripts->method('serialize')->willReturnCallback(
            static fn (OntologyDwlScript $script, User $user): array => ['name' => $script->getName(), 'viewer' => $user->getId()]
        );
        $this->dwl = $this->createMock(DwlTransformer::class);
        $this->guard = $this->createMock(DwlScriptGuard::class);
        $store = $this->createMock(BucketEntityDataStore::class);
        $store->method('isEnabled')->willReturn(false);
        $this->kit->set(DwlScriptManager::class, $this->scripts);
        $this->kit->set(DwlTransformer::class, $this->dwl);
        $this->kit->set(DwlScriptGuard::class, $this->guard);
        $this->kit->set(BucketEntityDataStore::class, $store);
        $this->kit->set(DwlOutputFormatter::class, new DwlOutputFormatter());
        $this->controller = $this->kit->wire(new OntologyDataPlaygroundController());
        $this->user = self::user(3);
        $this->kit->user = $this->user;
    }

    private static function user(int $id): User
    {
        $user = (new User())->setUsername('u' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function entity(int $id, string $name, ?string $system): OntologyEntity
    {
        $entity = (new OntologyEntity())->setName($name)->setSystem($system !== null ? (new OntologySystem())->setName($system) : null);
        (new \ReflectionProperty(OntologyEntity::class, 'id'))->setValue($entity, $id);

        return $entity;
    }

    private static function post(mixed $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], \is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testThePageRendersFromTheTemplate(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    // --- list -------------------------------------------------------------------------------

    public function testTheListServesTheVisibleScriptsAndThePickerEntities(): void
    {
        $this->scripts->method('visibleTo')->with($this->user)->willReturn([(new OntologyDwlScript())->setName('a')]);
        $this->kit->orm->repository(OntologyEntity::class)->method('findBy')->willReturn([
            self::entity(1, 'order', 'ERP'), self::entity(2, 'Account', 'erp'), self::entity(3, 'Batch', 'CRM'), self::entity(4, 'loose', null),
        ]);
        $this->kit->granted = [self::VIEW_ENTITIES];

        $body = ControllerKit::decode($this->controller->listAction());

        self::assertSame([['name' => 'a', 'viewer' => 3]], $body['scripts']);
        // By system, then name (case-insensitive); an entity without a system first.
        self::assertSame(
            [['id' => 4, 'name' => 'loose', 'system' => null], ['id' => 3, 'name' => 'Batch', 'system' => 'CRM'],
                ['id' => 2, 'name' => 'Account', 'system' => 'erp'], ['id' => 1, 'name' => 'order', 'system' => 'ERP']],
            $body['entities']
        );
    }

    public function testWithoutEntityAccessThePickerIsEmpty(): void
    {
        $this->scripts->method('visibleTo')->willReturn([]);

        self::assertSame(['scripts' => [], 'entities' => []], ControllerKit::decode($this->controller->listAction()));
    }

    public function testListingNeedsAnOroUser(): void
    {
        $this->kit->user = null;

        self::assertSame(403, $this->controller->listAction()->getStatusCode());
    }

    // --- create / update / delete ------------------------------------------------------------

    public function testCreateSavesANewScriptOwnedByTheUser(): void
    {
        $saved = null;
        $this->scripts->expects(self::once())->method('save')->willReturnCallback(
            function (OntologyDwlScript $script, array $body) use (&$saved): ?string {
                $saved = $script;
                $script->setName($body['name']);

                return null;
            }
        );

        $response = $this->controller->createAction(self::post(['name' => 'Totals']));

        self::assertSame(['success' => true, 'script' => ['name' => 'Totals', 'viewer' => 3]], ControllerKit::decode($response));
        self::assertSame($this->user, $saved->getOwner());
    }

    public function testARefusedSaveIsA422WithTheReason(): void
    {
        $this->scripts->method('save')->willReturn('name_taken');

        $response = $this->controller->createAction(self::post(['name' => 'Totals']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => self::KEY . 'name_taken'], ControllerKit::decode($response));
    }

    public function testATooLongNameNamesTheMaximum(): void
    {
        $this->scripts->method('save')->willReturn('name_too_long');

        $body = ControllerKit::decode($this->controller->createAction(self::post(['name' => 'x'])));

        self::assertSame(self::KEY . 'name_too_long|{"%max%":128}', $body['message']);
    }

    public function testABodyThatIsNotAnObjectIsA400(): void
    {
        $this->scripts->expects(self::never())->method('save');

        $response = $this->controller->createAction(self::post('nope'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Invalid request body.'], ControllerKit::decode($response));
    }

    public function testCreatingNeedsAnOroUser(): void
    {
        $this->kit->user = null;

        self::assertSame(403, $this->controller->createAction(self::post([]))->getStatusCode());
    }

    public function testTheOwnerUpdatesTheirScript(): void
    {
        $script = (new OntologyDwlScript())->setOwner($this->user)->setName('Totals');
        $this->scripts->method('findVisible')->with(5, $this->user)->willReturn($script);
        $this->scripts->expects(self::once())->method('save')->with($script, ['name' => 'Totals'])->willReturn(null);

        self::assertSame(['success' => true, 'script' => ['name' => 'Totals', 'viewer' => 3]], ControllerKit::decode(
            $this->controller->updateAction(5, self::post(['name' => 'Totals']))
        ));
    }

    public function testOthersCannotChangeOrDeleteAPublicScript(): void
    {
        $theirs = (new OntologyDwlScript())->setOwner(self::user(4))->setPublic(true);
        $this->scripts->method('findVisible')->willReturn($theirs);
        $this->scripts->expects(self::never())->method('save');
        $this->scripts->expects(self::never())->method('delete');

        foreach ([$this->controller->updateAction(5, self::post([])), $this->controller->deleteAction(5)] as $response) {
            self::assertSame(403, $response->getStatusCode());
            self::assertSame(['success' => false, 'message' => self::KEY . 'not_owner'], ControllerKit::decode($response));
        }
    }

    public function testAScriptTheUserCannotSeeIsNotFound(): void
    {
        $this->scripts->method('findVisible')->willReturn(null);

        foreach ([$this->controller->updateAction(5, self::post([])), $this->controller->deleteAction(5)] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame(['success' => false, 'message' => self::KEY . 'not_found'], ControllerKit::decode($response));
        }
        $this->kit->user = null;
        self::assertSame(404, $this->controller->deleteAction(5)->getStatusCode());
    }

    public function testTheOwnerDeletesTheirScript(): void
    {
        $script = (new OntologyDwlScript())->setOwner($this->user);
        $this->scripts->method('findVisible')->willReturn($script);
        $this->scripts->expects(self::once())->method('delete')->with($script);

        self::assertSame(['success' => true], ControllerKit::decode($this->controller->deleteAction(5)));
    }

    // --- run ---------------------------------------------------------------------------------

    public function testAScriptWithoutInputsRunsOnItsOwnAndItsOutputHeaderRendersTheResult(): void
    {
        $this->dwl->expects(self::once())->method('transform')->with("output application/csv\n---\n[{a: 1}]", [])->willReturn([['a' => 1]]);

        $body = ControllerKit::decode($this->controller->runAction(self::post(['script' => "output application/csv\n---\n[{a: 1}]"])));

        self::assertSame(
            ['success' => true, 'result' => "a\n1\n", 'inputs' => [], 'output' => ['rows' => 1, 'shown' => 1, 'truncated' => false],
                'format' => 'csv', 'mime' => 'application/csv', 'extension' => 'csv'],
            $body
        );
    }

    public function testEachInputIsReadInFullUnderItsNameAndMaxRowsOnlyCutsTheResult(): void
    {
        $orders = self::entity(10, 'Order', 'ERP');
        $lines = self::entity(11, 'Line', 'ERP');
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturnCallback(
            static fn (int $id): ?OntologyEntity => [10 => $orders, 11 => $lines][$id] ?? null
        );
        $this->kit->orm->queryResult = static fn (string $dql, array $parameters): array => $parameters['entity'] === $orders
            ? [['payload' => ['o' => 1]], ['payload' => ['o' => 2]], ['payload' => ['o' => 3]]]
            : [['payload' => ['l' => 1]]];
        $this->kit->granted = [self::VIEW_DATA];
        $this->dwl->expects(self::once())->method('transform')
            ->with("output application/csv\n---\norders", ['orders' => [['o' => 1], ['o' => 2], ['o' => 3]], 'lines' => [['l' => 1]]])
            ->willReturn([['o' => 1], ['o' => 2], ['o' => 3]]);

        $body = ControllerKit::decode($this->controller->runAction(self::post([
            'script' => "output application/csv\n---\norders",
            'inputs' => [['entityId' => 10, 'name' => 'orders'], ['entityId' => 11, 'name' => 'lines']],
            'limit' => '2',
        ])));

        // Every record was read (no row cap on the queries) …
        self::assertSame([null, null], array_column($this->kit->orm->queries, 'maxResults'));
        self::assertSame([['name' => 'orders', 'records' => 3], ['name' => 'lines', 'records' => 1]], $body['inputs']);
        // … and only the result lost its rows past max rows.
        self::assertSame("o\n1\n2\n", $body['result']);
        self::assertSame(['rows' => 3, 'shown' => 2, 'truncated' => true], $body['output']);
    }

    public function testAResultThatIsNoListIsShownWhole(): void
    {
        $this->dwl->method('transform')->willReturn(['a' => 1, 'b' => 2, 'c' => 3]);

        $body = ControllerKit::decode($this->controller->runAction(self::post(['script' => 'x', 'limit' => 1])));

        self::assertSame("{\n    \"a\": 1,\n    \"b\": 2,\n    \"c\": 3\n}", $body['result']);
        self::assertNull($body['output']);
    }

    public function testNoMaxRowsShowsEveryRow(): void
    {
        $this->dwl->method('transform')->willReturn([1, 2, 3]);

        foreach ([null, 0, 'x', 3] as $limit) {
            $body = ControllerKit::decode($this->controller->runAction(self::post(['script' => 'x', 'limit' => $limit])));
            self::assertSame(['rows' => 3, 'shown' => 3, 'truncated' => false], $body['output']);
        }
    }

    public function testAScriptErrorIsShownLikeAnyOutcome(): void
    {
        $this->dwl->method('transform')->willThrowException(new \RuntimeException('Unexpected token at line 1'));

        $response = $this->controller->runAction(self::post(['script' => 'bad']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Unexpected token at line 1', 'inputs' => []], ControllerKit::decode($response));
    }

    public function testTheOutputHeaderRendersEvenValuesJsonCannotCarry(): void
    {
        $this->dwl->method('transform')->willReturn(INF);

        $body = ControllerKit::decode($this->controller->runAction(self::post(['script' => "output application/xml\n---\nx"])));

        self::assertTrue($body['success']);
        self::assertStringContainsString('<result>INF</result>', $body['result']);
    }

    public function testAnInternalEntityThatCannotBeReadFailsTheRunLikeAScript(): void
    {
        $entity = self::entity(10, 'NotAClass', 'OroCommerce');
        $entity->getSystem()->setExternal(false);
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturn($entity);
        $this->kit->orm->managesClasses = false;
        $this->kit->granted = [self::VIEW_DATA];
        $this->dwl->expects(self::never())->method('transform');

        $response = $this->controller->runAction(self::post(['script' => 'x', 'inputs' => [['entityId' => 10, 'name' => 'x']]]));

        self::assertSame(200, $response->getStatusCode());
        $body = ControllerKit::decode($response);
        self::assertFalse($body['success']);
        self::assertStringContainsString('NotAClass', $body['error']);
    }

    public function testTheGuardScreensEveryScript(): void
    {
        $this->guard->method('check')->with('props()')->willReturn('dw::Runtime::props() is not available here.');
        $this->dwl->expects(self::never())->method('transform');

        self::assertSame(
            ['success' => false, 'error' => 'dw::Runtime::props() is not available here.'],
            ControllerKit::decode($this->controller->runAction(self::post(['script' => 'props()'])))
        );
    }

    /**
     * @dataProvider rejectedRunProvider
     *
     * @param array<int, string> $granted
     */
    public function testRunsThatCannotStart(mixed $body, array $granted, int $status, string $error): void
    {
        $this->kit->granted = $granted;
        $this->kit->orm->repository(OntologyEntity::class)->method('find')->willReturnCallback(
            static fn (int $id): ?OntologyEntity => $id === 10 ? self::entity(10, 'Order', 'ERP') : null
        );
        $this->dwl->expects(self::never())->method('transform');

        $response = $this->controller->runAction(self::post($body));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($error, ControllerKit::decode($response)['error']);
    }

    /**
     * @return array<string, array{mixed, array<int, string>, int, string}>
     */
    public static function rejectedRunProvider(): array
    {
        $input = [['entityId' => 10, 'name' => 'orders']];

        return [
            'not an object' => ['nope', [], 400, 'Invalid request body.'],
            'an empty script' => [['script' => '  '], [], 400, self::KEY . 'script_empty'],
            'a script that is not text' => [['script' => 5], [], 400, self::KEY . 'script_empty'],
            'inputs without data access' => [['script' => 'orders', 'inputs' => $input], [], 403, 'Access denied.'],
            'an input that is gone' => [['script' => 'x', 'inputs' => [['entityId' => 99, 'name' => 'x']]], [self::VIEW_DATA], 422, self::KEY . 'entity_missing'],
            'an input name that is no variable' => [['script' => 'x', 'inputs' => [['entityId' => 10, 'name' => 'a-b']]], [self::VIEW_DATA], 422, self::KEY . 'input_name_invalid'],
            'inputs that are not a list' => [['script' => 'x', 'inputs' => 'orders'], [self::VIEW_DATA], 422, self::KEY . 'inputs_invalid'],
        ];
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyDataPlaygroundController::getSubscribedServices();

        foreach ([DwlScriptManager::class, DwlTransformer::class, DwlScriptGuard::class, BucketEntityDataStore::class, DwlOutputFormatter::class] as $service) {
            self::assertContains($service, $services);
        }
    }
}
