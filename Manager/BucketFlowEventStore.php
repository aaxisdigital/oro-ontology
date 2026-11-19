<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * Flow-execution EVENTS on the configured S3 bucket — the alternative backend to
 * aaxis_ontology_flow_events, active while "Use Bucket for Flow Events" is on. Like the
 * entity-data store, the two backends are independent: flipping the toggle migrates nothing.
 *
 * Object layout:
 *
 *   {base}/flow-events/{yyyy}/{mm}/{dd}/{flow-id}-{run-uuid}/{YmdHis+micro}_{kind}_{rand}.json
 *
 * DATE-FIRST on purpose: any listing is bounded to a day window (the Events page reads only the
 * retention window's days), and browsing the bucket lands on "what ran that day", one folder per
 * RUN inside. The filename carries the event's micro-timestamp AND kind, so the Events page
 * aggregates its one-row-per-run view from a pure KEY listing — zero GETs; bodies are fetched
 * only when one run's popup opens. The day folder is the day of EACH EVENT (UTC), so a run
 * crossing midnight spans two run folders — the aggregation merges by uuid, and the popup scans
 * the started..finished day range. Envelope per object:
 * {flowId, flowUuid, flowName, event, datetime, payload}.
 */
class BucketFlowEventStore
{
    private const ROOT = 'flow-events';

    /** Day folders: {yyyy}/{mm}/{dd}. */
    private const DAY_PATH = 'Y/m/d';
    private const PREVIOUS_DAY = '-1 day';
    private const NEXT_DAY = '+1 day';

    /** The event kinds that close a run. */
    private const FINISH_KINDS = ['flow-finish', 'flow-exception'];

    public function __construct(private readonly OntologyBucketClient $client)
    {
    }

    /** Whether the bucket backend is active for flow events (toggle on + connection configured). */
    public function isEnabled(): bool
    {
        return $this->client->isEnabledFor('use_bucket_for_flow_events');
    }

    /**
     * Whether a bucket connection is configured at all — the RETENTION purge cleans the bucket's
     * flow events whenever it can reach them, toggle on or off (a store used before the toggle
     * was switched off must not keep its old events forever).
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Retention: deletes every event object of the UTC DAYS before the cutoff's day (whole day
     * folders — the date-first layout makes that a walk of year/month/day prefixes, skipping
     * every year and month that is not older). Returns how many objects were deleted.
     */
    public function purgeBefore(\DateTimeImmutable $cutoff): int
    {
        $deleted = 0;
        foreach ($this->dayPrefixesBefore($cutoff->setTimezone(new \DateTimeZone('UTC'))->format(self::DAY_PATH)) as $day) {
            foreach ($this->client->listKeys($day) as $key) {
                $this->client->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The day folders strictly before $limit ('Y/m/d'), never listing inside a year or month
     * that is not older.
     *
     * @return \Generator<int, string>
     */
    private function dayPrefixesBefore(string $limit): \Generator
    {
        $root = $this->rootPrefix();
        foreach ($this->prefixesUpTo($root, $root, substr($limit, 0, 4)) as $year) {
            foreach ($this->prefixesUpTo($year, $root, substr($limit, 0, 7)) as $month) {
                foreach ($this->client->listPrefixes($month) as $day) {
                    $date = substr($day, \strlen($root), 10);
                    if (preg_match('#^\d{4}/\d{2}/\d{2}$#', $date) === 1 && $date < $limit) {
                        yield $day;
                    }
                }
            }
        }
    }

    /**
     * The sub-folders of $parent whose date part (after $root) is not after $bound — a year
     * ('Y') or a month ('Y/m').
     *
     * @return \Generator<int, string>
     */
    private function prefixesUpTo(string $parent, string $root, string $bound): \Generator
    {
        foreach ($this->client->listPrefixes($parent) as $prefix) {
            if (substr($prefix, \strlen($root), \strlen($bound)) <= $bound) {
                yield $prefix;
            }
        }
    }

    /**
     * Stores one event. $datetime is the emitter's micro-precision stamp ('Y-m-d H:i:s.u', UTC);
     * an unparseable one falls back to now so an event can never be dropped over its clock.
     *
     * @param array<string, mixed> $payload
     */
    public function append(?int $flowId, ?string $flowName, ?string $uuid, string $event, string $datetime, array $payload): void
    {
        $at = date_create_immutable($datetime, new \DateTimeZone('UTC'))
            ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $key = $this->runPrefix($at->format(self::DAY_PATH), $flowId, $uuid)
            . $at->format('YmdHisu') . '_' . $event . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.json';

        $this->client->put($key, json_encode([
            'flowId' => $flowId,
            'flowUuid' => $uuid,
            'flowName' => $flowName,
            'event' => $event,
            'datetime' => $at->format('Y-m-d H:i:s.u'),
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * ONE ROW PER RUN aggregated from the last $days days' KEY LISTINGS alone (no object bodies):
     * the filename carries time + kind, the folder carries flow id + uuid. Shapes match the SQL
     * aggregation in OntologyEventController::listAction: started prefers the flow-start event,
     * finished is the latest flow-finish/flow-exception, raw datetimes keep micro precision.
     *
     * @return list<array{flowUuid: string, flowId: int|null, startedAtRaw: string|null,
     *                    finishedAtRaw: string|null, finishEvent: string|null, events: int}>
     */
    public function listRuns(int $days): array
    {
        $runs = [];
        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        for ($i = 0; $i < max(1, $days); $i++) {
            foreach ($this->parsedKeysOfDay($day) as $parsed) {
                $this->tallyRun($runs, $parsed);
            }
            $day = $day->modify(self::PREVIOUS_DAY);
        }

        return array_values(array_map(static fn (array $run): array => [
            'flowUuid' => $run['flowUuid'],
            'flowId' => $run['flowId'],
            'startedAtRaw' => self::stampToMicro($run['startOfStart'] ?? $run['startAll']),
            'finishedAtRaw' => self::stampToMicro($run['finishedAtRaw']),
            'finishEvent' => $run['finishEvent'],
            'events' => $run['events'],
        ], $runs));
    }

    /**
     * Folds one listed event key into its run's aggregate: event count, earliest event, earliest
     * flow-start and the latest finish/exception.
     *
     * @param array<string, array<string, mixed>>                          $runs
     * @param array{flowId: int|null, uuid: string, stamp: string, kind: string} $parsed
     */
    private function tallyRun(array &$runs, array $parsed): void
    {
        $runs[$parsed['uuid']] ??= [
            'flowUuid' => $parsed['uuid'],
            'flowId' => $parsed['flowId'],
            'startAll' => null, 'startOfStart' => null,
            'finishedAtRaw' => null, 'finishEvent' => null,
            'events' => 0,
        ];
        $run = &$runs[$parsed['uuid']];
        $run['events']++;
        $run['flowId'] ??= $parsed['flowId'];
        if ($run['startAll'] === null || $parsed['stamp'] < $run['startAll']) {
            $run['startAll'] = $parsed['stamp'];
        }
        if ($parsed['kind'] === 'flow-start'
            && ($run['startOfStart'] === null || $parsed['stamp'] < $run['startOfStart'])) {
            $run['startOfStart'] = $parsed['stamp'];
        }
        if (\in_array($parsed['kind'], self::FINISH_KINDS, true)
            && ($run['finishedAtRaw'] === null || $parsed['stamp'] > $run['finishedAtRaw'])) {
            $run['finishedAtRaw'] = $parsed['stamp'];
            $run['finishEvent'] = $parsed['kind'];
        }
    }

    /**
     * Every event of ONE run, chronological, envelopes included (this is the popup — bodies are
     * needed here). The run's folders are scanned across the started..finished day range (+1 day
     * of margin each side, capped) since a run crossing midnight spans several day folders.
     *
     * @return list<array{flowName: string|null, event: string, datetime: string, payload: array}>
     */
    public function runEvents(?int $flowId, string $uuid, ?string $startedAtRaw, ?string $finishedAtRaw): array
    {
        $from = self::stampOrNow($startedAtRaw)->modify(self::PREVIOUS_DAY);
        $to = self::stampOrNow($finishedAtRaw)->modify(self::NEXT_DAY);

        $events = [];
        $day = $from;
        for ($i = 0; $i < 14 && $day <= $to; $i++, $day = $day->modify(self::NEXT_DAY)) {
            foreach ($this->client->listKeys($this->runPrefix($day->format(self::DAY_PATH), $flowId, $uuid)) as $key) {
                $event = $this->readEvent($key);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }
        usort($events, static fn (array $a, array $b): int => strcmp($a['datetime'], $b['datetime']));

        return $events;
    }

    /** A run's raw started/finished stamp as a UTC date — "now" when absent or unparseable. */
    private static function stampOrNow(?string $raw): \DateTimeImmutable
    {
        $at = $raw !== null ? date_create_immutable($raw, new \DateTimeZone('UTC')) : false;

        return $at ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * One stored event for the popup, or null when the object is gone or not an event envelope.
     *
     * @return array{flowName: string|null, event: string, datetime: string, payload: array}|null
     */
    private function readEvent(string $key): ?array
    {
        $decoded = $this->fetchJson($key);
        if ($decoded === null || !\is_string($decoded['event'] ?? null)) {
            return null;
        }

        return [
            'flowName' => \is_string($decoded['flowName'] ?? null) ? $decoded['flowName'] : null,
            'event' => (string) $decoded['event'],
            'datetime' => (string) ($decoded['datetime'] ?? ''),
            'payload' => \is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
        ];
    }

    /** @return array<mixed>|null the decoded JSON object stored under $key, null when absent/invalid */
    private function fetchJson(string $key): ?array
    {
        $raw = $this->client->get($key);
        $decoded = $raw === null ? null : json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The event keys of one UTC day, parsed (keys of any other shape are skipped).
     *
     * @return list<array{flowId: int|null, uuid: string, stamp: string, kind: string, key: string}>
     */
    private function parsedKeysOfDay(\DateTimeImmutable $day): array
    {
        $parsed = [];
        foreach ($this->client->listKeys($this->dayPrefix($day->format(self::DAY_PATH))) as $key) {
            $entry = $this->parseKey($key);
            if ($entry !== null) {
                $parsed[] = $entry + ['key' => $key];
            }
        }

        return $parsed;
    }

    // --- Keys -------------------------------------------------------------------------------

    private function dayPrefix(string $dayPath): string
    {
        return $this->rootPrefix() . $dayPath . '/';
    }

    /** "{base path}/flow-events/" — every event key lives under it. */
    private function rootPrefix(): string
    {
        $base = $this->client->basePath();

        return ($base === '' ? '' : $base . '/') . self::ROOT . '/';
    }

    private function runPrefix(string $dayPath, ?int $flowId, ?string $uuid): string
    {
        return $this->dayPrefix($dayPath) . ($flowId ?? 0) . '-' . rawurlencode($uuid ?? 'none') . '/';
    }

    /**
     * Splits an event key back into its parts: …/{yyyy}/{mm}/{dd}/{flowId}-{uuid}/{stamp}_{kind}_{rand}.json
     *
     * @return array{flowId: int|null, uuid: string, stamp: string, kind: string}|null
     */
    private function parseKey(string $key): ?array
    {
        $segments = explode('/', $key);
        $file = $segments[\count($segments) - 1] ?? '';
        $runFolder = $segments[\count($segments) - 2] ?? '';
        if (!str_ends_with($file, '.json') || !str_contains($runFolder, '-')) {
            return null;
        }
        [$flowIdRaw, $uuid] = explode('-', $runFolder, 2);
        $fileParts = explode('_', substr($file, 0, -5), 3);
        if (\count($fileParts) < 2 || preg_match('/^\d{20}$/', $fileParts[0]) !== 1) {
            return null;
        }

        return [
            'flowId' => ctype_digit($flowIdRaw) && (int) $flowIdRaw > 0 ? (int) $flowIdRaw : null,
            'uuid' => rawurldecode($uuid),
            'stamp' => $fileParts[0],
            'kind' => $fileParts[1],
        ];
    }

    /** 20-digit YmdHis+micro stamp → the emitter's 'Y-m-d H:i:s.u' form (null passes through). */
    private static function stampToMicro(?string $stamp): ?string
    {
        if ($stamp === null || preg_match('/^\d{20}$/', $stamp) !== 1) {
            return $stamp;
        }

        return sprintf(
            '%s-%s-%s %s:%s:%s.%s',
            substr($stamp, 0, 4),
            substr($stamp, 4, 2),
            substr($stamp, 6, 2),
            substr($stamp, 8, 2),
            substr($stamp, 10, 2),
            substr($stamp, 12, 2),
            substr($stamp, 14, 6)
        );
    }
}
