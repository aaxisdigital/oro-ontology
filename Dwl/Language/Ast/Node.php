<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast;

abstract class Node
{
    public function __construct(
        public readonly int $line = 0,
        public readonly int $column = 0,
    ) {}
}

// === Script structure ===

class Script extends Node
{
    /** @param Directive[] $directives */
    public function __construct(
        public readonly ?string $version,
        public readonly array $directives,
        public readonly Node $body,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

// === Directives ===

abstract class Directive extends Node {}

class OutputDirective extends Directive
{
    public function __construct(
        public readonly string $mimeType,
        public readonly array $options = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class InputDirective extends Directive
{
    public function __construct(
        public readonly string $name,
        public readonly string $mimeType,
        public readonly array $options = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class VarDirective extends Directive
{
    public function __construct(
        public readonly string $name,
        public readonly Node $value,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class FunDirective extends Directive
{
    /** @param FunParam[] $params */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly Node $body,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class FunParam
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly ?Node $defaultValue = null,
    ) {}
}

class TypeDirective extends Directive
{
    public function __construct(
        public readonly string $name,
        public readonly Node $definition,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class NsDirective extends Directive
{
    public function __construct(
        public readonly string $prefix,
        public readonly string $uri,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class ImportDirective extends Directive
{
    public function __construct(
        public readonly array $names,
        public readonly string $module,
        public readonly bool $importAll = false,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

// === Expressions ===

class NumberLiteral extends Node
{
    public function __construct(
        public readonly float|int $value,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class StringLiteral extends Node
{
    public function __construct(
        public readonly string $value,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

/** A |…| date/time/period literal — `text` is the raw content between the pipes. */
class DateTimeLiteral extends Node
{
    public function __construct(
        public readonly string $text,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class BooleanLiteral extends Node
{
    public function __construct(
        public readonly bool $value,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class NullLiteral extends Node {}

class RegexLiteral extends Node
{
    public function __construct(
        public readonly string $pattern,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class ArrayLiteral extends Node
{
    /** @param Node[] $elements */
    public function __construct(
        public readonly array $elements,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class ObjectLiteral extends Node
{
    /** @param ObjectEntry[] $entries */
    public function __construct(
        public readonly array $entries,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class ObjectEntry
{
    public function __construct(
        public readonly Node $key,
        public readonly Node $value,
        public readonly bool $conditional = false,
        public readonly ?Node $attributes = null,
        public readonly bool $dynamicKey = false,
    ) {}
}

class Identifier extends Node
{
    public function __construct(
        public readonly string $name,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class BinaryOp extends Node
{
    public function __construct(
        public readonly Node $left,
        public readonly string $operator,
        public readonly Node $right,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class UnaryOp extends Node
{
    public function __construct(
        public readonly string $operator,
        public readonly Node $operand,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class FunctionCall extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public readonly Node $callee,
        public readonly array $args,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class InfixFunctionCall extends Node
{
    public function __construct(
        public readonly Node $left,
        public readonly string $name,
        public readonly Node $right,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class MemberAccess extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly string $property,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class IndexAccess extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly Node $index,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class RangeExpression extends Node
{
    public function __construct(
        public readonly Node $start,
        public readonly Node $end,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class ConditionalExpression extends Node
{
    public function __construct(
        public readonly Node $condition,
        public readonly Node $thenBranch,
        public readonly Node $elseBranch,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class MatchExpression extends Node
{
    /** @param MatchCase[] $cases */
    public function __construct(
        public readonly Node $value,
        public readonly array $cases,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class MatchCase
{
    public function __construct(
        public readonly ?Node $pattern,
        public readonly Node $body,
        public readonly bool $isDefault = false,
        public readonly ?string $binding = null,
    ) {}
}

class LambdaExpression extends Node
{
    /** @param FunParam[] $params */
    public function __construct(
        public readonly array $params,
        public readonly Node $body,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class DoExpression extends Node
{
    /** @param Directive[] $directives */
    public function __construct(
        public readonly array $directives,
        public readonly Node $body,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class TypeReference extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly array $metadata = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class TypeCoercion extends Node
{
    public function __construct(
        public readonly Node $expression,
        public readonly TypeReference $targetType,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class DynamicSelector extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly Node $selector,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class DescendantSelector extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly string $property,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class MultiValueSelector extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly string $property,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class NamespaceSelector extends Node
{
    public function __construct(
        public readonly string $prefix,
        public readonly string $localName,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class DollarRef extends Node
{
    public function __construct(
        public readonly int $index, // 1 = $, 2 = $$, 3 = $$$
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}

class ModuleRef extends Node
{
    public function __construct(
        public readonly string $module,
        public readonly string $member,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
