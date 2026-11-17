<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;

/**
 * application/json for read()/write(): STRICT JSON in (double-quoted keys and strings — DataWeave
 * literal syntax is application/dw), key order and `{}` vs `[]` preserved; out, pretty-printed with
 * DataWeave's 2-space indent unless the `indent` writer property is false.
 *
 * @internal used through {@see \Aaxis\Bundle\OntologyBundle\Dwl\Runtime\DataFormats}
 */
final class JsonCodec
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    public static function read(string $text): Value
    {
        try {
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(sprintf(
                'read: invalid application/json input (%s). JSON needs double-quoted keys and strings — '
                . 'to read a DataWeave literal such as {key: \'value\'} use "application/dw"',
                $e->getMessage(),
            ), 0, $e);
        }

        return self::toValue($data);
    }

    public static function write(Value $value, bool $pretty): string
    {
        try {
            $json = json_encode($value->toPhp(), $pretty ? self::FLAGS | JSON_PRETTY_PRINT : self::FLAGS);
        } catch (\JsonException $e) {
            throw new RuntimeException('write: cannot write application/json (' . $e->getMessage() . ')', 0, $e);
        }

        // json_encode indents by 4; DataWeave by 2. Pretty-printed JSON only has leading spaces as
        // indentation (raw newlines inside strings are always escaped), so halving them is safe.
        return $pretty ? (preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $match): string => substr($match[0], 0, intdiv(\strlen($match[0]), 2)),
            $json,
        ) ?? $json) : $json;
    }

    /** json_decode (objects as stdClass) → Value, so an empty object stays an object. */
    private static function toValue(mixed $data): Value
    {
        return match (true) {
            $data === null => Value::null(),
            \is_bool($data) => Value::boolean($data),
            \is_int($data), \is_float($data) => Value::number($data),
            \is_string($data) => Value::string($data),
            \is_array($data) => Value::array(array_map(self::toValue(...), $data)),
            default => self::objectToValue($data),
        };
    }

    private static function objectToValue(object $data): Value
    {
        $entries = [];
        foreach (get_object_vars($data) as $key => $value) {
            $entries[] = [Value::string((string) $key), self::toValue($value)];
        }

        return Value::object($entries);
    }
}
