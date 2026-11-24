<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyDwlScript;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptManager;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\OrmStub;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\TestCase;

final class DwlScriptManagerTest extends TestCase
{
    private OrmStub $orm;
    private DwlScriptManager $manager;
    private User $owner;
    private OntologyEntity $entity;

    protected function setUp(): void
    {
        $this->orm = new OrmStub(fn (string $class) => $this->createMock($class));
        $this->manager = new DwlScriptManager($this->orm->registry);
        $this->owner = self::user(3, 'Ann', 'Lee', 'ann');
        $this->entity = self::withId(new OntologyEntity(), 10);
        $this->orm->repository(OntologyEntity::class)->method('find')
            ->willReturnCallback(fn (mixed $id): ?OntologyEntity => $id === 10 ? $this->entity : null);
        // nameTaken() answers through the query callback: 0 = free unless a test says otherwise.
        $this->orm->queryResult = static fn (): int => 0;
    }

    private static function user(int $id, string $first = '', string $last = '', string $username = 'u'): User
    {
        $user = (new User())->setFirstName($first)->setLastName($last)->setUsername($username);

        return self::withId($user, $id);
    }

    /**
     * @template T of object
     *
     * @param T $object
     *
     * @return T
     */
    private static function withId(object $object, int $id): object
    {
        (new \ReflectionProperty($object::class, 'id'))->setValue($object, $id);

        return $object;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(array $overrides = []): array
    {
        return ['name' => ' Totals ', 'script' => 'orders', 'public' => true, 'inputs' => [['entityId' => 10, 'name' => 'orders']], 'limit' => 50] + $overrides;
    }

    public function testItListsTheUsersOwnScriptsAndEveryPublicOneByName(): void
    {
        $scripts = [new OntologyDwlScript()];
        $this->orm->queryResult = static fn (): array => $scripts;

        self::assertSame($scripts, $this->manager->visibleTo($this->owner));
        self::assertSame(
            'SELECT s FROM ' . OntologyDwlScript::class . ' s WHERE s.owner = :user OR s.public = true ORDER BY s.name ASC, s.id ASC',
            $this->orm->queries[0]['dql']
        );
        self::assertSame(['user' => $this->owner], $this->orm->queries[0]['parameters']);
    }

    public function testItFindsOwnAndPublicScriptsOnly(): void
    {
        $mine = (new OntologyDwlScript())->setOwner($this->owner);
        $public = (new OntologyDwlScript())->setOwner(self::user(4))->setPublic(true);
        $private = (new OntologyDwlScript())->setOwner(self::user(4));
        $byId = [1 => $mine, 2 => $public, 3 => $private];
        $this->orm->repository(OntologyDwlScript::class)->method('find')->willReturnCallback(static fn (mixed $id): ?OntologyDwlScript => $byId[$id] ?? null);

        self::assertSame($mine, $this->manager->findVisible(1, $this->owner));
        self::assertSame($public, $this->manager->findVisible(2, $this->owner));
        self::assertSame($public, $this->manager->findVisible(2, null));
        self::assertNull($this->manager->findVisible(3, $this->owner));
        self::assertNull($this->manager->findVisible(4, $this->owner));
    }

    public function testItSavesTheScriptAndItsMetadata(): void
    {
        $script = (new OntologyDwlScript())->setOwner($this->owner);
        $before = $script->getUpdatedAt();
        usleep(1000);

        self::assertNull($this->manager->save($script, self::body()));

        self::assertSame(['Totals', 'orders', true, [['entityId' => 10, 'name' => 'orders']], 50], [
            $script->getName(), $script->getScript(), $script->isPublic(), $script->getInputs(), $script->getRowLimit(),
        ]);
        self::assertGreaterThan($before, $script->getUpdatedAt());
        self::assertSame([$script], $this->orm->persisted);
        self::assertSame(1, $this->orm->flushes);
        // The name is checked among the OWNER's scripts, the script itself excluded (0 = new).
        self::assertSame(
            'SELECT COUNT(s.id) FROM ' . OntologyDwlScript::class . ' s WHERE s.owner = :owner AND s.name = :name AND s.id <> :except',
            $this->orm->queries[0]['dql']
        );
        self::assertSame(['owner' => $this->owner, 'name' => 'Totals', 'except' => 0], $this->orm->queries[0]['parameters']);
    }

    public function testOmittedMetadataTakesTheDefaults(): void
    {
        $script = self::withId((new OntologyDwlScript())->setOwner($this->owner), 7);

        self::assertNull($this->manager->save($script, ['name' => 'n', 'script' => '']));

        self::assertSame([false, [], null], [$script->isPublic(), $script->getInputs(), $script->getRowLimit()]);
        self::assertSame(7, $this->orm->queries[0]['parameters']['except']);
    }

    /**
     * @dataProvider refusalProvider
     *
     * @param array<string, mixed> $overrides
     */
    public function testItRefusesInvalidFields(array $overrides, string $reason): void
    {
        $script = (new OntologyDwlScript())->setOwner($this->owner);

        self::assertSame($reason, $this->manager->save($script, array_merge(self::body(), $overrides)));
        self::assertSame([], $this->orm->persisted);
        self::assertSame('', $script->getName());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'no name' => [['name' => '  '], 'name_required'],
            'a name that is not text' => [['name' => 5], 'name_required'],
            'a long name' => [['name' => str_repeat('é', 129)], 'name_too_long'],
            'a script that is not text' => [['script' => ['x']], 'script_invalid'],
            'a public flag that is not a bool' => [['public' => 'yes'], 'public_invalid'],
            'a zero limit' => [['limit' => 0], 'limit_invalid'],
            'a limit that is not an int' => [['limit' => '50'], 'limit_invalid'],
            'a huge limit' => [['limit' => DwlScriptManager::MAX_LIMIT + 1], 'limit_invalid'],
            'inputs that are not a list' => [['inputs' => 'orders'], 'inputs_invalid'],
            'an input name that is no variable' => [['inputs' => [['entityId' => 10, 'name' => 'order lines']]], 'input_name_invalid'],
            'two inputs with one name' => [['inputs' => [['entityId' => 10, 'name' => 'a'], ['entityId' => 10, 'name' => 'a']]], 'input_name_duplicate'],
            'a missing input entity' => [['inputs' => [['entityId' => 99, 'name' => 'a']]], 'entity_missing'],
        ];
    }

    public function testAMaximalNameAndLimitAreFine(): void
    {
        $script = (new OntologyDwlScript())->setOwner($this->owner);

        self::assertNull($this->manager->save($script, self::body(['name' => str_repeat('é', 128), 'limit' => DwlScriptManager::MAX_LIMIT])));
    }

    public function testANameTheOwnerAlreadyUsesIsRefused(): void
    {
        $this->orm->queryResult = static fn (): int => 1;

        self::assertSame('name_taken', $this->manager->save((new OntologyDwlScript())->setOwner($this->owner), self::body()));
        self::assertSame([], $this->orm->persisted);
    }

    public function testAScriptWithoutOwnerIsRefused(): void
    {
        self::assertSame('not_owner', $this->manager->save(new OntologyDwlScript(), self::body()));
    }

    public function testTheUniqueIndexDecidesARaceForTheSameName(): void
    {
        $driverError = new class ('duplicate key') extends \Exception implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string
            {
                return '23505';
            }
        };
        $this->orm->flushFailure = new UniqueConstraintViolationException($driverError, null);

        self::assertSame('name_taken', $this->manager->save((new OntologyDwlScript())->setOwner($this->owner), self::body()));
    }

    public function testItDeletes(): void
    {
        $script = new OntologyDwlScript();

        $this->manager->delete($script);

        self::assertSame([$script], $this->orm->removed);
        self::assertSame(1, $this->orm->flushes);
    }

    public function testItSerializesForThePage(): void
    {
        $inputs = [['entityId' => 10, 'name' => 'orders']];
        $script = self::withId((new OntologyDwlScript())->setOwner($this->owner)->setName('Totals')->setScript('orders')
            ->setPublic(true)->setInputs($inputs)->setRowLimit(null), 5);

        $mine = $this->manager->serialize($script, $this->owner);
        self::assertSame(
            ['id' => 5, 'name' => 'Totals', 'script' => 'orders', 'public' => true, 'mine' => true, 'owner' => 'Ann Lee', 'inputs' => $inputs, 'limit' => null],
            array_diff_key($mine, ['updatedAt' => true])
        );
        self::assertSame($script->getUpdatedAt()->format(\DateTimeInterface::ATOM), $mine['updatedAt']);

        // Someone else's view; an owner without a name shows the username.
        $nameless = self::user(8, '', '', 'svc');
        $theirs = $this->manager->serialize((new OntologyDwlScript())->setOwner($nameless), $this->owner);
        self::assertSame([false, 'svc', []], [$theirs['mine'], $theirs['owner'], $theirs['inputs']]);
        self::assertSame('', $this->manager->serialize(new OntologyDwlScript(), $this->owner)['owner']);
    }
}
