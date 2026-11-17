<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ArrayLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BooleanLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DateTimeLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Identifier;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Node;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NullLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NumberLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectEntry;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\StringLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\UnaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Parser;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Environment;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Evaluator;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\ValueType;

/**
 * application/dw — DataWeave's default content type for read()/write() — limited to LITERALS:
 * objects, arrays, strings, numbers (negative included), booleans, null and |…| dates.
 *
 * The reader NEVER evaluates expressions: the input is data (a file, a request body), and evaluating
 * it would run code that no screening of the script itself has seen — e.g. a `dw::Runtime::props()`
 * call smuggled past the playground's DwlScriptGuard inside a string. So the text is parsed, every
 * node must be a literal (no directives, dynamic/conditional keys, selectors, calls or operators
 * beyond a numeric minus), and only then is it turned into a value.
 *
 * @internal used through {@see \Aaxis\Bundle\OntologyBundle\Dwl\Runtime\DataFormats}
 */
final class DwCodec
{
    public static function read(string $text): Value
    {
        try {
            $script = Parser::parse($text);
        } catch (\Throwable $e) {
            throw new RuntimeException('read: invalid application/dw input (' . $e->getMessage() . ')', 0, $e);
        }
        if ($script->directives !== [] || !self::isLiteral($script->body)) {
            throw new RuntimeException(
                'read: application/dw input must be a literal value (object, array, string, number, boolean, '
                . 'null or date) — expressions are not evaluated'
            );
        }

        return (new Evaluator(new Environment()))->evaluate($script);
    }

    public static function write(Value $value, int $depth = 0): string
    {
        return match ($value->type) {
            ValueType::Null => 'null',
            ValueType::Boolean => $value->data ? 'true' : 'false',
            ValueType::Number => self::number($value->data),
            ValueType::String, ValueType::Binary => self::string((string) $value->data),
            ValueType::Date, ValueType::DateTime, ValueType::Period => '|' . $value->toPhp() . '|',
            ValueType::Regex => '/' . $value->data . '/',
            ValueType::Array => self::block('[', ']', array_map(
                static fn (Value $item): string => self::write($item, $depth + 1),
                $value->data,
            ), $depth),
            ValueType::Object => self::block('{', '}', array_map(
                static fn (array $entry): string => self::key((string) $entry[0]->data)
                    . ': ' . self::write($entry[1], $depth + 1),
                $value->data,
            ), $depth),
            ValueType::Function => throw new RuntimeException('write: cannot write a Function'),
        };
    }

    /** Whether the node is pure data: literals all the way down, nothing to evaluate. */
    private static function isLiteral(Node $node): bool
    {
        return match (true) {
            $node instanceof StringLiteral, $node instanceof NumberLiteral, $node instanceof BooleanLiteral,
            $node instanceof NullLiteral, $node instanceof DateTimeLiteral => true,
            $node instanceof UnaryOp => $node->operator === '-' && $node->operand instanceof NumberLiteral,
            $node instanceof ArrayLiteral => self::allLiteral($node->elements),
            $node instanceof ObjectLiteral => self::allLiteralEntries($node->entries),
            default => false,
        };
    }

    /** @param Node[] $nodes */
    private static function allLiteral(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!self::isLiteral($node)) {
                return false;
            }
        }

        return true;
    }

    /** @param ObjectEntry[] $entries */
    private static function allLiteralEntries(array $entries): bool
    {
        foreach ($entries as $entry) {
            $plainKey = $entry->key instanceof Identifier || $entry->key instanceof StringLiteral;
            $plainEntry = !$entry->conditional && !$entry->dynamicKey && $entry->attributes === null;
            if (!$plainKey || !$plainEntry || !self::isLiteral($entry->value)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $items */
    private static function block(string $open, string $close, array $items, int $depth): string
    {
        if ($items === []) {
            return $open . $close;
        }
        $indent = str_repeat('  ', $depth + 1);

        return $open . "\n" . $indent . implode(",\n" . $indent, $items) . "\n" . str_repeat('  ', $depth) . $close;
    }

    private static function number(int|float $number): string
    {
        return \is_int($number) || !is_finite($number)
            ? (string) $number
            : (string) json_encode($number, JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function string(string $text): string
    {
        $escapes = ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', '$' => '\\$'];

        return '"' . strtr($text, $escapes) . '"';
    }

    private static function key(string $key): string
    {
        $bare = preg_match('/^[A-Za-z_]\w*$/', $key) === 1 && !\in_array($key, ['true', 'false', 'null'], true);

        return $bare ? $key : self::string($key);
    }
}
