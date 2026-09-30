<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures;

/**
 * A class for the "Invoke PHP" method type-ahead to reflect on: constructor, destructor and magic
 * methods must be skipped, abstract ones too; parameters carry their declared type (or "mixed").
 */
class SampleService
{
    public function __construct(private readonly string $dependency = 'x')
    {
    }

    public function __destruct()
    {
    }

    public function __toString(): string
    {
        return $this->dependency;
    }

    public function zulu(): void
    {
    }

    public function alpha(string $name, int|string $id, $untyped, ?array $options = null): string
    {
        return $name;
    }

    public static function mike(bool $flag): bool
    {
        return $flag;
    }

    protected function hidden(): void
    {
    }
}
