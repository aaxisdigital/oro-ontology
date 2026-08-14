<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime;

enum ValueType: string
{
    case String = 'String';
    case Number = 'Number';
    case Boolean = 'Boolean';
    case Null = 'Null';
    case Array = 'Array';
    case Object = 'Object';
    case Date = 'Date';
    case DateTime = 'DateTime';
    case Regex = 'Regex';
    case Binary = 'Binary';
    case Function = 'Function';
}

class Value
{
    private static ?Value $nullSingleton = null;
    private static ?Value $trueSingleton = null;
    private static ?Value $falseSingleton = null;

    /** @var array<string, int>|null Hash index for O(1) object key lookups */
    private ?array $keyIndex = null;

    public function __construct(
        public readonly ValueType $type,
        public readonly mixed $data,
        public readonly array $attributes = [],
    ) {}

    /**
     * Get value by key from an Object value in O(1) amortized time.
     */
    public function getKey(string $key): ?Value
    {
        if ($this->type !== ValueType::Object) {
            return null;
        }
        if ($this->keyIndex === null) {
            $this->keyIndex = [];
            foreach ($this->data as $i => [$k, $v]) {
                $ks = $k->type === ValueType::String ? $k->data : (string)$k->data;
                // Last-write-wins for duplicate keys, but store first occurrence
                if (!isset($this->keyIndex[$ks])) {
                    $this->keyIndex[$ks] = $i;
                }
            }
        }
        if (isset($this->keyIndex[$key])) {
            return $this->data[$this->keyIndex[$key]][1];
        }
        return null;
    }

    public static function string(string $val): self
    {
        return new self(ValueType::String, $val);
    }

    public static function number(float|int $val): self
    {
        return new self(ValueType::Number, $val);
    }

    public static function boolean(bool $val): self
    {
        return $val ? (self::$trueSingleton ??= new self(ValueType::Boolean, true))
                     : (self::$falseSingleton ??= new self(ValueType::Boolean, false));
    }

    public static function null(): self
    {
        return self::$nullSingleton ??= new self(ValueType::Null, null);
    }

    /**
     * Type-aware ordering used by the comparison operators and by min/max: numbers compare
     * numerically, strings lexically (byte order, like DataWeave), booleans false < true,
     * dates by their underlying value; mixed/unknown pairs fall back to numeric coercion.
     */
    public static function compare(Value $a, Value $b): int
    {
        if ($a->type === ValueType::String && $b->type === ValueType::String) {
            return strcmp((string) $a->data, (string) $b->data);
        }
        if ($a->type === ValueType::Boolean && $b->type === ValueType::Boolean) {
            return ((bool) $a->data) <=> ((bool) $b->data);
        }
        if (($a->type === ValueType::Date || $a->type === ValueType::DateTime)
            && ($b->type === ValueType::Date || $b->type === ValueType::DateTime)
        ) {
            return $a->data <=> $b->data;
        }

        return (float) ($a->data ?? 0) <=> (float) ($b->data ?? 0);
    }

    public static function array(array $elements): self
    {
        return new self(ValueType::Array, $elements);
    }

    public static function object(array $entries): self
    {
        return new self(ValueType::Object, $entries);
    }

    public static function regex(string $pattern): self
    {
        return new self(ValueType::Regex, $pattern);
    }

    public static function func(callable $fn): self
    {
        return new self(ValueType::Function, $fn);
    }

    public function isTruthy(): bool
    {
        return match ($this->type) {
            ValueType::Boolean => $this->data === true,
            ValueType::Null => false,
            ValueType::String => $this->data !== '',
            ValueType::Number => $this->data !== 0 && $this->data !== 0.0,
            ValueType::Array => !empty($this->data),
            ValueType::Object => !empty($this->data),
            default => true,
        };
    }

    public function toPhp(): mixed
    {
        return match ($this->type) {
            ValueType::String => $this->data,
            ValueType::Number => $this->data,
            ValueType::Boolean => $this->data,
            ValueType::Null => null,
            ValueType::Array => array_map(fn(Value $v) => $v->toPhp(), $this->data),
            ValueType::Object => $this->objectToPhp(),
            default => $this->data,
        };
    }

    private function objectToPhp(): array|\stdClass
    {
        $result = new \stdClass();
        foreach ($this->data as [$key, $value]) {
            $k = $key instanceof Value ? $key->data : $key;
            $result->$k = $value instanceof Value ? $value->toPhp() : $value;
        }
        return $result;
    }
}
