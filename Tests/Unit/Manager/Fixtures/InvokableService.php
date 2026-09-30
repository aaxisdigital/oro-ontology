<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

/**
 * A service an "Invoke PHP" step can call: one method per parameter-binding rule under test.
 */
class InvokableService
{
    public function __construct()
    {
    }

    public function __destruct()
    {
    }

    public function scalars(int $count, float $ratio, string $label, bool $flag = false): array
    {
        return [$count, $ratio, $label, $flag];
    }

    public function loose(mixed $anything, array $list, iterable $items, $untyped, ?string $maybe = null): array
    {
        return [$anything, $list, $items, $untyped, $maybe];
    }

    public function union(int|string $key): string
    {
        return 'key:' . $key;
    }

    public function nullableUnion(int|string|null $key): string
    {
        return 'key:' . $key;
    }

    public function intersection(\Countable&\Traversable $things): int
    {
        return \count($things);
    }

    public function object(\DateTimeInterface $at): string
    {
        return $at->format('c');
    }

    public function nothing(): ?string
    {
        return null;
    }

    public function dto(): object
    {
        return (object) ['id' => 7, 'nested' => (object) ['ok' => true]];
    }

    public function listOfDtos(): array
    {
        return [(object) ['a' => 1], 'plain', 2];
    }

    public static function twice(int $n): int
    {
        return $n * 2;
    }

    public function explode(): never
    {
        throw new \LogicException('kaboom');
    }

    private function secret(): string
    {
        return 'hidden';
    }
}
