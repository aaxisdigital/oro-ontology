<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * A small Doctrine stand-in for controller tests: one mocked repository per entity class (the test
 * scripts its finders), one entity manager that records persist/remove/flush, DQL queries answered
 * by a single callback, and a mocked DBAL connection.
 */
final class OrmStub
{
    public ManagerRegistry&MockObject $registry;
    public EntityManagerInterface&MockObject $em;
    public Connection&MockObject $connection;

    /** @var array<int, object> */
    public array $persisted = [];

    /** @var array<int, object> */
    public array $removed = [];

    public int $flushes = 0;

    /** When false, getManagerForClass() answers null (a class Doctrine does not manage). */
    public bool $managesClasses = true;

    public ?\Throwable $managerFailure = null;

    /** When set, flush() throws it (after counting the call). */
    public ?\Throwable $flushFailure = null;

    /** @var array<int, array{dql: string, parameters: array<string, mixed>, maxResults: int|null, method: string}> */
    public array $queries = [];

    /** @var callable(string, array<string, mixed>, ?int, string): mixed answers every query's terminal call */
    public $queryResult;

    /** @var array<string, EntityRepository&MockObject> */
    private array $repositories = [];

    /** @var callable(class-string): MockObject */
    private $create;

    /**
     * @param callable(class-string): MockObject $createMock the test case's createMock()
     */
    public function __construct(callable $createMock)
    {
        $this->create = $createMock;
        $this->queryResult = static fn (): array => [];

        $this->em = $createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });
        $this->em->method('remove')->willReturnCallback(function (object $object): void {
            $this->removed[] = $object;
        });
        $this->em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
            if ($this->flushFailure !== null) {
                throw $this->flushFailure;
            }
        });
        $this->em->method('createQuery')->willReturnCallback(fn (string $dql): Query => $this->newQuery($dql));

        $this->connection = $createMock(Connection::class);

        $this->registry = $createMock(ManagerRegistry::class);
        $this->registry->method('getRepository')->willReturnCallback(fn (string $class): EntityRepository => $this->repository($class));
        $this->registry->method('getManagerForClass')->willReturnCallback(function (): ?EntityManagerInterface {
            if ($this->managerFailure !== null) {
                throw $this->managerFailure;
            }

            return $this->managesClasses ? $this->em : null;
        });
        $this->registry->method('getConnection')->willReturn($this->connection);
    }

    /**
     * @return EntityRepository&MockObject
     */
    public function repository(string $class): EntityRepository
    {
        return $this->repositories[$class] ??= ($this->create)(EntityRepository::class);
    }

    private function newQuery(string $dql): Query
    {
        $index = \count($this->queries);
        $this->queries[$index] = ['dql' => $dql, 'parameters' => [], 'maxResults' => null, 'method' => ''];

        $query = ($this->create)(Query::class);
        $query->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use ($index, $query) {
            $this->queries[$index]['parameters'][$name] = $value;

            return $query;
        });
        $query->method('setMaxResults')->willReturnCallback(function (?int $max) use ($index, $query) {
            $this->queries[$index]['maxResults'] = $max;

            return $query;
        });
        foreach (['getArrayResult', 'getSingleScalarResult', 'execute', 'getResult'] as $terminal) {
            $query->method($terminal)->willReturnCallback(function () use ($index, $terminal) {
                $this->queries[$index]['method'] = $terminal;
                $entry = $this->queries[$index];

                return ($this->queryResult)($entry['dql'], $entry['parameters'], $entry['maxResults'], $terminal);
            });
        }

        return $query;
    }
}
