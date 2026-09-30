<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ArrayLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BinaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BooleanLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ConditionalExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DescendantSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Directive;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DoExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DollarRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DynamicSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunParam;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Identifier;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ImportDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\IndexAccess;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InfixFunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\LambdaExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MatchExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MemberAccess;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ModuleRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MultiValueSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NamespaceSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Node;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NsDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NullLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NumberLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectEntry;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\OutputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RangeExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DateTimeLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RegexLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Script;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\StringLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeCoercion;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeReference;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\UnaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\VarDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;

class Evaluator
{
    /** Java-style (DataWeave) date pattern letters => PHP date() format characters. */
    private const JAVA_DATE_FORMAT = [
        'yyyy' => 'Y', 'yy' => 'y', 'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n',
        'dd' => 'd', 'd' => 'j', 'EEEE' => 'l', 'EEE' => 'D',
        'HH' => 'H', 'H' => 'G', 'hh' => 'h', 'h' => 'g', 'mm' => 'i', 'ss' => 's',
        'SSSSSS' => 'u', 'SSS' => 'v', 'a' => 'A',
        'XXX' => 'P', 'X' => 'P', 'Z' => 'O', 'z' => 'T',
    ];

    private Environment $env;
    private ?string $outputMimeType = null;
    private array $outputOptions = [];
    private array $inputTypes = [];
    private array $inputOptions = [];
    private array $namespaces = [];

    public function __construct(?Environment $env = null)
    {
        $this->env = $env ?? $this->createGlobalEnv();
    }

    public function getOutputMimeType(): ?string
    {
        return $this->outputMimeType;
    }

    public function getOutputOptions(): array
    {
        return $this->outputOptions;
    }

    public function getInputTypes(): array
    {
        return $this->inputTypes;
    }

    public function getInputOptions(): array
    {
        return $this->inputOptions;
    }

    public function evaluate(Script $script): Value
    {
        // Process directives
        foreach ($script->directives as $directive) {
            $this->processDirective($directive);
        }

        // Evaluate body
        return $this->eval($script->body);
    }

    private function processDirective(Directive $directive): void
    {
        if ($directive instanceof OutputDirective) {
            $this->outputMimeType = $directive->mimeType;
            $this->outputOptions = $directive->options;
        } elseif ($directive instanceof InputDirective) {
            $this->inputTypes[$directive->name] = $directive->mimeType;
            $this->inputOptions[$directive->name] = $directive->options;
        } elseif ($directive instanceof VarDirective) {
            $this->env->define($directive->name, $this->eval($directive->value));
        } elseif ($directive instanceof FunDirective) {
            $this->defineFunction($directive);
        } elseif ($directive instanceof NsDirective) {
            $this->namespaces[$directive->prefix] = $directive->uri;
        } elseif ($directive instanceof ImportDirective) {
            $this->processImport($directive);
        }
    }

    private function defineFunction(FunDirective $directive): void
    {
        $params = $directive->params;
        $body = $directive->body;
        $closure = $this->env;

        $fn = function (array $args) use ($params, $body, $closure): Value {
            $local = $closure->child();
            foreach ($params as $i => $param) {
                $val = $args[$i] ?? ($param->defaultValue ? $this->eval($param->defaultValue) : Value::null());
                $local->define($param->name, $val);
            }
            $savedEnv = $this->env;
            $this->env = $local;
            $result = $this->eval($body);
            $this->env = $savedEnv;
            return $result;
        };

        $this->env->define($directive->name, Value::func($fn));
    }

    private function processImport(ImportDirective $directive): void
    {
        $module = StandardLibrary::getModule($directive->module);
        if ($module === null) {
            return; // Module not found, skip silently for now
        }

        if ($directive->importAll) {
            foreach ($module as $name => $value) {
                $this->env->define($name, $value);
            }
        } else {
            foreach ($directive->names as $name) {
                if (isset($module[$name])) {
                    $this->env->define($name, $module[$name]);
                }
            }
        }
    }

    public function eval(Node $node): Value
    {
        return match (true) {
            $node instanceof NumberLiteral => Value::number($node->value),
            $node instanceof StringLiteral => Value::string($node->value),
            $node instanceof BooleanLiteral => Value::boolean($node->value),
            $node instanceof NullLiteral => Value::null(),
            $node instanceof RegexLiteral => Value::regex($node->pattern),
            $node instanceof DateTimeLiteral => $this->evalDateTimeLiteral($node),
            $node instanceof ArrayLiteral => $this->evalArray($node),
            $node instanceof ObjectLiteral => $this->evalObject($node),
            $node instanceof Identifier => $this->evalIdentifier($node),
            $node instanceof DollarRef => $this->evalDollarRef($node),
            $node instanceof BinaryOp => $this->evalBinaryOp($node),
            $node instanceof UnaryOp => $this->evalUnaryOp($node),
            $node instanceof FunctionCall => $this->evalFunctionCall($node),
            $node instanceof InfixFunctionCall => $this->evalInfixFunctionCall($node),
            $node instanceof MemberAccess => $this->evalMemberAccess($node),
            $node instanceof IndexAccess => $this->evalIndexAccess($node),
            $node instanceof MultiValueSelector => $this->evalMultiValueSelector($node),
            $node instanceof DescendantSelector => $this->evalDescendantSelector($node),
            $node instanceof ConditionalExpression => $this->evalConditional($node),
            $node instanceof LambdaExpression => $this->evalLambda($node),
            $node instanceof DoExpression => $this->evalDo($node),
            $node instanceof TypeCoercion => $this->evalTypeCoercion($node),
            $node instanceof RangeExpression => $this->evalRange($node),
            $node instanceof ModuleRef => $this->evalModuleRef($node),
            $node instanceof MatchExpression => $this->evalMatch($node),
            $node instanceof NamespaceSelector => Value::string($node->prefix . '#' . $node->localName),
            default => throw new RuntimeException('Cannot evaluate node of type ' . get_class($node)),
        };
    }

    private function evalArray(ArrayLiteral $node): Value
    {
        $elements = array_map(fn(Node $el) => $this->eval($el), $node->elements);
        return Value::array($elements);
    }

    private function evalObject(ObjectLiteral $node): Value
    {
        $entries = [];
        foreach ($node->entries as $entry) {
            if ($entry->conditional) {
                // Dynamic/spread entry: (expr)
                foreach ($this->spreadEntries($this->eval($entry->key)) as $pair) {
                    $entries[] = $pair;
                }
                continue;
            }

            $key = $this->evalObjectKey($entry);
            $value = $this->eval($entry->value);
            $entries[] = [$key, $value];
        }
        return Value::object($entries);
    }

    /**
     * The pairs a spread entry contributes: an object's own pairs, the pairs of every object in an
     * array; anything else contributes nothing.
     *
     * @return array<int, array{0: Value, 1: Value}>
     */
    private function spreadEntries(Value $val): array
    {
        if ($val->type === ValueType::Object) {
            return $val->data;
        }
        $pairs = [];
        foreach ($val->type === ValueType::Array ? $val->data : [] as $item) {
            if ($item->type === ValueType::Object) {
                foreach ($item->data as $pair) {
                    $pairs[] = $pair;
                }
            }
        }
        return $pairs;
    }

    /**
     * Identifier keys in objects are treated as string literal keys, not variable references —
     * but dynamic keys (from parenthesized expressions) are evaluated as expressions.
     */
    private function evalObjectKey(ObjectEntry $entry): Value
    {
        return !$entry->dynamicKey && $entry->key instanceof Identifier
            ? Value::string($entry->key->name)
            : $this->eval($entry->key);
    }

    private function evalIdentifier(Identifier $node): Value
    {
        $val = $this->env->get($node->name);
        if ($val === null) {
            throw new RuntimeException("Undefined variable: '{$node->name}' at line {$node->line}");
        }
        return $val;
    }

    private function evalDollarRef(DollarRef $node): Value
    {
        $name = match ($node->index) {
            1 => '$',
            2 => '$$',
            3 => '$$$',
            default => '$',
        };
        $val = $this->env->get($name);
        if ($val === null) {
            throw new RuntimeException("Dollar reference '{$name}' not in scope");
        }
        return $val;
    }

    private function evalBinaryOp(BinaryOp $node): Value
    {
        $left = $this->eval($node->left);
        $right = $this->eval($node->right);

        return match ($node->operator) {
            '+' => $this->add($left, $right),
            '-' => $this->subtract($left, $right),
            '*' => Value::number($this->toNumber($left) * $this->toNumber($right)),
            '/' => Value::number($this->toNumber($right) != 0 ? $this->toNumber($left) / $this->toNumber($right) : throw new RuntimeException('Division by zero')),
            '%' => Value::number((int) $this->toNumber($left) % (int) $this->toNumber($right)),
            '++' => $this->concat($left, $right),
            '--' => $this->removeAll($left, $right),
            '==' => Value::boolean($this->isEqual($left, $right)),
            '!=' => Value::boolean(!$this->isEqual($left, $right)),
            '~=' => Value::boolean($this->isSimilar($left, $right)),
            '>' => Value::boolean(Value::compare($left, $right) > 0),
            '>=' => Value::boolean(Value::compare($left, $right) >= 0),
            '<' => Value::boolean(Value::compare($left, $right) < 0),
            '<=' => Value::boolean(Value::compare($left, $right) <= 0),
            'and' => Value::boolean($left->isTruthy() && $right->isTruthy()),
            'or' => Value::boolean($left->isTruthy() || $right->isTruthy()),
            'is' => $this->evalIsType($left, $right),
            default => throw new RuntimeException("Unknown operator: {$node->operator}"),
        };
    }

    private function add(Value $left, Value $right): Value
    {
        if ($left->type === ValueType::Number && $right->type === ValueType::Number) {
            return Value::number($left->data + $right->data);
        }
        $shifted = $this->shiftByPeriod($left, $right) ?? $this->shiftByPeriod($right, $left);
        if ($shifted !== null) {
            return $shifted;
        }
        return Value::string($this->toString($left) . $this->toString($right));
    }

    /**
     * The `~=` similarity: equal IGNORING type — DataWeave coerces one side to the other before
     * comparing, so "1" ~= 1, "1.0" ~= 1 and "true" ~= true all hold. Strict equality short-cuts;
     * numbers against numeric strings compare numerically (so "1.0" matches 1); everything else
     * scalar compares by string form. Structures and nulls are only ever equal to themselves.
     */
    private function isSimilar(Value $left, Value $right): bool
    {
        $structural = [ValueType::Array, ValueType::Object, ValueType::Function, ValueType::Null];

        return $this->isEqual($left, $right)
            || (!in_array($left->type, $structural, true) && !in_array($right->type, $structural, true)
                && $this->isSimilarScalar($left, $right));
    }

    private function isSimilarScalar(Value $left, Value $right): bool
    {
        if ($left->type === ValueType::Number || $right->type === ValueType::Number) {
            $l = trim($this->toString($left));
            $r = trim($this->toString($right));

            return is_numeric($l) && is_numeric($r) && (float) $l === (float) $r;
        }

        return $this->toString($left) === $this->toString($right);
    }

    private function subtract(Value $left, Value $right): Value
    {
        return match (true) {
            // Object - "key": a new object without every pair of that key (objects are pair lists
            // and may carry duplicate keys). `obj - 'k' ++ {k: v}` is the DataWeave way to replace
            // a key.
            $left->type === ValueType::Object && $right->type === ValueType::String => Value::object(array_values(array_filter(
                $left->data,
                fn(array $pair) => $this->toString($pair[0]) !== $right->data
            ))),
            // Array - item: a new array without every occurrence of the item (equality, not
            // identity — same matching as `--`).
            $left->type === ValueType::Array => Value::array(array_values(array_filter(
                $left->data,
                fn(Value $item) => !$this->isEqual($item, $right)
            ))),
            default => $this->shiftByPeriod($left, $right, true)
                ?? Value::number($this->toNumber($left) - $this->toNumber($right)),
        };
    }

    /**
     * DateTime ± Period arithmetic: shifts the temporal operand by the period (a negative period
     * flips the direction). Null temporals propagate as null — a missing date stays missing, so
     * a downstream `default` can catch it. Returns null when the pair is not temporal+period.
     */
    private function shiftByPeriod(Value $temporal, Value $period, bool $subtract = false): ?Value
    {
        if ($period->type !== ValueType::Period) {
            return null;
        }

        return match ($temporal->type) {
            ValueType::Null => Value::null(),
            ValueType::DateTime, ValueType::Date => $this->applyPeriod($temporal, $period, $subtract),
            default => null,
        };
    }

    private function applyPeriod(Value $temporal, Value $period, bool $subtract): Value
    {
        /** @var \DateTimeImmutable $dateTime */
        $dateTime = $temporal->data;
        /** @var \DateInterval $interval */
        $interval = $period->data;
        $plain = clone $interval;
        $plain->invert = 0;
        $backwards = $subtract !== ($interval->invert === 1);

        return Value::dateTime(
            $backwards ? $dateTime->sub($plain) : $dateTime->add($plain),
            $temporal->attributes['kind'] ?? 'datetime'
        );
    }

    private function concat(Value $left, Value $right): Value
    {
        return match (true) {
            $left->type === ValueType::String && $right->type === ValueType::String => Value::string($left->data . $right->data),
            $left->type === ValueType::Array && $right->type === ValueType::Array => Value::array(array_merge($left->data, $right->data)),
            $left->type === ValueType::Object && $right->type === ValueType::Object => Value::object(array_merge($left->data, $right->data)),
            $left->type === ValueType::Array => Value::array(array_merge($left->data, [$right])),
            $right->type === ValueType::Array => Value::array(array_merge([$left], $right->data)),
            default => Value::string($this->toString($left) . $this->toString($right)),
        };
    }

    private function removeAll(Value $left, Value $right): Value
    {
        if ($left->type === ValueType::Array && $right->type === ValueType::Array) {
            return Value::array(array_values(array_filter(
                $left->data,
                fn(Value $item) => !$this->arrayContains($right, $item)
            )));
        }
        if ($left->type === ValueType::Object && $right->type === ValueType::Array) {
            $entries = array_filter(
                $left->data,
                fn(array $pair) => !$this->arrayContainsString($right, $this->toString($pair[0]))
            );
            return Value::object(array_values($entries));
        }
        return $left;
    }

    private function arrayContains(Value $array, Value $item): bool
    {
        foreach ($array->data as $el) {
            if ($this->isEqual($el, $item)) {
                return true;
            }
        }
        return false;
    }

    private function arrayContainsString(Value $array, string $str): bool
    {
        foreach ($array->data as $el) {
            if ($this->toString($el) === $str) {
                return true;
            }
        }
        return false;
    }

    private function isEqual(Value $a, Value $b): bool
    {
        return match (true) {
            $a->type === ValueType::Null && $b->type === ValueType::Null => true,
            $a->type !== $b->type => false,
            $a->type === ValueType::Array => $this->arraysEqual($a->data, $b->data),
            $a->type === ValueType::Object => $this->objectsEqual($a->data, $b->data),
            default => $a->data === $b->data,
        };
    }

    /**
     * @param Value[] $a
     * @param Value[] $b
     */
    private function arraysEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $v) {
            if (!$this->isEqual($v, $b[$i])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pairwise and in order: objects are pair lists, so {a:1, b:2} and {b:2, a:1} differ.
     *
     * @param array<int, array{0: Value, 1: Value}> $a
     * @param array<int, array{0: Value, 1: Value}> $b
     */
    private function objectsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => [$ka, $va]) {
            [$kb, $vb] = $b[$i];
            if (!$this->isEqual($ka, $kb) || !$this->isEqual($va, $vb)) {
                return false;
            }
        }
        return true;
    }

    private function evalIsType(Value $left, Value $right): Value
    {
        $typeName = $right instanceof Value ? $this->toString($right) : '';
        $matches = match (strtolower($typeName)) {
            'string' => $left->type === ValueType::String,
            'number' => $left->type === ValueType::Number,
            'boolean' => $left->type === ValueType::Boolean,
            'null' => $left->type === ValueType::Null,
            'array' => $left->type === ValueType::Array,
            'object' => $left->type === ValueType::Object,
            'regex' => $left->type === ValueType::Regex,
            default => false,
        };
        return Value::boolean($matches);
    }

    private function evalUnaryOp(UnaryOp $node): Value
    {
        $operand = $this->eval($node->operand);
        return match ($node->operator) {
            '-' => Value::number(-$this->toNumber($operand)),
            'not', '!' => Value::boolean(!$operand->isTruthy()),
            default => throw new RuntimeException("Unknown unary operator: {$node->operator}"),
        };
    }

    private function evalFunctionCall(FunctionCall $node): Value
    {
        $callee = $this->eval($node->callee);
        if ($callee->type !== ValueType::Function) {
            throw new RuntimeException('Cannot call non-function value');
        }
        $args = array_map(fn(Node $arg) => $this->eval($arg), $node->args);
        return ($callee->data)($args);
    }

    private function evalInfixFunctionCall(InfixFunctionCall $node): Value
    {
        // Check if this is a known builtin infix function first — builtins handle
        // lazy evaluation of the right side (needed for dollar scope / lambdas)
        $builtinInfix = [
            'map', 'flatMap', 'filter', 'reduce', 'groupBy', 'orderBy',
            'distinctBy', 'mapObject', 'pluck', 'joinBy', 'splitBy',
            'contains', 'startsWith', 'endsWith', 'replace', 'to',
            'default', 'match', 'matches', 'maxBy', 'minBy', 'every', 'some',
        ];
        if (in_array($node->name, $builtinInfix, true)) {
            return $this->evalBuiltinInfix($node);
        }

        $fn = $this->env->get($node->name);
        if ($fn !== null && $fn->type === ValueType::Function) {
            $left = $this->eval($node->left);
            $right = $this->eval($node->right);
            return ($fn->data)([$left, $right]);
        }

        // Fall back to built-in infix functions
        return $this->evalBuiltinInfix($node);
    }

    private function evalBuiltinInfix(InfixFunctionCall $node): Value
    {
        $left = $this->eval($node->left);

        return match ($node->name) {
            'map' => $this->builtinMap($left, $node->right),
            'flatMap' => $this->builtinFlatMap($left, $node->right),
            'filter' => $this->builtinFilter($left, $node->right),
            'reduce' => $this->builtinReduce($left, $node->right),
            'groupBy' => $this->builtinGroupBy($left, $node->right),
            'orderBy' => $this->builtinOrderBy($left, $node->right),
            'distinctBy' => $this->builtinDistinctBy($left, $node->right),
            'mapObject' => $this->builtinMapObject($left, $node->right),
            'pluck' => $this->builtinPluck($left, $node->right),
            'joinBy' => $this->builtinJoinBy($left, $node->right),
            'splitBy' => $this->builtinSplitBy($left, $node->right),
            'contains' => $this->builtinContains($left, $node->right),
            'startsWith' => $this->builtinStartsWith($left, $node->right),
            'endsWith' => $this->builtinEndsWith($left, $node->right),
            'replace' => $this->builtinReplace($left, $node->right),
            'to' => $this->builtinTo($left, $node->right),
            'default' => $left->type === ValueType::Null ? $this->eval($node->right) : $left,
            'match' => $this->builtinMatch($left, $node->right),
            'matches' => $this->builtinMatches($left, $node->right),
            'maxBy' => $this->builtinMaxBy($left, $node->right),
            'minBy' => $this->builtinMinBy($left, $node->right),
            'every' => $this->builtinEvery($left, $node->right),
            'some' => $this->builtinSome($left, $node->right),
            default => throw new RuntimeException("Unknown infix function: {$node->name}"),
        };
    }

    private function builtinMap(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'map');
        $results = [];
        foreach ($left->data as $i => $item) {
            $results[] = $this->callWithDollarScope($right, $item, Value::number($i));
        }
        return Value::array($results);
    }

    private function builtinFlatMap(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'flatMap');
        $results = [];
        foreach ($left->data as $i => $item) {
            $result = $this->callWithDollarScope($right, $item, Value::number($i));
            if ($result->type === ValueType::Array) {
                foreach ($result->data as $el) {
                    $results[] = $el;
                }
            } else {
                $results[] = $result;
            }
        }
        return Value::array($results);
    }

    private function builtinFilter(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'filter');
        $results = [];
        foreach ($left->data as $i => $item) {
            $result = $this->callWithDollarScope($right, $item, Value::number($i));
            if ($result->isTruthy()) {
                $results[] = $item;
            }
        }
        return Value::array($results);
    }

    private function builtinReduce(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'reduce');
        $items = $left->data;
        if (empty($items)) {
            // If lambda has a default for accumulator, return that
            if ($right instanceof LambdaExpression && count($right->params) >= 2 && $right->params[1]->defaultValue !== null) {
                return $this->eval($right->params[1]->defaultValue);
            }
            return Value::null();
        }

        // Check if the lambda/expression has a default accumulator value
        if ($right instanceof LambdaExpression && count($right->params) >= 2 && $right->params[1]->defaultValue !== null) {
            // Use the default value as initial accumulator, iterate from index 0
            $acc = $this->eval($right->params[1]->defaultValue);
            $startIdx = 0;
        } else {
            // No default: first element is accumulator, start from index 1
            $acc = $items[0];
            $startIdx = 1;
        }

        for ($i = $startIdx, $count = count($items); $i < $count; $i++) {
            $acc = $this->callWithDollarScope($right, $items[$i], $acc);
        }
        return $acc;
    }

    private function builtinGroupBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'groupBy');
        $groups = [];
        $groupOrder = [];
        foreach ($left->data as $i => $item) {
            $key = $this->callWithDollarScope($right, $item, Value::number($i));
            $keyStr = $key->type === ValueType::String ? $key->data : $this->toString($key);
            if (!isset($groups[$keyStr])) {
                $groups[$keyStr] = [];
                $groupOrder[] = $keyStr;
            }
            $groups[$keyStr][] = $item;
        }
        $entries = [];
        foreach ($groupOrder as $key) {
            $entries[] = [Value::string($key), Value::array($groups[$key])];
        }
        return Value::object($entries);
    }

    private function builtinOrderBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'orderBy');
        $items = $left->data;
        usort($items, function (Value $a, Value $b) use ($right) {
            $va = $this->callWithDollarScope($right, $a, Value::number(0));
            $vb = $this->callWithDollarScope($right, $b, Value::number(0));
            if ($va->type === ValueType::Number && $vb->type === ValueType::Number) {
                return $va->data <=> $vb->data;
            }
            return $this->toString($va) <=> $this->toString($vb);
        });
        return Value::array($items);
    }

    private function builtinDistinctBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'distinctBy');
        $seen = [];
        $results = [];
        foreach ($left->data as $i => $item) {
            $keyVal = $this->callWithDollarScope($right, $item, Value::number($i));
            $key = $keyVal->type === ValueType::String ? $keyVal->data : $this->toString($keyVal);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $results[] = $item;
            }
        }
        return Value::array($results);
    }

    private function builtinMapObject(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Object, 'mapObject');
        $entries = [];
        foreach ($left->data as $i => [$key, $value]) {
            $result = $this->callWithDollarScope($right, $value, $key, Value::number($i));
            if ($result->type === ValueType::Object) {
                foreach ($result->data as $pair) {
                    $entries[] = $pair;
                }
            }
        }
        return Value::object($entries);
    }

    private function builtinPluck(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Object, 'pluck');
        $results = [];
        foreach ($left->data as $i => [$key, $value]) {
            $results[] = $this->callWithDollarScope($right, $value, $key, Value::number($i));
        }
        return Value::array($results);
    }

    private function builtinJoinBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'joinBy');
        $separator = $this->toString($this->eval($right));
        $strings = array_map(fn(Value $v) => $this->toString($v), $left->data);
        return Value::string(implode($separator, $strings));
    }

    private function builtinSplitBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::String, 'splitBy');
        $separator = $this->toString($this->eval($right));
        $parts = explode($separator, $left->data);
        return Value::array(array_map(fn(string $s) => Value::string($s), $parts));
    }

    private function builtinContains(Value $left, Node $right): Value
    {
        $rightVal = $this->eval($right);
        if ($left->type === ValueType::String) {
            return Value::boolean(str_contains($left->data, $this->toString($rightVal)));
        }
        if ($left->type === ValueType::Array) {
            return Value::boolean($this->arrayContains($left, $rightVal));
        }
        return Value::boolean(false);
    }

    private function builtinStartsWith(Value $left, Node $right): Value
    {
        $rightVal = $this->toString($this->eval($right));
        return Value::boolean(str_starts_with($this->toString($left), $rightVal));
    }

    private function builtinEndsWith(Value $left, Node $right): Value
    {
        $rightVal = $this->toString($this->eval($right));
        return Value::boolean(str_ends_with($this->toString($left), $rightVal));
    }

    private function builtinReplace(Value $left, Node $right): Value
    {
        // replace is typically: str replace pattern with replacement
        // For now, handle simple case
        $rightVal = $this->eval($right);
        if ($rightVal->type === ValueType::Regex) {
            return Value::string(preg_replace('/' . $rightVal->data . '/', '', $this->toString($left)) ?? $this->toString($left));
        }
        return Value::string(str_replace($this->toString($rightVal), '', $this->toString($left)));
    }

    private function builtinTo(Value $left, Node $right): Value
    {
        $start = (int) $this->toNumber($left);
        $end = (int) $this->toNumber($this->eval($right));
        $range = [];
        if ($start <= $end) {
            for ($i = $start; $i <= $end; $i++) {
                $range[] = Value::number($i);
            }
        } else {
            for ($i = $start; $i >= $end; $i--) {
                $range[] = Value::number($i);
            }
        }
        return Value::array($range);
    }

    private function builtinMatch(Value $left, Node $right): Value
    {
        if ($right instanceof MatchExpression || ($right instanceof ObjectLiteral)) {
            // Evaluate match cases
            return $this->eval($right);
        }
        $rightVal = $this->eval($right);
        if ($rightVal->type === ValueType::Regex) {
            preg_match('/' . $rightVal->data . '/', $this->toString($left), $matches);
            return Value::array(array_map(fn($m) => Value::string($m), $matches));
        }
        return Value::null();
    }

    private function builtinMatches(Value $left, Node $right): Value
    {
        $rightVal = $this->eval($right);
        if ($rightVal->type === ValueType::Regex) {
            return Value::boolean((bool) preg_match('/^' . $rightVal->data . '$/', $this->toString($left)));
        }
        return Value::boolean(false);
    }

    private function builtinMaxBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'maxBy');
        if (empty($left->data)) {
            return Value::null();
        }
        $max = $left->data[0];
        $maxVal = $this->callWithDollarScope($right, $max, Value::number(0));
        foreach ($left->data as $i => $item) {
            $val = $this->callWithDollarScope($right, $item, Value::number($i));
            if ($this->toNumber($val) > $this->toNumber($maxVal)) {
                $max = $item;
                $maxVal = $val;
            }
        }
        return $max;
    }

    private function builtinMinBy(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'minBy');
        if (empty($left->data)) {
            return Value::null();
        }
        $min = $left->data[0];
        $minVal = $this->callWithDollarScope($right, $min, Value::number(0));
        foreach ($left->data as $i => $item) {
            $val = $this->callWithDollarScope($right, $item, Value::number($i));
            if ($this->toNumber($val) < $this->toNumber($minVal)) {
                $min = $item;
                $minVal = $val;
            }
        }
        return $min;
    }

    private function builtinEvery(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'every');
        foreach ($left->data as $i => $item) {
            $result = $this->callWithDollarScope($right, $item, Value::number($i));
            if (!$result->isTruthy()) {
                return Value::boolean(false);
            }
        }
        return Value::boolean(true);
    }

    private function builtinSome(Value $left, Node $right): Value
    {
        $this->assertType($left, ValueType::Array, 'some');
        foreach ($left->data as $i => $item) {
            $result = $this->callWithDollarScope($right, $item, Value::number($i));
            if ($result->isTruthy()) {
                return Value::boolean(true);
            }
        }
        return Value::boolean(false);
    }

    private function callWithDollarScope(Node $expr, Value $dollar, ?Value $dollarDollar = null, ?Value $dollarDollarDollar = null): Value
    {
        if ($expr instanceof LambdaExpression) {
            $this->env->pushScope();
            foreach ($expr->params as $i => $param) {
                $val = match ($i) {
                    0 => $dollar,
                    1 => $dollarDollar ?? Value::null(),
                    2 => $dollarDollarDollar ?? Value::null(),
                    default => Value::null(),
                };
                $this->env->define($param->name, $val);
            }
            $result = $this->eval($expr->body);
            $this->env->popScope();
            return $result;
        }

        // Implicit lambda with $ $$ $$$
        $this->env->pushScope();
        $this->env->define('$', $dollar);
        if ($dollarDollar !== null) {
            $this->env->define('$$', $dollarDollar);
        }
        if ($dollarDollarDollar !== null) {
            $this->env->define('$$$', $dollarDollarDollar);
        }
        $result = $this->eval($expr);
        $this->env->popScope();
        return $result;
    }

    private function evalMemberAccess(MemberAccess $node): Value
    {
        $obj = $this->eval($node->object);
        $prop = $node->property;
        if ($obj->type === ValueType::Object) {
            return $obj->getKey($prop) ?? Value::null();
        }
        if ($obj->type === ValueType::Array) {
            return $this->selectFromEach($obj, $prop);
        }
        return Value::null();
    }

    /** Array of objects: the property of each object element (every pair with that key). */
    private function selectFromEach(Value $array, string $property): Value
    {
        $results = [];
        foreach ($array->data as $item) {
            if ($item->type !== ValueType::Object) {
                continue;
            }
            foreach ($item->data as [$key, $value]) {
                if ($this->toString($key) === $property) {
                    $results[] = $value;
                }
            }
        }
        return Value::array($results);
    }

    private function evalIndexAccess(IndexAccess $node): Value
    {
        $obj = $this->eval($node->object);
        $index = $this->eval($node->index);

        return match ($obj->type) {
            ValueType::Array => $this->elementAt($obj, $index),
            ValueType::Object => $obj->getKey($index->type === ValueType::String ? $index->data : $this->toString($index))
                ?? Value::null(),
            ValueType::String => $this->characterAt($obj, $index),
            default => Value::null(),
        };
    }

    /** A negative index counts from the end; out of range is null. */
    private function elementAt(Value $array, Value $index): Value
    {
        $i = (int) $this->toNumber($index);
        if ($i < 0) {
            $i = count($array->data) + $i;
        }
        return $array->data[$i] ?? Value::null();
    }

    /** A negative index counts from the end; out of range is the empty string. */
    private function characterAt(Value $string, Value $index): Value
    {
        $i = (int) $this->toNumber($index);
        if ($i < 0) {
            $i = strlen($string->data) + $i;
        }
        return Value::string($string->data[$i] ?? '');
    }

    private function evalMultiValueSelector(MultiValueSelector $node): Value
    {
        $obj = $this->eval($node->object);
        if ($obj->type === ValueType::Object) {
            $results = [];
            foreach ($obj->data as [$key, $value]) {
                if ($this->toString($key) === $node->property) {
                    $results[] = $value;
                }
            }
            return Value::array($results);
        }
        return Value::array([]);
    }

    private function evalDescendantSelector(DescendantSelector $node): Value
    {
        $obj = $this->eval($node->object);
        $results = [];
        $this->collectDescendants($obj, $node->property, $results);
        return Value::array($results);
    }

    private function collectDescendants(Value $value, string $property, array &$results): void
    {
        if ($value->type === ValueType::Object) {
            foreach ($value->data as [$key, $val]) {
                if ($this->toString($key) === $property) {
                    $results[] = $val;
                }
                // Recurse into nested values
                $this->collectDescendants($val, $property, $results);
            }
        } elseif ($value->type === ValueType::Array) {
            foreach ($value->data as $item) {
                $this->collectDescendants($item, $property, $results);
            }
        }
    }

    private function evalConditional(ConditionalExpression $node): Value
    {
        $condition = $this->eval($node->condition);
        return $condition->isTruthy()
            ? $this->eval($node->thenBranch)
            : $this->eval($node->elseBranch);
    }

    private function evalLambda(LambdaExpression $node): Value
    {
        $params = $node->params;
        $body = $node->body;
        $closure = $this->env;

        return Value::func(function (array $args) use ($params, $body, $closure): Value {
            $local = $closure->child();
            foreach ($params as $i => $param) {
                $local->define($param->name, $args[$i] ?? Value::null());
            }
            $savedEnv = $this->env;
            $this->env = $local;
            $result = $this->eval($body);
            $this->env = $savedEnv;
            return $result;
        });
    }

    private function evalDo(DoExpression $node): Value
    {
        $local = $this->env->child();
        $savedEnv = $this->env;
        $this->env = $local;

        foreach ($node->directives as $directive) {
            $this->processDirective($directive);
        }

        $result = $this->eval($node->body);
        $this->env = $savedEnv;
        return $result;
    }

    private function evalTypeCoercion(TypeCoercion $node): Value
    {
        $value = $this->eval($node->expression);
        $typeName = strtolower($node->targetType->name);
        if ($value->type === ValueType::Null) {
            return Value::null(); // null flows through coercions (pairs with `default`)
        }
        $format = $node->targetType->metadata['format'] ?? null;
        $format = \is_string($format) && $format !== '' ? $format : null;

        return match ($typeName) {
            'string' => Value::string($this->coerceToString($value, $format)),
            'number' => Value::number($this->toNumber($value)),
            'boolean' => Value::boolean($value->isTruthy()),
            'date', 'time', 'localtime', 'datetime', 'localdatetime'
                => $this->coerceToTemporal($value, $typeName, $format, $node->line),
            'period' => $value->type === ValueType::Period
                ? $value
                : $this->parsePeriod($this->toString($value), $node->line),
            default => $value, // Pass through for unknown types
        };
    }

    /** `as String {format: ...}`: temporal values honor the (Java-style) format; others as usual. */
    private function coerceToString(Value $value, ?string $format): string
    {
        if (($value->type === ValueType::DateTime || $value->type === ValueType::Date) && $format !== null) {
            return $value->data->format(self::javaDateFormatToPhp($format));
        }
        return $this->toString($value);
    }

    /** `as LocalDateTime {format: ...}` and friends: parses strings, re-kinds temporal values. */
    private function coerceToTemporal(Value $value, string $target, ?string $format, int $line): Value
    {
        $kind = match ($target) {
            'date' => 'date',
            'time' => 'time',
            'localtime' => 'localtime',
            'localdatetime' => 'local_datetime',
            default => 'datetime',
        };
        if ($value->type === ValueType::DateTime || $value->type === ValueType::Date) {
            return Value::dateTime($value->data, $kind);
        }
        $text = $this->toString($value);
        if ($format !== null) {
            // '!' resets the fields the format does not mention (instead of "now" bleeding in).
            $dateTime = \DateTimeImmutable::createFromFormat('!' . self::javaDateFormatToPhp($format), $text);
            if ($dateTime === false) {
                throw new RuntimeException("Cannot parse '$text' as $target with format \"$format\" at line $line");
            }
            return Value::dateTime($dateTime, $kind);
        }
        return $this->parseTemporal($text, $kind, $line);
    }

    /** A |…| literal: an ISO-8601 period (|PT1S|) or a date/time (|2003-10-01T23:57:59Z|). */
    private function evalDateTimeLiteral(DateTimeLiteral $node): Value
    {
        if (preg_match('/^-?P/i', $node->text)) {
            return $this->parsePeriod($node->text, $node->line);
        }
        return $this->parseTemporal($node->text, null, $node->line);
    }

    private function parsePeriod(string $iso, int $line): Value
    {
        $negative = str_starts_with($iso, '-');
        $spec = strtoupper(ltrim($iso, '-'));
        // DateInterval rejects fractional seconds — carried separately via its `f` property.
        $fraction = 0.0;
        if (preg_match('/(\d+)\.(\d+)S/', $spec, $m)) {
            $fraction = (float) ('0.' . $m[2]);
            $spec = str_replace($m[0], $m[1] . 'S', $spec);
        }
        try {
            $interval = new \DateInterval($spec);
        } catch (\Exception) {
            throw new RuntimeException("Invalid period '|$iso|' at line $line");
        }
        $interval->f = $fraction;
        if ($negative) {
            $interval->invert = 1;
        }
        return Value::period($interval, $iso);
    }

    private function parseTemporal(string $text, ?string $kind, int $line): Value
    {
        try {
            if (preg_match('/^\d{2}:\d{2}/', $text)) { // time-only, anchored to the epoch date
                return Value::dateTime(new \DateTimeImmutable('1970-01-01 ' . $text), $kind ?? 'time');
            }
            $dateTime = new \DateTimeImmutable($text);
        } catch (\Exception) {
            throw new RuntimeException("Invalid date/time '|$text|' at line $line");
        }
        // Without an explicit target the text's own shape decides the flavor.
        $kind ??= self::temporalKindOf($text);

        return Value::dateTime($dateTime, $kind);
    }

    /** No time part is a date; a time with a zone/offset a datetime; one without a local datetime. */
    private static function temporalKindOf(string $text): string
    {
        return match (true) {
            !str_contains($text, ':') => 'date',
            preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $text) === 1 => 'datetime',
            default => 'local_datetime',
        };
    }

    /**
     * Translates the (Java-style) DataWeave date format patterns to PHP's date() syntax:
     * "yyyy-MM-dd HH:mm:ss.SSSSSS" → "Y-m-d H:i:s.u". Single-quoted literals ('T') and unmapped
     * letters come out backslash-escaped; runs match their longest known prefix.
     */
    private static function javaDateFormatToPhp(string $format): string
    {
        $out = '';
        $i = 0;
        $len = \strlen($format);
        while ($i < $len) {
            $ch = $format[$i];
            if ($ch === "'") {
                [$piece, $i] = self::quotedFormatLiteral($format, $i + 1);
            } elseif (!ctype_alpha($ch)) {
                $piece = $ch;
                $i++;
            } else {
                $run = str_repeat($ch, strspn($format, $ch, $i));
                $i += \strlen($run);
                $piece = self::formatLetterRun($run);
            }
            $out .= $piece;
        }
        return $out;
    }

    /**
     * The escaped text of a quoted literal starting at $start (just past its opening quote), and
     * the position after its closing quote ('' renders one literal quote as an escaped nothing).
     *
     * @return array{0: string, 1: int}
     */
    private static function quotedFormatLiteral(string $format, int $start): array
    {
        $out = '';
        $i = $start;
        $len = \strlen($format);
        while ($i < $len && $format[$i] !== "'") {
            $out .= '\\' . $format[$i];
            $i++;
        }
        return [$out, $i + 1];
    }

    /** A run of one pattern letter, mapped by its longest known prefix; unmapped letters are escaped. */
    private static function formatLetterRun(string $run): string
    {
        $out = '';
        while ($run !== '') {
            $piece = $run;
            while ($piece !== '' && !isset(self::JAVA_DATE_FORMAT[$piece])) {
                $piece = substr($piece, 0, -1);
            }
            if ($piece === '') {
                $out .= '\\' . $run[0];
                $run = substr($run, 1);
            } else {
                $out .= self::JAVA_DATE_FORMAT[$piece];
                $run = substr($run, \strlen($piece));
            }
        }
        return $out;
    }

    private function evalRange(RangeExpression $node): Value
    {
        $start = (int) $this->toNumber($this->eval($node->start));
        $end = (int) $this->toNumber($this->eval($node->end));
        $range = [];
        if ($start <= $end) {
            for ($i = $start; $i <= $end; $i++) {
                $range[] = Value::number($i);
            }
        } else {
            for ($i = $start; $i >= $end; $i--) {
                $range[] = Value::number($i);
            }
        }
        return Value::array($range);
    }

    private function evalModuleRef(ModuleRef $node): Value
    {
        $module = StandardLibrary::getModule($node->module);
        if ($module !== null && isset($module[$node->member])) {
            return $module[$node->member];
        }
        throw new RuntimeException("Cannot resolve {$node->module}::{$node->member}");
    }

    private function evalMatch(MatchExpression $node): Value
    {
        $value = $this->eval($node->value);
        foreach ($node->cases as $case) {
            if ($case->isDefault) {
                return $this->eval($case->body);
            }
            $pattern = $this->eval($case->pattern);
            if ($this->isEqual($value, $pattern)) {
                return $this->eval($case->body);
            }
        }
        return Value::null();
    }

    // === Type conversion helpers ===

    private function toString(Value $value): string
    {
        return match ($value->type) {
            ValueType::String => $value->data,
            ValueType::Number => $this->formatNumber($value->data),
            ValueType::Boolean => $value->data ? 'true' : 'false',
            ValueType::Null => 'null',
            ValueType::Array => json_encode(array_map(fn(Value $v) => $this->toString($v), $value->data)),
            ValueType::Object => json_encode($this->objectToString($value)),
            ValueType::Date, ValueType::DateTime => Value::formatTemporal($value),
            ValueType::Period => (string) ($value->attributes['iso'] ?? ''),
            default => is_scalar($value->data) ? (string) $value->data : json_encode($value->data),
        };
    }

    private function objectToString(Value $value): array
    {
        $result = [];
        foreach ($value->data as [$key, $val]) {
            $result[$this->toString($key)] = $this->toString($val);
        }
        return $result;
    }

    private function formatNumber(float|int $num): string
    {
        if (is_int($num)) {
            return (string) $num;
        }
        if ($num == (int) $num) {
            return (string) (int) $num;
        }
        return rtrim(rtrim(sprintf('%.15g', $num), '0'), '.');
    }

    private function toNumber(Value $value): float|int
    {
        return match ($value->type) {
            ValueType::Number => $value->data,
            ValueType::String => $this->stringToNumber($value->data),
            ValueType::Boolean => $value->data ? 1 : 0,
            ValueType::Null => 0,
            default => 0,
        };
    }

    /** A numeric string becomes an int, or a float when it carries a decimal point; anything else 0. */
    private function stringToNumber(string $text): float|int
    {
        if (!is_numeric($text)) {
            return 0;
        }
        return str_contains($text, '.') ? (float) $text : (int) $text;
    }

    private function assertType(Value $value, ValueType $expected, string $context): void
    {
        if ($value->type !== $expected) {
            throw new RuntimeException(
                "{$context} expects {$expected->value} but got {$value->type->value}"
            );
        }
    }

    private function createGlobalEnv(): Environment
    {
        $env = new Environment();
        StandardLibrary::register($env);
        return $env;
    }
}
