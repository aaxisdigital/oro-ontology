<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyEventController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OntologyEventControllerTest extends TestCase
{
    private ControllerKit $kit;
    private BucketFlowEventStore&MockObject $store;
    private OntologyEventController $controller;

    /** @var array<string, mixed> */
    private array $config = ['aaxis_ontology.flow_execution_history_days' => 14];

    protected function setUp(): void
    {
        $this->kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->store = $this->createMock(BucketFlowEventStore::class);
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);
        $this->kit->set(BucketFlowEventStore::class, $this->store);
        $this->kit->set(ConfigManager::class, $config);
        $this->controller = $this->kit->wire(new OntologyEventController());
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function event(int $id, ?string $flowName, string $event, ?string $datetime, ?array $payload): OntologyFlowEvent
    {
        $entity = new OntologyFlowEvent();
        foreach (['id' => $id, 'flowName' => $flowName, 'event' => $event, 'datetime' => $datetime, 'payload' => $payload] as $property => $value) {
            (new \ReflectionProperty(OntologyFlowEvent::class, $property))->setValue($entity, $value);
        }

        return $entity;
    }

    private static function flow(int $id, string $name): OntologyFlow
    {
        $flow = (new OntologyFlow())->setName($name);
        (new \ReflectionProperty(OntologyFlow::class, 'id'))->setValue($flow, $id);

        return $flow;
    }

    public function testTheIndexPageHasNoData(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    // --- list: table backend ------------------------------------------------------------------

    public function testRunsAreAggregatedFromTheEventTable(): void
    {
        $this->store->method('isEnabled')->willReturn(false);
        $this->kit->orm->connection->expects(self::once())->method('fetchAllAssociative')->with(self::stringContains('FROM aaxis_ontology_flow_events'))->willReturn([
            [
                'flow_uuid' => 'u-1', 'start_flow_id' => 3, 'any_flow_id' => 9, 'start_name' => 'Nightly', 'any_name' => 'Other',
                'started_at' => '2026-03-04 05:06:07.500000', 'finished_at' => '2026-03-04 05:06:09.250000',
                'finish_event' => 'flow-finish', 'events' => '6',
            ],
            [
                'flow_uuid' => 'u-2', 'start_flow_id' => null, 'any_flow_id' => 9, 'start_name' => null, 'any_name' => 'Fallback',
                'started_at' => '2026-03-04 05:00:00', 'finished_at' => '2026-03-04 05:00:01',
                'finish_event' => 'flow-exception', 'events' => 2,
            ],
            [
                'flow_uuid' => 'u-3', 'start_flow_id' => 4, 'any_flow_id' => 4, 'start_name' => 'Running one', 'any_name' => 'x',
                'started_at' => '2026-03-04 04:00:00', 'finished_at' => null, 'finish_event' => null, 'events' => 1,
            ],
            [
                'flow_uuid' => 'u-4', 'start_flow_id' => null, 'any_flow_id' => null, 'start_name' => null, 'any_name' => null,
                'started_at' => '2026-03-04 03:00:00.000000', 'finished_at' => '2026-03-04 02:59:59.000000',
                'finish_event' => 'something-else', 'events' => 1,
            ],
        ]);

        $records = ControllerKit::decode($this->controller->listAction())['records'];

        self::assertSame([
            'flowUuid' => 'u-1', 'flowId' => 3, 'flowName' => 'Nightly',
            'startedAt' => '2026-03-04T05:06:07+00:00', 'finishedAt' => '2026-03-04T05:06:09+00:00',
            'elapsedMs' => 1750, 'status' => 'success', 'events' => 6,
        ], $records[0]);
        // A run with no flow-start falls back to the first flow id / name seen on any of its events.
        self::assertSame([9, 'Fallback', 'exception', 1000], [$records[1]['flowId'], $records[1]['flowName'], $records[1]['status'], $records[1]['elapsedMs']]);
        // Still running: no finish time, so no elapsed time.
        self::assertSame([null, null, 'running'], [$records[2]['finishedAt'], $records[2]['elapsedMs'], $records[2]['status']]);
        // A finish that precedes the start never yields a negative elapsed time; unknown finish kinds read as running.
        self::assertSame([0, 'running', null, null], [$records[3]['elapsedMs'], $records[3]['status'], $records[3]['flowId'], $records[3]['flowName']]);
    }

    public function testAnEmptyEventTableListsNothing(): void
    {
        $this->store->method('isEnabled')->willReturn(false);
        $this->kit->orm->connection->method('fetchAllAssociative')->willReturn([]);

        self::assertSame(['records' => []], ControllerKit::decode($this->controller->listAction()));
    }

    // --- list: bucket backend -----------------------------------------------------------------

    public function testRunsAreAggregatedFromTheBucketWithFlowNamesResolvedFromTheFlowsTable(): void
    {
        $this->store->method('isEnabled')->willReturn(true);
        $this->store->expects(self::once())->method('listRuns')->with(14)->willReturn([
            ['flowUuid' => 'old', 'flowId' => 3, 'startedAtRaw' => '2026-03-04 05:00:00.000000', 'finishedAtRaw' => '2026-03-04 05:00:02.500000', 'finishEvent' => 'flow-finish', 'events' => 4],
            ['flowUuid' => 'new', 'flowId' => 8, 'startedAtRaw' => '2026-03-05 09:00:00.000000', 'finishedAtRaw' => null, 'finishEvent' => null, 'events' => 1],
            ['flowUuid' => 'bare', 'flowId' => null, 'startedAtRaw' => '2026-03-04 07:00:00.000000', 'finishedAtRaw' => '2026-03-04 07:00:01.000000', 'finishEvent' => 'flow-exception', 'events' => 2],
        ]);
        $this->kit->orm->repository(OntologyFlow::class)->expects(self::once())->method('findBy')->with(['id' => [3, 8]])->willReturn([self::flow(3, 'Nightly')]);

        $records = ControllerKit::decode($this->controller->listAction())['records'];

        // Newest run first; a deleted flow (id 8) and a bare run have no name.
        self::assertSame(['new', 'bare', 'old'], array_column($records, 'flowUuid'));
        self::assertSame(
            [
                'flowUuid' => 'old', 'flowId' => 3, 'flowName' => 'Nightly',
                'startedAt' => '2026-03-04T05:00:00+00:00', 'finishedAt' => '2026-03-04T05:00:02+00:00',
                'elapsedMs' => 2500, 'status' => 'success', 'events' => 4,
                'bucket' => true, 'startedAtRaw' => '2026-03-04 05:00:00.000000', 'finishedAtRaw' => '2026-03-04 05:00:02.500000',
            ],
            $records[2]
        );
        self::assertSame([null, 'running', null], [$records[0]['flowName'], $records[0]['status'], $records[0]['elapsedMs']]);
        self::assertSame([null, 'exception', 1000], [$records[1]['flowName'], $records[1]['status'], $records[1]['elapsedMs']]);
    }

    /**
     * @dataProvider historyDaysProvider
     */
    public function testTheBucketListingWindowDefaultsTo30Days(mixed $configured, int $expected): void
    {
        $this->config['aaxis_ontology.flow_execution_history_days'] = $configured;
        $this->store->method('isEnabled')->willReturn(true);
        $this->store->expects(self::once())->method('listRuns')->with($expected)->willReturn([]);

        self::assertSame(['records' => []], ControllerKit::decode($this->controller->listAction()));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function historyDaysProvider(): array
    {
        return ['set' => [7, 7], 'numeric string' => ['45', 45], 'unset' => [null, 30], 'zero' => [0, 30], 'negative' => [-3, 30]];
    }

    public function testNoFlowLookupIsMadeWhenNoRunHasAFlowId(): void
    {
        $this->store->method('isEnabled')->willReturn(true);
        $this->store->method('listRuns')->willReturn([
            ['flowUuid' => 'a', 'flowId' => null, 'startedAtRaw' => '2026-03-04 05:00:00.000000', 'finishedAtRaw' => null, 'finishEvent' => null, 'events' => 1],
        ]);
        $this->kit->orm->repository(OntologyFlow::class)->expects(self::never())->method('findBy');

        self::assertCount(1, ControllerKit::decode($this->controller->listAction())['records']);
    }

    // --- run popup ----------------------------------------------------------------------------

    public function testABlankRunUuidYieldsNoRecords(): void
    {
        self::assertSame(['records' => []], ControllerKit::decode($this->controller->runAction(Request::create('/?uuid=%20%20'))));
        self::assertSame(['records' => []], ControllerKit::decode($this->controller->runAction(Request::create('/'))));
    }

    public function testTheRunPopupListsTheEventsOfOneRunOldestFirst(): void
    {
        $this->kit->orm->repository(OntologyFlowEvent::class)->expects(self::once())->method('findBy')
            ->with(['flowUuid' => 'u-1'], ['datetime' => 'ASC', 'id' => 'ASC'])
            ->willReturn([
                self::event(1, 'Nightly', 'flow-start', '2026-03-04 05:06:07.250000', ['trigger' => 'cron']),
                self::event(2, null, 'step', null, null),
            ]);

        $records = ControllerKit::decode($this->controller->runAction(Request::create('/?uuid=u-1')))['records'];

        self::assertSame([
            'id' => 1, 'flowName' => 'Nightly', 'event' => 'flow-start',
            'datetime' => '2026-03-04T05:06:07+00:00', 'ms' => 1772600767250, 'payload' => ['trigger' => 'cron'],
        ], $records[0]);
        self::assertSame(['id' => 2, 'flowName' => null, 'event' => 'step', 'datetime' => null, 'ms' => null, 'payload' => null], $records[1]);
    }

    public function testTheBucketRunPopupReadsTheRunsDayFolders(): void
    {
        $this->store->expects(self::once())->method('runEvents')
            ->with(3, 'u-1', '2026-03-04 05:00:00.000000', '2026-03-04 05:00:02.000000')
            ->willReturn([
                ['flowName' => 'Nightly', 'event' => 'flow-start', 'datetime' => '2026-03-04 05:00:00.500000', 'payload' => ['a' => 1]],
                ['flowName' => null, 'event' => 'step', 'datetime' => '', 'payload' => []],
            ]);

        $request = Request::create('/?uuid=u-1&bucket=1&flowId=3&startedAt=2026-03-04+05:00:00.000000&finishedAt=2026-03-04+05:00:02.000000');
        $records = ControllerKit::decode($this->controller->runAction($request))['records'];

        self::assertSame(
            ['id' => 1, 'flowName' => 'Nightly', 'event' => 'flow-start', 'datetime' => '2026-03-04T05:00:00+00:00', 'ms' => 1772600400500, 'payload' => ['a' => 1]],
            $records[0]
        );
        // Numbered by position; an event without a timestamp renders none.
        self::assertSame(['id' => 2, 'flowName' => null, 'event' => 'step', 'datetime' => null, 'ms' => null, 'payload' => []], $records[1]);
    }

    public function testTheBucketRunPopupTreatsAMissingFlowIdAndBlankBoundsAsUnknown(): void
    {
        $this->store->expects(self::once())->method('runEvents')->with(null, 'u-1', null, null)->willReturn([]);

        $response = $this->controller->runAction(Request::create('/?uuid=u-1&bucket=1&flowId=abc&startedAt=&finishedAt='));

        self::assertSame(['records' => []], ControllerKit::decode($response));
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyEventController::getSubscribedServices();

        self::assertContains(BucketFlowEventStore::class, $services);
        self::assertContains(ConfigManager::class, $services);
        self::assertContains(\Doctrine\Persistence\ManagerRegistry::class, $services);
    }
}
