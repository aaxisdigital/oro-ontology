<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InMemoryBucketClient;
use PHPUnit\Framework\TestCase;

class BucketEntityDataStoreTest extends TestCase
{
    private InMemoryBucketClient $bucket;
    private BucketEntityDataStore $store;
    private OntologyEntity $entity;

    protected function setUp(): void
    {
        $this->bucket = new InMemoryBucketClient();
        $this->store = new BucketEntityDataStore($this->bucket);
        $this->entity = (new OntologyEntity())->setName('Order');
        (new \ReflectionProperty(OntologyEntity::class, 'id'))->setValue($this->entity, 12);
    }

    private static function when(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $key): array
    {
        self::assertArrayHasKey($key, $this->bucket->objects);

        return json_decode($this->bucket->objects[$key], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedLatest(string $uid, int $version, string $uuid, string $updatedAt, array $payload): void
    {
        $this->bucket->objects['aaxis-ontology/entity-data/12/' . rawurlencode($uid) . '.json'] = json_encode([
            'entityId' => 12, 'entity' => 'Order', 'uniqueId' => $uid, 'version' => $version,
            'uuid' => $uuid, 'updatedAt' => $updatedAt, 'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }

    private function seedHistory(string $uid, int $version, string $uuid, string $stamp = '20260101000000'): void
    {
        $this->bucket->objects[sprintf('aaxis-ontology/entity-data-history/12/%s/%s/%d/%s.json', rawurlencode($uid), $stamp, $version, $uuid)]
            = json_encode(['uniqueId' => $uid, 'version' => $version, 'uuid' => $uuid, 'updatedAt' => '2026-01-01T00:00:00+00:00', 'payload' => ['v' => $version]], JSON_THROW_ON_ERROR);
    }

    public function testItIsEnabledExactlyWhenTheClientIs(): void
    {
        self::assertTrue($this->store->isEnabled());

        $this->bucket->enabled = false;
        self::assertFalse($this->store->isEnabled());
    }

    // --- create -------------------------------------------------------------------------------

    public function testANewRecordIsStoredAtAFixedKeyAsAVersionOneEnvelope(): void
    {
        $changed = $this->store->upsertBatch($this->entity, 'uuid-1', ['SO-1'], [['id' => 'SO-1', 'qty' => 2]], self::when('2026-03-04 05:06:07'));

        self::assertSame(['SO-1'], $changed);
        self::assertSame([
            'entityId' => 12,
            'entity' => 'Order',
            'uniqueId' => 'SO-1',
            'version' => 1,
            'uuid' => 'uuid-1',
            'updatedAt' => '2026-03-04T05:06:07+00:00',
            'payload' => ['id' => 'SO-1', 'qty' => 2],
        ], $this->stored('aaxis-ontology/entity-data/12/SO-1.json'));
    }

    public function testTheUniqueIdIsUrlEncodedInTheKeyAndTheBasePathIsOptional(): void
    {
        $this->bucket->base = '';

        $this->store->upsertBatch($this->entity, 'u', ['a/b c'], [['x' => 1]], self::when('2026-01-01'));

        self::assertSame(['entity-data/12/a%2Fb%20c.json'], array_keys($this->bucket->objects));
    }

    public function testNonAsciiPayloadsAreStoredUnescaped(): void
    {
        $this->store->upsertBatch($this->entity, 'u', ['1'], [['name' => 'São Paulo / ü']], self::when('2026-01-01'));

        self::assertStringContainsString('"name":"São Paulo / ü"', $this->bucket->objects['aaxis-ontology/entity-data/12/1.json']);
    }

    public function testRecreatingADeletedRecordContinuesTheVersionNumbering(): void
    {
        // The live object is gone but three archived versions survive.
        $this->seedHistory('SO-1', 1, 'a');
        $this->seedHistory('SO-1', 3, 'c');
        $this->seedHistory('SO-1', 2, 'b');

        $this->store->upsertBatch($this->entity, 'new', ['SO-1'], [['x' => 1]], self::when('2026-02-01'));

        self::assertSame(4, $this->stored('aaxis-ontology/entity-data/12/SO-1.json')['version']);
    }

    // --- update -------------------------------------------------------------------------------

    public function testAnUpdateDeepMergesArchivesTheOldSnapshotAndBumpsTheVersion(): void
    {
        $this->seedLatest('SO-1', 2, 'old-uuid', '2026-03-04T05:06:07+00:00', ['a' => 1, 'nested' => ['x' => 1, 'y' => 2], 'list' => [1, 2]]);

        $changed = $this->store->upsertBatch($this->entity, 'new-uuid', ['SO-1'], [['b' => 2, 'nested' => ['y' => 9], 'list' => [3]]], self::when('2026-03-05 10:00:00'));

        self::assertSame(['SO-1'], $changed);
        $latest = $this->stored('aaxis-ontology/entity-data/12/SO-1.json');
        self::assertSame(3, $latest['version']);
        self::assertSame('new-uuid', $latest['uuid']);
        self::assertSame('2026-03-05T10:00:00+00:00', $latest['updatedAt']);
        self::assertSame(['a' => 1, 'nested' => ['x' => 1, 'y' => 9], 'list' => [3], 'b' => 2], $latest['payload']);

        // The previous snapshot moves to a history key built from ITS date/version/uuid, whole.
        $archive = $this->stored('aaxis-ontology/entity-data-history/12/SO-1/20260304050607/2/old-uuid.json');
        self::assertSame(2, $archive['version']);
        self::assertSame('old-uuid', $archive['uuid']);
        self::assertSame(['a' => 1, 'nested' => ['x' => 1, 'y' => 2], 'list' => [1, 2]], $archive['payload']);
    }

    public function testAnUpdateThatChangesNothingIsSkipped(): void
    {
        $this->seedLatest('SO-1', 2, 'u', '2026-03-04T05:06:07+00:00', ['a' => 1, 'o' => ['x' => 1, 'y' => 2]]);
        $before = $this->bucket->objects;
        $this->bucket->calls = [];

        // Same content, different key order: still "nothing changed".
        $changed = $this->store->upsertBatch($this->entity, 'new', ['SO-1'], [['o' => ['y' => 2, 'x' => 1]]], self::when('2026-03-05'));

        self::assertSame([], $changed);
        self::assertSame($before, $this->bucket->objects);
        self::assertSame([], array_filter($this->bucket->calls, static fn (string $call): bool => str_starts_with($call, 'PUT')));
    }

    public function testTheArchiveVersionNeverFallsBelowTheHistoryFloor(): void
    {
        // Live says v2 but history already holds v5: the archive lands at 6 and the new latest at 7.
        $this->seedLatest('SO-1', 2, 'live', '2026-03-04T05:06:07+00:00', ['a' => 1]);
        $this->seedHistory('SO-1', 5, 'h5');

        $this->store->upsertBatch($this->entity, 'new', ['SO-1'], [['a' => 2]], self::when('2026-03-05'));

        self::assertSame(7, $this->stored('aaxis-ontology/entity-data/12/SO-1.json')['version']);
        self::assertArrayHasKey('aaxis-ontology/entity-data-history/12/SO-1/20260304050607/6/live.json', $this->bucket->objects);
    }

    public function testTheArchiveTimestampIsUtcAndAnUnparseableOneIsZeroed(): void
    {
        $this->seedLatest('A', 1, 'ua', '2026-03-04T05:06:07-03:00', ['v' => 1]);
        $this->seedLatest('B', 1, 'ub', 'garbage', ['v' => 1]);

        $this->store->upsertBatch($this->entity, 'n', ['A', 'B'], [['v' => 2], ['v' => 2]], self::when('2026-03-05'));

        self::assertArrayHasKey('aaxis-ontology/entity-data-history/12/A/20260304080607/1/ua.json', $this->bucket->objects);
        self::assertArrayHasKey('aaxis-ontology/entity-data-history/12/B/00000000000000/1/ub.json', $this->bucket->objects);
    }

    public function testABatchHandlesEachRecordIndependently(): void
    {
        $this->seedLatest('B', 1, 'ub', '2026-03-04T05:06:07+00:00', ['v' => 1]);

        $changed = $this->store->upsertBatch($this->entity, 'n', ['A', 'B', 'C'], [['v' => 1], ['v' => 1], ['v' => 3]], self::when('2026-03-05'));

        self::assertSame(['A', 'C'], $changed, 'B is unchanged');
    }

    // --- read ---------------------------------------------------------------------------------

    public function testReadLatestDecodesTheEnvelopeWithDefaults(): void
    {
        $this->bucket->objects['aaxis-ontology/entity-data/12/X.json'] = '{"uniqueId":"X"}';

        self::assertSame(
            ['entity' => '', 'uniqueId' => 'X', 'version' => 1, 'uuid' => '', 'updatedAt' => '', 'payload' => []],
            $this->store->readLatest($this->entity, 'X')
        );
    }

    public function testReadLatestIgnoresMissingAndForeignObjects(): void
    {
        self::assertNull($this->store->readLatest($this->entity, 'nope'));

        $this->bucket->objects['aaxis-ontology/entity-data/12/junk.json'] = 'not json';
        self::assertNull($this->store->readLatest($this->entity, 'junk'));

        $this->bucket->objects['aaxis-ontology/entity-data/12/other.json'] = '{"hello":"world"}';
        self::assertNull($this->store->readLatest($this->entity, 'other'));

        $this->bucket->objects['aaxis-ontology/entity-data/12/scalar.json'] = '"text"';
        self::assertNull($this->store->readLatest($this->entity, 'scalar'));
    }

    public function testARecordDeletedBetweenTheListingAndItsReadIsSkipped(): void
    {
        $bucket = new class () extends InMemoryBucketClient {
            public function get(string $key): ?string
            {
                // Another writer removes B right after the LIST.
                return str_ends_with($key, '/B.json') ? null : parent::get($key);
            }
        };
        $bucket->objects['aaxis-ontology/entity-data/12/A.json'] = '{"uniqueId":"A","payload":{"v":1}}';
        $bucket->objects['aaxis-ontology/entity-data/12/B.json'] = '{"uniqueId":"B","payload":{"v":2}}';

        $records = (new BucketEntityDataStore($bucket))->listLatest($this->entity);

        self::assertSame(['A'], array_column($records, 'uniqueId'));
    }

    public function testListAndCountCoverOnlyTheLiveRecordsOfThisEntity(): void
    {
        $this->seedLatest('A', 1, 'ua', '2026-01-01T00:00:00+00:00', ['v' => 1]);
        $this->seedLatest('B', 4, 'ub', '2026-01-01T00:00:00+00:00', ['v' => 2]);
        $this->seedHistory('A', 1, 'h');
        $this->bucket->objects['aaxis-ontology/entity-data/12/junk.json'] = 'not json';
        $this->bucket->objects['aaxis-ontology/entity-data/99/A.json'] = '{"uniqueId":"A"}';

        $records = $this->store->listLatest($this->entity);

        self::assertSame(['A', 'B'], array_column($records, 'uniqueId'));
        self::assertSame([4], [$records[1]['version']]);
        self::assertSame(3, $this->store->countLatest($this->entity), 'counting is by key: the foreign object counts too, no GETs');
    }

    public function testListPayloadsReturnsJustThePayloadsOfTheLiveRecords(): void
    {
        $this->seedLatest('A', 1, 'ua', '2026-01-01T00:00:00+00:00', ['v' => 1]);
        $this->seedLatest('B', 4, 'ub', '2026-01-01T00:00:00+00:00', ['v' => 2, 'n' => ['x']]);
        $this->bucket->objects['aaxis-ontology/entity-data/12/junk.json'] = 'not json';

        self::assertSame([['v' => 1], ['v' => 2, 'n' => ['x']]], $this->store->listPayloads($this->entity));
    }

    public function testVersionsListTheLiveRecordFirstThenTheArchiveNewestFirst(): void
    {
        $this->seedLatest('SO-1', 4, 'live', '2026-03-04T05:06:07+00:00', ['v' => 4]);
        $this->seedHistory('SO-1', 1, 'h1');
        $this->seedHistory('SO-1', 3, 'h3');
        $this->seedHistory('SO-1', 2, 'h2');
        $this->bucket->objects['aaxis-ontology/entity-data-history/12/SO-1/x/9/junk.json'] = 'not json';
        $this->seedHistory('SO-10', 7, 'other-record');

        $versions = $this->store->versions($this->entity, 'SO-1');

        self::assertSame([4, 3, 2, 1], array_column($versions, 'version'));
        self::assertSame([true, false, false, false], array_column($versions, 'current'));
        self::assertSame(['live', 'h3', 'h2', 'h1'], array_column($versions, 'uuid'));
        self::assertSame(['v' => 4], $versions[0]['payload']);
        self::assertSame('2026-03-04T05:06:07+00:00', $versions[0]['updatedAt']);
    }

    public function testVersionsOfAnUnknownRecordAreEmpty(): void
    {
        self::assertSame([], $this->store->versions($this->entity, 'ghost'));
    }

    public function testVersionsOfADeletedRecordStillShowItsArchive(): void
    {
        $this->seedHistory('SO-1', 1, 'h1');

        $versions = $this->store->versions($this->entity, 'SO-1');

        self::assertSame([[false]], [array_column($versions, 'current')]);
    }

    // --- purge --------------------------------------------------------------------------------

    public function testPurgeDeletesLiveAndArchivedObjectsOfTheEntityOnly(): void
    {
        $this->seedLatest('A', 1, 'ua', '2026-01-01T00:00:00+00:00', []);
        $this->seedLatest('B', 1, 'ub', '2026-01-01T00:00:00+00:00', []);
        $this->seedHistory('A', 1, 'h1');
        $this->bucket->objects['aaxis-ontology/entity-data/13/A.json'] = '{"uniqueId":"A"}';
        $this->bucket->objects['aaxis-ontology/entity-data-history/13/A/1/1/x.json'] = '{}';

        $liveCount = $this->store->purgeEntity($this->entity);

        self::assertSame(2, $liveCount);
        self::assertEqualsCanonicalizing(
            ['aaxis-ontology/entity-data-history/13/A/1/1/x.json', 'aaxis-ontology/entity-data/13/A.json'],
            array_keys($this->bucket->objects)
        );
    }

    public function testErasingRecordsDropsTheirLiveObjectAndEveryVersion(): void
    {
        $this->seedLatest('A/1', 2, 'ua', '2026-01-01T00:00:00+00:00', []);
        $this->seedLatest('B', 1, 'ub', '2026-01-01T00:00:00+00:00', []);
        $this->seedHistory('A/1', 1, 'h1');
        $this->seedHistory('A/1', 2, 'h2', '20260102000000');
        $this->seedHistory('B', 1, 'h3');
        $this->seedHistory('gone', 1, 'h4');

        self::assertSame(1, $this->store->eraseRecords($this->entity, ['A/1', 'A/1', 'gone']));

        self::assertEqualsCanonicalizing([
            'aaxis-ontology/entity-data/12/B.json',
            'aaxis-ontology/entity-data-history/12/B/20260101000000/1/h3.json',
        ], array_keys($this->bucket->objects), 'a record already gone still loses its stray history');
    }

    // --- merge + compare ----------------------------------------------------------------------

    /**
     * @dataProvider mergeProvider
     */
    public function testDeepMerge(mixed $old, mixed $new, mixed $expected): void
    {
        self::assertSame($expected, BucketEntityDataStore::deepMerge($old, $new));
    }

    /**
     * @return array<string, array{mixed, mixed, mixed}>
     */
    public static function mergeProvider(): array
    {
        return [
            'objects merge key by key' => [['a' => 1, 'b' => 2], ['b' => 3, 'c' => 4], ['a' => 1, 'b' => 3, 'c' => 4]],
            'nested objects merge recursively' => [['o' => ['x' => 1, 'y' => 2]], ['o' => ['y' => 3]], ['o' => ['x' => 1, 'y' => 3]]],
            'lists are replaced' => [['l' => [1, 2, 3]], ['l' => [9]], ['l' => [9]]],
            'a scalar replaces an object' => [['o' => ['x' => 1]], ['o' => 'flat'], ['o' => 'flat']],
            'an object replaces a scalar' => [['o' => 'flat'], ['o' => ['x' => 1]], ['o' => ['x' => 1]]],
            'null overwrites a key' => [['a' => 1], ['a' => null], ['a' => null]],
            'an empty old array is not an object, so the new value replaces it' => [[], ['a' => 1], ['a' => 1]],
            'a null new value keeps the old scalar' => ['old', null, 'old'],
            'a non-object new value keeps an old object' => [['a' => 1], 'text', ['a' => 1]],
            'an empty new array keeps an old object' => [['a' => 1], [], ['a' => 1]],
            'an old list is replaced by the new value' => [[1, 2], ['a' => 1], ['a' => 1]],
        ];
    }

    /**
     * @dataProvider equalityProvider
     */
    public function testJsonEquals(mixed $a, mixed $b, bool $equal): void
    {
        self::assertSame($equal, BucketEntityDataStore::jsonEquals($a, $b));
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function equalityProvider(): array
    {
        return [
            'object key order is ignored' => [['a' => 1, 'b' => ['x' => 1, 'y' => 2]], ['b' => ['y' => 2, 'x' => 1], 'a' => 1], true],
            'list order matters' => [['l' => [1, 2]], ['l' => [2, 1]], false],
            'lists of objects normalise inside' => [[['b' => 1, 'a' => 2]], [['a' => 2, 'b' => 1]], true],
            'different values' => [['a' => 1], ['a' => 2], false],
            'type differences' => [['a' => 1], ['a' => '1'], false],
            'scalars' => ['x', 'x', true],
            'null vs empty' => [null, [], false],
        ];
    }
}
