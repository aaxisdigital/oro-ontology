<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyBucketClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BucketFlowEventStoreTest extends TestCase
{
    private OntologyBucketClient&MockObject $client;
    private BucketFlowEventStore $store;

    /** @var array<string, array<int, string>> listing prefix => keys */
    private array $listings = [];

    /** @var array<string, string> key => body */
    private array $bodies = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(OntologyBucketClient::class);
        $this->client->method('basePath')->willReturn('base');
        $this->client->method('listKeys')->willReturnCallback(fn (string $prefix): array => $this->listings[$prefix] ?? []);
        $this->client->method('get')->willReturnCallback(fn (string $key): ?string => $this->bodies[$key] ?? null);
        $this->store = new BucketFlowEventStore($this->client);
    }

    private static function day(int $daysAgo): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify(sprintf('-%d day', $daysAgo));
    }

    private static function dayPrefix(\DateTimeImmutable $day): string
    {
        return 'base/flow-events/' . $day->format('Y/m/d') . '/';
    }

    private static function stamp(\DateTimeImmutable $day, string $time): string
    {
        return $day->format('Ymd') . $time;
    }

    public function testIsEnabledFollowsTheFlowEventsToggle(): void
    {
        $this->client->expects(self::once())->method('isEnabledFor')->with('use_bucket_for_flow_events')->willReturn(true);

        self::assertTrue($this->store->isEnabled());
    }

    public function testAppendStoresTheEnvelopeUnderItsDayAndRunFolder(): void
    {
        $this->client->expects(self::once())->method('put')->with(
            self::callback(static fn (string $key): bool => preg_match(
                '#^base/flow-events/2026/03/04/12-run%2F1/20260304050607123456_flow-start_[0-9a-f]{6}\.json$#',
                $key
            ) === 1),
            '{"flowId":12,"flowUuid":"run/1","flowName":"Ünïcode/Flow","event":"flow-start",'
            . '"datetime":"2026-03-04 05:06:07.123456","payload":{"trigger":"debug"}}'
        );

        $this->store->append(12, 'Ünïcode/Flow', 'run/1', 'flow-start', '2026-03-04 05:06:07.123456', ['trigger' => 'debug']);
    }

    public function testAppendWithoutIdentityAndWithABrokenClockStillStores(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y/m/d');
        $this->client->expects(self::once())->method('put')->with(
            self::callback(static fn (string $key): bool => str_starts_with($key, 'base/flow-events/' . $today . '/0-none/')
                && str_contains($key, '_step_')),
            self::stringContains('"flowId":null')
        );

        $this->store->append(null, null, null, 'step', 'not a date', []);
    }

    public function testAppendWithoutABasePath(): void
    {
        $client = $this->createMock(OntologyBucketClient::class);
        $client->method('basePath')->willReturn('');
        $client->expects(self::once())->method('put')
            ->with(self::stringStartsWith('flow-events/2026/03/04/5-u/'), self::anything());

        (new BucketFlowEventStore($client))->append(5, 'F', 'u', 'step', '2026-03-04 05:06:07.000000', []);
    }

    public function testListRunsAggregatesOneRowPerRunFromKeysAlone(): void
    {
        $today = self::day(0);
        $yesterday = self::day(1);
        $this->listings[self::dayPrefix($yesterday)] = [
            self::dayPrefix($yesterday) . '3-aaa/' . self::stamp($yesterday, '235900000001') . '_step_ab12cd.json',
            self::dayPrefix($yesterday) . '3-aaa/' . self::stamp($yesterday, '235800000000') . '_flow-start_ab12cd.json',
        ];
        $this->listings[self::dayPrefix($today)] = [
            self::dayPrefix($today) . '3-aaa/' . self::stamp($today, '000100000000') . '_flow-finish_ab12cd.json',
            self::dayPrefix($today) . '0-bbb/' . self::stamp($today, '010000000000') . '_step_ab12cd.json',
            self::dayPrefix($today) . '0-bbb/' . self::stamp($today, '010100000000') . '_flow-exception_ab12cd.json',
            self::dayPrefix($today) . '0-bbb/' . self::stamp($today, '010200000000') . '_flow-finish_ab12cd.json',
            self::dayPrefix($today) . '0-bbb/not-a-stamp_step.json',
            self::dayPrefix($today) . '0-bbb/' . self::stamp($today, '010000000000') . '.json',
            self::dayPrefix($today) . 'nodash/' . self::stamp($today, '010000000000') . '_step_x.json',
            self::dayPrefix($today) . '0-bbb/readme.txt',
        ];

        // Newest day first: runs appear in the order their first key is listed.
        $runs = $this->store->listRuns(2);

        self::assertSame([
            [
                'flowUuid' => 'aaa',
                'flowId' => 3,
                'startedAtRaw' => $yesterday->format('Y-m-d') . ' 23:58:00.000000',
                'finishedAtRaw' => $today->format('Y-m-d') . ' 00:01:00.000000',
                'finishEvent' => 'flow-finish',
                'events' => 3,
            ],
            [
                'flowUuid' => 'bbb',
                'flowId' => null,
                'startedAtRaw' => $today->format('Y-m-d') . ' 01:00:00.000000',
                'finishedAtRaw' => $today->format('Y-m-d') . ' 01:02:00.000000',
                'finishEvent' => 'flow-finish',
                'events' => 3,
            ],
        ], $runs);
    }

    public function testListRunsReadsAtLeastToday(): void
    {
        $this->client->expects(self::once())->method('listKeys')->with(self::dayPrefix(self::day(0)));

        self::assertSame([], $this->store->listRuns(0));
    }

    public function testRunEventsReadsTheRunFoldersAcrossItsDaysInOrder(): void
    {
        $prefix = static fn (string $date): string => 'base/flow-events/' . $date . '/4-run%201/';
        $this->listings[$prefix('2026/03/03')] = [$prefix('2026/03/03') . 'a.json'];
        $this->listings[$prefix('2026/03/04')] = [
            $prefix('2026/03/04') . 'b.json',
            $prefix('2026/03/04') . 'missing.json',
            $prefix('2026/03/04') . 'broken.json',
            $prefix('2026/03/04') . 'no-event.json',
        ];
        $this->listings[$prefix('2026/03/06')] = [$prefix('2026/03/06') . 'late.json'];
        $this->bodies[$prefix('2026/03/03') . 'a.json'] = json_encode(['flowName' => 'F', 'event' => 'flow-finish', 'datetime' => '2026-03-05 00:00:00.000000', 'payload' => ['x' => 1]]);
        $this->bodies[$prefix('2026/03/04') . 'b.json'] = json_encode(['event' => 'flow-start', 'datetime' => '2026-03-04 10:00:00.000000', 'payload' => 'odd']);
        $this->bodies[$prefix('2026/03/04') . 'broken.json'] = '{nope';
        $this->bodies[$prefix('2026/03/04') . 'no-event.json'] = json_encode(['datetime' => 'x']);
        $this->bodies[$prefix('2026/03/06') . 'late.json'] = json_encode(['event' => 'step']);

        $events = $this->store->runEvents(4, 'run 1', '2026-03-04 10:00:00.000000', '2026-03-05 10:00:00.000000');

        self::assertSame([
            ['flowName' => null, 'event' => 'step', 'datetime' => '', 'payload' => []],
            ['flowName' => null, 'event' => 'flow-start', 'datetime' => '2026-03-04 10:00:00.000000', 'payload' => []],
            ['flowName' => 'F', 'event' => 'flow-finish', 'datetime' => '2026-03-05 00:00:00.000000', 'payload' => ['x' => 1]],
        ], $events);
    }

    public function testRunEventsWithoutBoundsScansAroundToday(): void
    {
        $scanned = [];
        $client = $this->createMock(OntologyBucketClient::class);
        $client->method('basePath')->willReturn('');
        $client->method('listKeys')->willReturnCallback(static function (string $prefix) use (&$scanned): array {
            $scanned[] = $prefix;

            return [];
        });

        self::assertSame([], (new BucketFlowEventStore($client))->runEvents(null, 'u', null, 'garbage'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::assertCount(3, $scanned);
        self::assertSame('flow-events/' . $now->modify('-1 day')->format('Y/m/d') . '/0-u/', $scanned[0]);
    }

    public function testRunEventsScansAtMostFourteenDays(): void
    {
        $count = 0;
        $client = $this->createMock(OntologyBucketClient::class);
        $client->method('listKeys')->willReturnCallback(static function () use (&$count): array {
            $count++;

            return [];
        });

        (new BucketFlowEventStore($client))->runEvents(1, 'u', '2026-01-01 00:00:00', '2026-03-01 00:00:00');

        self::assertSame(14, $count);
    }

    // --- retention ------------------------------------------------------------------------------

    public function testIsConfiguredAsksTheClient(): void
    {
        $this->client->expects(self::once())->method('isConfigured')->willReturn(true);

        self::assertTrue($this->store->isConfigured());
    }

    public function testPurgeDeletesTheDayFoldersBeforeTheCutoffDaySkippingNewerYearsAndMonths(): void
    {
        $prefixes = [
            'base/flow-events/' => ['base/flow-events/2025/', 'base/flow-events/2026/', 'base/flow-events/2027/', 'base/flow-events/junk/'],
            'base/flow-events/2025/' => ['base/flow-events/2025/12/'],
            'base/flow-events/2025/12/' => ['base/flow-events/2025/12/31/'],
            'base/flow-events/2026/' => ['base/flow-events/2026/02/', 'base/flow-events/2026/03/', 'base/flow-events/2026/04/'],
            'base/flow-events/2026/02/' => ['base/flow-events/2026/02/28/'],
            'base/flow-events/2026/03/' => ['base/flow-events/2026/03/09/', 'base/flow-events/2026/03/10/', 'base/flow-events/2026/03/11/', 'base/flow-events/2026/03/xx/'],
        ];
        $listed = [];
        $this->client->method('listPrefixes')->willReturnCallback(static function (string $prefix) use ($prefixes, &$listed): array {
            $listed[] = $prefix;

            return $prefixes[$prefix] ?? [];
        });
        $this->listings = [
            'base/flow-events/2025/12/31/' => ['k1'],
            'base/flow-events/2026/02/28/' => ['k2', 'k3'],
            'base/flow-events/2026/03/09/' => ['k4'],
            'base/flow-events/2026/03/10/' => ['keep'],
        ];
        $deleted = [];
        $this->client->method('delete')->willReturnCallback(static function (string $key) use (&$deleted): void {
            $deleted[] = $key;
        });

        // Cutoff on 2026-03-10 (any time, any zone resolving to that UTC day): that day stays.
        $count = $this->store->purgeBefore(new \DateTimeImmutable('2026-03-10 22:30:00', new \DateTimeZone('UTC')));

        self::assertSame(4, $count);
        self::assertSame(['k1', 'k2', 'k3', 'k4'], $deleted);
        self::assertNotContains('base/flow-events/2027/', $listed);
        self::assertNotContains('base/flow-events/2026/04/', $listed);
    }

    public function testPurgeOfAnEmptyStoreDeletesNothing(): void
    {
        $this->client->method('listPrefixes')->willReturn([]);
        $this->client->expects(self::never())->method('delete');

        self::assertSame(0, $this->store->purgeBefore(new \DateTimeImmutable('2026-03-10', new \DateTimeZone('UTC'))));
    }
}
