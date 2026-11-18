<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyEventController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunIndex;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OntologyEventControllerTest extends TestCase
{
    private ControllerKit $kit;
    private BucketFlowEventStore&MockObject $store;
    private FlowRunIndex&MockObject $runs;
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
        $this->runs = $this->createMock(FlowRunIndex::class);
        $this->runs->method('cursor')->willReturn('2026-03-05 10:00:00.000000');
        $this->kit->set(FlowRunIndex::class, $this->runs);
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

    public function testTheIndexPageHasNoData(): void
    {
        self::assertSame([], $this->controller->indexAction());
    }

    // --- list: the run index -------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function runRow(string $uuid, array $overrides = []): array
    {
        return $overrides + [
            'flow_uuid' => $uuid, 'flow_id' => 3, 'flow_name' => 'Nightly', 'storage' => 'db',
            'started_at' => '2026-03-04 05:06:07.5', 'finished_at' => '2026-03-04 05:06:09.25',
            'finish_event' => 'flow-finish', 'elapsed_ms' => '1750', 'events' => '6',
        ];
    }

    public function testRunsAreReadFromTheIndexOfTheTableBackend(): void
    {
        $this->store->method('isEnabled')->willReturn(false);
        $this->runs->expects(self::once())->method('runs')
            ->with(FlowRunIndex::STORAGE_DB, self::matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'), null)
            ->willReturn([
                self::runRow('u-1'),
                self::runRow('u-2', ['flow_id' => null, 'flow_name' => null, 'finished_at' => null, 'finish_event' => null, 'elapsed_ms' => null, 'events' => 1]),
                self::runRow('u-3', ['finish_event' => 'flow-exception', 'elapsed_ms' => 0]),
            ]);
        $this->kit->orm->connection->expects(self::never())->method('fetchAllAssociative');

        $answer = ControllerKit::decode($this->controller->listAction(Request::create('/')));

        self::assertSame('2026-03-05 10:00:00.000000', $answer['cursor']);
        self::assertFalse($answer['incremental']);
        self::assertSame([
            'flowUuid' => 'u-1', 'flowId' => 3, 'flowName' => 'Nightly',
            'startedAt' => '2026-03-04T05:06:07+00:00', 'finishedAt' => '2026-03-04T05:06:09+00:00',
            'elapsedMs' => 1750, 'status' => 'success', 'events' => 6,
        ], $answer['records'][0]);
        // Still running: no finish, no elapsed time, no flow known yet.
        self::assertSame([null, null, null, null, 'running', 1], [
            $answer['records'][1]['flowId'], $answer['records'][1]['flowName'], $answer['records'][1]['finishedAt'],
            $answer['records'][1]['elapsedMs'], $answer['records'][1]['status'], $answer['records'][1]['events'],
        ]);
        self::assertSame(['exception', 0], [$answer['records'][2]['status'], $answer['records'][2]['elapsedMs']]);
    }

    public function testBucketRunsCarryWhatTheirPopupNeeds(): void
    {
        $this->store->method('isEnabled')->willReturn(true);
        $this->runs->expects(self::once())->method('runs')->with(FlowRunIndex::STORAGE_BUCKET, self::anything(), null)->willReturn([
            self::runRow('b-1', ['storage' => 'bucket']),
        ]);

        $record = ControllerKit::decode($this->controller->listAction(Request::create('/')))['records'][0];

        self::assertSame(
            [true, '2026-03-04 05:06:07.5', '2026-03-04 05:06:09.25'],
            [$record['bucket'], $record['startedAtRaw'], $record['finishedAtRaw']]
        );
    }

    public function testARefreshAsksOnlyForTheRunsChangedSinceTheCursor(): void
    {
        $this->store->method('isEnabled')->willReturn(false);
        $this->runs->expects(self::once())->method('runs')
            ->with(FlowRunIndex::STORAGE_DB, self::anything(), '2026-03-05 09:59:55.123456')
            ->willReturn([]);

        $answer = ControllerKit::decode($this->controller->listAction(Request::create('/?since=2026-03-05%2009:59:55.123456')));

        self::assertSame(['records' => [], 'cursor' => '2026-03-05 10:00:00.000000', 'incremental' => true], $answer);
    }

    public function testAMalformedCursorMeansAFullList(): void
    {
        $this->store->method('isEnabled')->willReturn(false);
        $this->runs->expects(self::once())->method('runs')->with(self::anything(), self::anything(), null)->willReturn([]);

        self::assertFalse(ControllerKit::decode($this->controller->listAction(Request::create("/?since=1'%20OR%201=1")))['incremental']);
    }

    /**
     * @dataProvider historyDaysProvider
     */
    public function testTheListWindowFollowsTheHistoryDaysDefaultingTo30(mixed $configured, int $expected): void
    {
        $this->config['aaxis_ontology.flow_execution_history_days'] = $configured;
        $this->store->method('isEnabled')->willReturn(false);
        $since = null;
        $this->runs->method('runs')->willReturnCallback(function (string $storage, string $startedSince) use (&$since): array {
            $since = $startedSince;

            return [];
        });

        $this->controller->listAction(Request::create('/'));

        $days = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp()
            - (new \DateTimeImmutable($since, new \DateTimeZone('UTC')))->getTimestamp();
        self::assertEqualsWithDelta($expected * 86400, $days, 5);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function historyDaysProvider(): array
    {
        return ['set' => [7, 7], 'numeric string' => ['45', 45], 'unset' => [null, 30], 'zero' => [0, 30], 'negative' => [-3, 30]];
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
        self::assertContains(FlowRunIndex::class, $services);
        self::assertContains(\Doctrine\Persistence\ManagerRegistry::class, $services);
    }
}
