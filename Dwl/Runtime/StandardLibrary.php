<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime;

final class StandardLibrary
{
    public static function register(Environment $env): void
    {
        // Core functions
        $env->define('sizeOf', Value::func(fn(array $args) => self::sizeOf($args[0])));
        $env->define('sizeof', Value::func(fn(array $args) => self::sizeOf($args[0])));
        $env->define('typeOf', Value::func(fn(array $args) => self::typeOf($args[0])));
        $env->define('typeof', Value::func(fn(array $args) => self::typeOf($args[0])));
        $env->define('isEmpty', Value::func(fn(array $args) => self::isEmpty($args[0])));
        $env->define('trim', Value::func(fn(array $args) => Value::string(trim($args[0]->data ?? ''))));
        $env->define('upper', Value::func(fn(array $args) => Value::string(strtoupper($args[0]->data ?? ''))));
        $env->define('lower', Value::func(fn(array $args) => Value::string(strtolower($args[0]->data ?? ''))));
        $env->define('capitalize', Value::func(fn(array $args) => Value::string(ucwords(strtolower($args[0]->data ?? '')))));
        $env->define('abs', Value::func(fn(array $args) => Value::number(abs($args[0]->data ?? 0))));
        $env->define('ceil', Value::func(fn(array $args) => Value::number((int) ceil((float) ($args[0]->data ?? 0)))));
        $env->define('floor', Value::func(fn(array $args) => Value::number((int) floor((float) ($args[0]->data ?? 0)))));
        $env->define('round', Value::func(fn(array $args) => Value::number((int) round((float) ($args[0]->data ?? 0)))));
        $env->define('sqrt', Value::func(fn(array $args) => Value::number(sqrt((float) ($args[0]->data ?? 0)))));
        $env->define('pow', Value::func(fn(array $args) => Value::number(pow((float) ($args[0]->data ?? 0), (float) ($args[1]->data ?? 0)))));
        $env->define('mod', Value::func(fn(array $args) => Value::number((int) ($args[0]->data ?? 0) % (int) ($args[1]->data ?? 1))));
        // DataWeave semantics: min/max take an ARRAY of comparables and return the extreme
        // ELEMENT (strings compare lexically). The legacy two-number form is kept as a fallback.
        $env->define('min', Value::func(fn(array $args) => self::extremeOf($args, -1)));
        $env->define('max', Value::func(fn(array $args) => self::extremeOf($args, 1)));
        $env->define('sum', Value::func(fn(array $args) => self::sum($args[0])));
        $env->define('avg', Value::func(fn(array $args) => self::avg($args[0])));

        // String functions
        $env->define('length', Value::func(fn(array $args) => self::sizeOf($args[0])));
        $env->define('substring', Value::func(fn(array $args) => Value::string(
            substr($args[0]->data ?? '', (int) ($args[1]->data ?? 0), isset($args[2]) ? (int) $args[2]->data : null)
        )));
        $env->define('replace', Value::func(function (array $args) {
            $str = $args[0]->data ?? '';
            $search = $args[1]->data ?? '';
            $replace = $args[2]->data ?? '';
            if ($args[1]->type === ValueType::Regex) {
                return Value::string(preg_replace('/' . $search . '/', $replace, $str) ?? $str);
            }
            return Value::string(str_replace($search, $replace, $str));
        }));

        // Array functions
        $env->define('flatten', Value::func(fn(array $args) => self::flatten($args[0])));
        $env->define('reverse', Value::func(fn(array $args) => self::reverse($args[0])));
        $env->define('first', Value::func(fn(array $args) => self::first($args[0])));
        $env->define('last', Value::func(fn(array $args) => self::last($args[0])));
        $env->define('indexOf', Value::func(fn(array $args) => self::indexOf($args[0], $args[1])));
        $env->define('distinctBy', Value::func(fn(array $args) => self::distinctByFn($args[0], $args[1] ?? null)));
        $env->define('zip', Value::func(fn(array $args) => self::zip($args[0], $args[1])));
        $env->define('unzip', Value::func(fn(array $args) => self::unzip($args[0])));

        // Object functions
        $env->define('keys', Value::func(fn(array $args) => self::keys($args[0])));
        $env->define('namesOf', Value::func(fn(array $args) => self::keys($args[0])));
        $env->define('values', Value::func(fn(array $args) => self::values($args[0])));
        $env->define('entries', Value::func(fn(array $args) => self::entries($args[0])));

        // Type checking
        $env->define('isBlank', Value::func(fn(array $args) => Value::boolean(
            $args[0]->type === ValueType::Null || ($args[0]->type === ValueType::String && trim($args[0]->data) === '')
        )));
        $env->define('isInteger', Value::func(fn(array $args) => Value::boolean(
            $args[0]->type === ValueType::Number && $args[0]->data == (int) $args[0]->data
        )));
        $env->define('isDecimal', Value::func(fn(array $args) => Value::boolean(
            $args[0]->type === ValueType::Number && $args[0]->data != (int) $args[0]->data
        )));
        $env->define('isEven', Value::func(fn(array $args) => Value::boolean(
            $args[0]->type === ValueType::Number && (int) $args[0]->data % 2 === 0
        )));
        $env->define('isOdd', Value::func(fn(array $args) => Value::boolean(
            $args[0]->type === ValueType::Number && (int) $args[0]->data % 2 !== 0
        )));

        // Date/time
        $env->define('now', Value::func(fn(array $args) => Value::dateTime(new \DateTimeImmutable('now'), 'datetime')));
        // DataWeave's modulo operator (`a mod b`) — float-aware, integer results stay integers.
        $env->define('mod', Value::func(function (array $args) {
            $result = fmod((float) $args[0]->data, (float) $args[1]->data);
            return Value::number($result === floor($result) ? (int) $result : $result);
        }));

        // Utility
        $env->define('log', Value::func(function (array $args) {
            fwrite(STDERR, self::valueToString($args[0]) . "\n");
            return $args[0];
        }));
        $env->define('read', Value::func(fn(array $args) => $args[0])); // Simplified
        $env->define('write', Value::func(fn(array $args) => $args[0])); // Simplified
        $env->define('uuid', Value::func(fn(array $args) => Value::string(self::generateUuid())));
        $env->define('random', Value::func(fn(array $args) => Value::number(mt_rand() / mt_getrandmax())));
    }

    /** @return array<string, Value>|null */
    public static function getModule(string $modulePath): ?array
    {
        return match ($modulePath) {
            'dw::core::Arrays', 'dw::Core' => self::getCoreArraysModule(),
            'dw::core::Strings' => self::getCoreStringsModule(),
            'dw::core::Objects' => self::getCoreObjectsModule(),
            'dw::core::Numbers' => self::getCoreNumbersModule(),
            'dw::Runtime' => self::getRuntimeModule(),
            default => null,
        };
    }

    private static function getCoreArraysModule(): array
    {
        return [
            'countBy' => Value::func(function (array $args) {
                $arr = $args[0];
                $fn = $args[1];
                $count = 0;
                foreach ($arr->data as $item) {
                    if (($fn->data)([$item])->isTruthy()) {
                        $count++;
                    }
                }
                return Value::number($count);
            }),
            'sumBy' => Value::func(function (array $args) {
                $arr = $args[0];
                $fn = $args[1];
                $sum = 0;
                foreach ($arr->data as $item) {
                    $sum += ($fn->data)([$item])->data;
                }
                return Value::number($sum);
            }),
            'take' => Value::func(fn(array $args) => Value::array(array_slice($args[0]->data, 0, (int) $args[1]->data))),
            'drop' => Value::func(fn(array $args) => Value::array(array_slice($args[0]->data, (int) $args[1]->data))),
            'every' => Value::func(function (array $args) {
                foreach ($args[0]->data as $item) {
                    if (!($args[1]->data)([$item])->isTruthy()) {
                        return Value::boolean(false);
                    }
                }
                return Value::boolean(true);
            }),
            'some' => Value::func(function (array $args) {
                foreach ($args[0]->data as $item) {
                    if (($args[1]->data)([$item])->isTruthy()) {
                        return Value::boolean(true);
                    }
                }
                return Value::boolean(false);
            }),
            // slice(array, from, until) — from inclusive, until EXCLUSIVE (Mule semantics).
            'slice' => Value::func(function (array $args) {
                $items = $args[0]->data;
                $from = max(0, (int) $args[1]->data);
                $until = min(\count($items), (int) $args[2]->data);
                return Value::array($from < $until ? \array_slice($items, $from, $until - $from) : []);
            }),
            // divideBy(array, size) — consecutive chunks of at most `size` items.
            'divideBy' => Value::func(function (array $args) {
                $size = max(1, (int) $args[1]->data);
                return Value::array(array_map(
                    static fn(array $chunk) => Value::array($chunk),
                    array_chunk($args[0]->data, $size)
                ));
            }),
            'takeWhile' => Value::func(function (array $args) {
                $out = [];
                foreach ($args[0]->data as $item) {
                    if (!($args[1]->data)([$item])->isTruthy()) {
                        break;
                    }
                    $out[] = $item;
                }
                return Value::array($out);
            }),
            'dropWhile' => Value::func(function (array $args) {
                $items = $args[0]->data;
                $i = 0;
                while ($i < \count($items) && ($args[1]->data)([$items[$i]])->isTruthy()) {
                    $i++;
                }
                return Value::array(\array_slice($items, $i));
            }),
            // firstWith(array, (item, index) -> Boolean) — the first match, or null.
            'firstWith' => Value::func(function (array $args) {
                foreach ($args[0]->data as $i => $item) {
                    if (($args[1]->data)([$item, Value::number($i)])->isTruthy()) {
                        return $item;
                    }
                }
                return Value::null();
            }),
            'indexOf' => Value::func(fn(array $args) => self::indexOf($args[0], $args[1])),
            'lastIndexOf' => Value::func(function (array $args) {
                $found = -1;
                foreach ($args[0]->data as $i => $item) {
                    if ($item->type === $args[1]->type && $item->data === $args[1]->data) {
                        $found = $i;
                    }
                }
                return Value::number($found);
            }),
            'indexWhere' => Value::func(function (array $args) {
                foreach ($args[0]->data as $i => $item) {
                    if (($args[1]->data)([$item, Value::number($i)])->isTruthy()) {
                        return Value::number($i);
                    }
                }
                return Value::number(-1);
            }),
            // partition(array, fn) — {success: [matches], failure: [the rest]}.
            'partition' => Value::func(function (array $args) {
                $success = [];
                $failure = [];
                foreach ($args[0]->data as $item) {
                    if (($args[1]->data)([$item])->isTruthy()) {
                        $success[] = $item;
                    } else {
                        $failure[] = $item;
                    }
                }
                return Value::object([
                    [Value::string('success'), Value::array($success)],
                    [Value::string('failure'), Value::array($failure)],
                ]);
            }),
            // splitAt(array, n) — {l: first n items, r: the rest}.
            'splitAt' => Value::func(function (array $args) {
                $n = max(0, (int) $args[1]->data);
                return Value::object([
                    [Value::string('l'), Value::array(\array_slice($args[0]->data, 0, $n))],
                    [Value::string('r'), Value::array(\array_slice($args[0]->data, $n))],
                ]);
            }),
            // splitWhere(array, fn) — splits at the FIRST item matching the condition.
            'splitWhere' => Value::func(function (array $args) {
                $items = $args[0]->data;
                $i = 0;
                while ($i < \count($items) && !($args[1]->data)([$items[$i]])->isTruthy()) {
                    $i++;
                }
                return Value::object([
                    [Value::string('l'), Value::array(\array_slice($items, 0, $i))],
                    [Value::string('r'), Value::array(\array_slice($items, $i))],
                ]);
            }),
            'join' => Value::func(fn(array $args) => self::joinArrays($args[0], $args[1], $args[2], $args[3], 'inner')),
            'leftJoin' => Value::func(fn(array $args) => self::joinArrays($args[0], $args[1], $args[2], $args[3], 'left')),
            'outerJoin' => Value::func(fn(array $args) => self::joinArrays($args[0], $args[1], $args[2], $args[3], 'outer')),
        ];
    }

    private static function getCoreStringsModule(): array
    {
        return [
            'camelize' => Value::func(fn(array $args) => Value::string(lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $args[0]->data ?? '')))))),
            'dasherize' => Value::func(fn(array $args) => Value::string(strtolower(preg_replace('/[A-Z]/', '-$0', lcfirst($args[0]->data ?? '')) ?? ''))),
            'underscore' => Value::func(fn(array $args) => Value::string(strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($args[0]->data ?? '')) ?? ''))),
            'pluralize' => Value::func(fn(array $args) => Value::string(($args[0]->data ?? '') . 's')), // Simplified
            'singularize' => Value::func(fn(array $args) => Value::string(rtrim($args[0]->data ?? '', 's'))), // Simplified
            'repeat' => Value::func(fn(array $args) => Value::string(str_repeat($args[0]->data ?? '', (int) ($args[1]->data ?? 1)))),
            'leftPad' => Value::func(fn(array $args) => Value::string(str_pad($args[0]->data ?? '', (int) ($args[1]->data ?? 0), $args[2]->data ?? ' ', STR_PAD_LEFT))),
            'rightPad' => Value::func(fn(array $args) => Value::string(str_pad($args[0]->data ?? '', (int) ($args[1]->data ?? 0), $args[2]->data ?? ' '))),
        ];
    }

    private static function getCoreObjectsModule(): array
    {
        return [
            'mergeWith' => Value::func(function (array $args) {
                $a = $args[0];
                $b = $args[1];
                $entries = $a->data;
                foreach ($b->data as $pair) {
                    $entries[] = $pair;
                }
                return Value::object($entries);
            }),
        ];
    }

    private static function getCoreNumbersModule(): array
    {
        return [
            'isNaN' => Value::func(fn(array $args) => Value::boolean(is_nan((float) ($args[0]->data ?? 0)))),
            'isInfinite' => Value::func(fn(array $args) => Value::boolean(is_infinite((float) ($args[0]->data ?? 0)))),
        ];
    }

    private static function getRuntimeModule(): array
    {
        return [
            'props' => Value::func(function (array $args) {
                $entries = [];
                foreach (getenv() as $key => $value) {
                    $entries[] = [Value::string($key), Value::string($value)];
                }
                return Value::object($entries);
            }),
            'fail' => Value::func(function (array $args) {
                throw new \Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException($args[0]->data ?? 'Execution failed');
            }),
        ];
    }

    // === Helper implementations ===

    private static function sizeOf(Value $val): Value
    {
        return Value::number(match ($val->type) {
            ValueType::Array => count($val->data),
            ValueType::Object => count($val->data),
            ValueType::String => strlen($val->data),
            default => 0,
        });
    }

    private static function typeOf(Value $val): Value
    {
        return Value::string($val->type->value);
    }

    private static function isEmpty(Value $val): Value
    {
        return Value::boolean(match ($val->type) {
            ValueType::Array => empty($val->data),
            ValueType::Object => empty($val->data),
            ValueType::String => $val->data === '',
            ValueType::Null => true,
            default => false,
        });
    }

    /**
     * min/max: DataWeave form takes one ARRAY argument and returns its smallest/largest ELEMENT
     * by {@see Value::compare} (empty array -> null). Two scalar arguments keep the legacy
     * numeric behaviour.
     *
     * @param Value[] $args
     * @param int     $direction 1 = max, -1 = min
     */
    private static function extremeOf(array $args, int $direction): Value
    {
        $first = $args[0] ?? null;
        if ($first !== null && $first->type === ValueType::Array) {
            $best = null;
            foreach ($first->data as $item) {
                if ($best === null || (Value::compare($item, $best) <=> 0) === $direction) {
                    $best = $item;
                }
            }

            return $best ?? Value::null();
        }

        // Legacy scalar form: min(a, b) / max(a, b) over numbers.
        $a = (float) ($first?->data ?? 0);
        $b = (float) (($args[1] ?? null)?->data ?? 0);

        return Value::number($direction === 1 ? max($a, $b) : min($a, $b));
    }

    private static function sum(Value $val): Value
    {
        if ($val->type !== ValueType::Array) {
            return Value::number(0);
        }
        $sum = 0;
        foreach ($val->data as $item) {
            $sum += $item->data ?? 0;
        }
        return Value::number($sum);
    }

    private static function avg(Value $val): Value
    {
        if ($val->type !== ValueType::Array || empty($val->data)) {
            return Value::number(0);
        }
        $sum = 0;
        foreach ($val->data as $item) {
            $sum += $item->data ?? 0;
        }
        return Value::number($sum / count($val->data));
    }

    private static function flatten(Value $val): Value
    {
        if ($val->type !== ValueType::Array) {
            return $val;
        }
        $result = [];
        foreach ($val->data as $item) {
            if ($item->type === ValueType::Array) {
                foreach ($item->data as $inner) {
                    $result[] = $inner;
                }
            } else {
                $result[] = $item;
            }
        }
        return Value::array($result);
    }

    private static function reverse(Value $val): Value
    {
        if ($val->type === ValueType::Array) {
            return Value::array(array_reverse($val->data));
        }
        if ($val->type === ValueType::String) {
            return Value::string(strrev($val->data));
        }
        return $val;
    }

    private static function first(Value $val): Value
    {
        if ($val->type === ValueType::Array && !empty($val->data)) {
            return $val->data[0];
        }
        return Value::null();
    }

    private static function last(Value $val): Value
    {
        if ($val->type === ValueType::Array && !empty($val->data)) {
            return end($val->data);
        }
        return Value::null();
    }

    /**
     * dw::core::Arrays join/leftJoin/outerJoin: pairs items whose criteria values match, one
     * {l: leftItem, r: rightItem} row per pairing. leftJoin also emits unmatched left items as
     * {l: item}; outerJoin adds unmatched right items as {r: item}.
     */
    private static function joinArrays(Value $left, Value $right, Value $leftCriteria, Value $rightCriteria, string $mode): Value
    {
        $rightBuckets = [];
        foreach ($right->data as $item) {
            $rightBuckets[self::joinKey(($rightCriteria->data)([$item]))][] = $item;
        }

        $rows = [];
        $matched = [];
        foreach ($left->data as $item) {
            $key = self::joinKey(($leftCriteria->data)([$item]));
            if (isset($rightBuckets[$key])) {
                $matched[$key] = true;
                foreach ($rightBuckets[$key] as $rightItem) {
                    $rows[] = Value::object([[Value::string('l'), $item], [Value::string('r'), $rightItem]]);
                }
            } elseif ($mode !== 'inner') {
                $rows[] = Value::object([[Value::string('l'), $item]]);
            }
        }
        if ($mode === 'outer') {
            foreach ($rightBuckets as $key => $items) {
                if (!isset($matched[$key])) {
                    foreach ($items as $rightItem) {
                        $rows[] = Value::object([[Value::string('r'), $rightItem]]);
                    }
                }
            }
        }

        return Value::array($rows);
    }

    /** Matching key for joins: type-tagged so "1" (string) and 1 (number) stay distinct. */
    private static function joinKey(Value $value): string
    {
        return $value->type->value . ':' . json_encode($value->toPhp());
    }

    private static function indexOf(Value $arr, Value $item): Value
    {
        if ($arr->type === ValueType::Array) {
            foreach ($arr->data as $i => $el) {
                if ($el->type === $item->type && $el->data === $item->data) {
                    return Value::number($i);
                }
            }
        }
        if ($arr->type === ValueType::String && $item->type === ValueType::String) {
            $pos = strpos($arr->data, $item->data);
            return $pos !== false ? Value::number($pos) : Value::number(-1);
        }
        return Value::number(-1);
    }

    private static function distinctByFn(Value $arr, ?Value $fn): Value
    {
        if ($arr->type !== ValueType::Array) {
            return $arr;
        }
        $seen = [];
        $result = [];
        foreach ($arr->data as $item) {
            $key = $fn !== null && $fn->type === ValueType::Function
                ? self::valueToString(($fn->data)([$item]))
                : self::valueToString($item);
            if (!in_array($key, $seen, true)) {
                $seen[] = $key;
                $result[] = $item;
            }
        }
        return Value::array($result);
    }

    private static function zip(Value $a, Value $b): Value
    {
        $result = [];
        $len = min(count($a->data ?? []), count($b->data ?? []));
        for ($i = 0; $i < $len; $i++) {
            $result[] = Value::array([$a->data[$i], $b->data[$i]]);
        }
        return Value::array($result);
    }

    private static function unzip(Value $arr): Value
    {
        $a = [];
        $b = [];
        foreach ($arr->data ?? [] as $pair) {
            if ($pair->type === ValueType::Array && count($pair->data) >= 2) {
                $a[] = $pair->data[0];
                $b[] = $pair->data[1];
            }
        }
        return Value::array([Value::array($a), Value::array($b)]);
    }

    private static function keys(Value $obj): Value
    {
        if ($obj->type !== ValueType::Object) {
            return Value::array([]);
        }
        return Value::array(array_map(fn($pair) => $pair[0], $obj->data));
    }

    private static function values(Value $obj): Value
    {
        if ($obj->type !== ValueType::Object) {
            return Value::array([]);
        }
        return Value::array(array_map(fn($pair) => $pair[1], $obj->data));
    }

    private static function entries(Value $obj): Value
    {
        if ($obj->type !== ValueType::Object) {
            return Value::array([]);
        }
        return Value::array(array_map(fn($pair) => Value::object([
            [Value::string('key'), $pair[0]],
            [Value::string('value'), $pair[1]],
        ]), $obj->data));
    }

    private static function valueToString(Value $val): string
    {
        return match ($val->type) {
            ValueType::String => $val->data,
            ValueType::Number => is_int($val->data) ? (string) $val->data : rtrim(rtrim(sprintf('%.15g', $val->data), '0'), '.'),
            ValueType::Boolean => $val->data ? 'true' : 'false',
            ValueType::Null => 'null',
            default => (string) $val->data,
        };
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
