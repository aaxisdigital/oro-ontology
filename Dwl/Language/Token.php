<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Language;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column,
    ) {}

    public function __toString(): string
    {
        return sprintf('%s(%s) at %d:%d', $this->type->value, $this->value, $this->line, $this->column);
    }
}
