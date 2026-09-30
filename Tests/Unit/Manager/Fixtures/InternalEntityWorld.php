<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * A miniature Doctrine world for the internal-system reader/writer tests: REAL class metadata
 * (built by hand, so field/association resolution is the real thing) behind a mocked entity
 * manager that records every DQL string and hands back scripted rows.
 */
final class InternalEntityWorld
{
    /** @var array<string, ClassMetadata> */
    public array $metadata = [];

    /** @var array<int, array{dql: string, parameters: array<string, mixed>, maxResults: int|null}> */
    public array $queries = [];

    /** @var array<int, array<string, mixed>|\Throwable> scripted results of getArrayResult() */
    public array $results = [];

    /** @var array<int, object> rows the repository's findBy() returns */
    public array $found = [];

    /** @var array<int, array<string, mixed>> */
    public array $findByCalls = [];

    /** @var array<int, array{string, mixed}> */
    public array $references = [];

    public int $flushes = 0;
    public ?\Throwable $flushFailure = null;
    public bool $metadataFails = false;

    public EntityManagerInterface&MockObject $em;
    public ManagerRegistry&MockObject $registry;

    /**
     * @param callable(class-string): MockObject $create the test case's createMock()
     */
    public function __construct(callable $create)
    {
        $this->metadata[InternalProduct::class] = self::productMetadata();
        $this->metadata[InternalCategory::class] = self::simpleMetadata(InternalCategory::class, 'id', 'integer', ['name' => 'string']);
        $this->metadata[InternalOwner::class] = self::simpleMetadata(InternalOwner::class, 'code', 'string', []);

        $this->em = $create(EntityManagerInterface::class);
        $this->em->method('getClassMetadata')->willReturnCallback(function (string $class): ClassMetadata {
            if ($this->metadataFails || !isset($this->metadata[$class])) {
                throw new \RuntimeException('not an entity');
            }

            return $this->metadata[$class];
        });
        $this->em->method('createQuery')->willReturnCallback(function (string $dql) use ($create): Query {
            $index = \count($this->queries);
            $this->queries[$index] = ['dql' => $dql, 'parameters' => [], 'maxResults' => null];

            $query = $create(Query::class);
            $query->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use ($index, $query) {
                $this->queries[$index]['parameters'][$name] = $value;

                return $query;
            });
            $query->method('setMaxResults')->willReturnCallback(function (?int $max) use ($index, $query) {
                $this->queries[$index]['maxResults'] = $max;

                return $query;
            });
            $query->method('getArrayResult')->willReturnCallback(function () {
                $next = array_shift($this->results) ?? [];
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            });

            return $query;
        });
        $repository = $create(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            $this->findByCalls[] = $criteria;

            return $this->found;
        });
        $this->em->method('getRepository')->willReturn($repository);
        $this->em->method('getReference')->willReturnCallback(function (string $class, mixed $id): object {
            $this->references[] = [$class, $id];

            return $class === InternalCategory::class ? new InternalCategory((int) $id) : new InternalOwner((string) $id);
        });
        $this->em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
            if ($this->flushFailure !== null) {
                throw $this->flushFailure;
            }
        });

        $this->registry = $create(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturnCallback(
            fn (string $class) => isset($this->metadata[$class]) ? $this->em : null
        );
    }

    public static function productMetadata(): ClassMetadata
    {
        $md = new ClassMetadata(InternalProduct::class);
        $md->initializeReflection(new RuntimeReflectionService());
        $md->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        foreach ([
            'sku' => 'string', 'name' => 'string', 'price' => 'decimal', 'qty' => 'integer', 'active' => 'boolean',
            'weight' => 'float', 'createdAt' => 'datetime', 'releaseDate' => 'date_immutable', 'openingTime' => 'time',
            'tags' => 'simple_array', 'meta' => 'json',
        ] as $field => $type) {
            $md->mapField(['fieldName' => $field, 'type' => $type]);
        }
        $md->mapManyToOne([
            'fieldName' => 'category',
            'targetEntity' => InternalCategory::class,
            'joinColumns' => [['name' => 'category_id', 'referencedColumnName' => 'id']],
        ]);
        $md->mapManyToOne([
            'fieldName' => 'owner',
            'targetEntity' => InternalOwner::class,
            'joinColumns' => [['name' => 'owner_code', 'referencedColumnName' => 'code']],
        ]);
        // A to-many association: has no flat representation, so payloads must skip it.
        $md->mapOneToMany(['fieldName' => 'variants', 'targetEntity' => InternalCategory::class, 'mappedBy' => 'product']);
        // reflFields (what getFieldValue()/setFieldValue() read through) are built here, as Doctrine does on load.
        $md->wakeupReflection(new RuntimeReflectionService());

        return $md;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function simpleMetadata(string $class, string $idField, string $idType, array $fields): ClassMetadata
    {
        $md = new ClassMetadata($class);
        $md->initializeReflection(new RuntimeReflectionService());
        $md->mapField(['fieldName' => $idField, 'type' => $idType, 'id' => true]);
        foreach ($fields as $field => $type) {
            $md->mapField(['fieldName' => $field, 'type' => $type]);
        }
        $md->wakeupReflection(new RuntimeReflectionService());

        return $md;
    }

    public function lastDql(): string
    {
        Assert::assertNotSame([], $this->queries, 'no query was issued');

        return $this->queries[array_key_last($this->queries)]['dql'];
    }
}
