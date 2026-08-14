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
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\OutputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RangeExpression;
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
                $val = $this->eval($entry->key);
                if ($val->type === ValueType::Object) {
                    foreach ($val->data as $pair) {
                        $entries[] = $pair;
                    }
                } elseif ($val->type === ValueType::Array) {
                    foreach ($val->data as $item) {
                        if ($item->type === ValueType::Object) {
                            foreach ($item->data as $pair) {
                                $entries[] = $pair;
                            }
                        }
                    }
                }
                continue;
            }

            // Identifier keys in objects are treated as string literal keys, not variable references
            // But dynamic keys (from parenthesized expressions) are evaluated as expressions
            if ($entry->dynamicKey) {
                $key = $this->eval($entry->key);
            } elseif ($entry->key instanceof Identifier) {
                $key = Value::string($entry->key->name);
            } else {
                $key = $this->eval($entry->key);
            }
            $value = $this->eval($entry->value);
            $entries[] = [$key, $value];
        }
        return Value::object($entries);
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
        return Value::string($this->toString($left) . $this->toString($right));
    }

    private function subtract(Value $left, Value $right): Value
    {
        return Value::number($this->toNumber($left) - $this->toNumber($right));
    }

    private function concat(Value $left, Value $right): Value
    {
        if ($left->type === ValueType::String && $right->type === ValueType::String) {
            return Value::string($left->data . $right->data);
        }
        if ($left->type === ValueType::Array && $right->type === ValueType::Array) {
            return Value::array(array_merge($left->data, $right->data));
        }
        if ($left->type === ValueType::Object && $right->type === ValueType::Object) {
            return Value::object(array_merge($left->data, $right->data));
        }
        if ($left->type === ValueType::Array) {
            return Value::array(array_merge($left->data, [$right]));
        }
        if ($right->type === ValueType::Array) {
            return Value::array(array_merge([$left], $right->data));
        }
        return Value::string($this->toString($left) . $this->toString($right));
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
        if ($a->type === ValueType::Null && $b->type === ValueType::Null) {
            return true;
        }
        if ($a->type !== $b->type) {
            return false;
        }
        if ($a->type === ValueType::Array) {
            if (count($a->data) !== count($b->data)) {
                return false;
            }
            foreach ($a->data as $i => $v) {
                if (!$this->isEqual($v, $b->data[$i])) {
                    return false;
                }
            }
            return true;
        }
        if ($a->type === ValueType::Object) {
            if (count($a->data) !== count($b->data)) {
                return false;
            }
            foreach ($a->data as $i => [$ka, $va]) {
                [$kb, $vb] = $b->data[$i];
                if (!$this->isEqual($ka, $kb) || !$this->isEqual($va, $vb)) {
                    return false;
                }
            }
            return true;
        }
        return $a->data === $b->data;
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
        $startIdx = 0;
        $acc = $items[0];
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
            $rightVal = $this->eval($right);
            return $rightVal;
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
            // Array of objects: select property from each
            $results = [];
            foreach ($obj->data as $item) {
                if ($item->type === ValueType::Object) {
                    foreach ($item->data as [$key, $value]) {
                        if ($this->toString($key) === $node->property) {
                            $results[] = $value;
                        }
                    }
                }
            }
            return Value::array($results);
        }
        return Value::null();
    }

    private function evalIndexAccess(IndexAccess $node): Value
    {
        $obj = $this->eval($node->object);
        $index = $this->eval($node->index);

        if ($obj->type === ValueType::Array) {
            $i = (int) $this->toNumber($index);
            if ($i < 0) {
                $i = count($obj->data) + $i;
            }
            return $obj->data[$i] ?? Value::null();
        }
        if ($obj->type === ValueType::Object) {
            $key = $index->type === ValueType::String ? $index->data : $this->toString($index);
            return $obj->getKey($key) ?? Value::null();
        }
        if ($obj->type === ValueType::String) {
            $i = (int) $this->toNumber($index);
            if ($i < 0) {
                $i = strlen($obj->data) + $i;
            }
            return Value::string($obj->data[$i] ?? '');
        }
        return Value::null();
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

        return match ($typeName) {
            'string' => Value::string($this->toString($value)),
            'number' => Value::number($this->toNumber($value)),
            'boolean' => Value::boolean($value->isTruthy()),
            default => $value, // Pass through for unknown types
        };
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
            ValueType::String => is_numeric($value->data) ? (str_contains($value->data, '.') ? (float) $value->data : (int) $value->data) : 0,
            ValueType::Boolean => $value->data ? 1 : 0,
            ValueType::Null => 0,
            default => 0,
        };
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
