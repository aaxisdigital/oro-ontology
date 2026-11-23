<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec\CsvCodec;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec\DwCodec;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec\JsonCodec;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec\XmlCodec;

/**
 * The data formats behind the core `read(text, contentType, readerProperties)` and
 * `write(value, contentType, writerProperties)` functions. Both default to application/dw, as in
 * DataWeave.
 *
 * Supported content types (parameters such as `;charset=` are ignored, `+json`/`+xml` suffixes map
 * to their base format) — the per-format details live on each codec:
 * - application/json, text/json ({@see JsonCodec})
 * - application/xml, text/xml ({@see XmlCodec})
 * - application/csv, text/csv ({@see CsvCodec})
 * - text/plain — the text as-is; the writer accepts simple values only
 * - application/dw — DataWeave LITERALS only, never evaluated ({@see DwCodec})
 *
 * Unknown content types and malformed input fail with a RuntimeException naming the problem.
 */
final class DataFormats
{
    public const DEFAULT_CONTENT_TYPE = 'application/dw';

    private const JSON = 'json';
    private const XML = 'xml';
    private const CSV = 'csv';
    private const TEXT = 'text';
    private const DW = 'dw';

    /** @var array<string, string> content type => format */
    private const CONTENT_TYPES = [
        'application/json' => self::JSON,
        'text/json' => self::JSON,
        'application/xml' => self::XML,
        'text/xml' => self::XML,
        'application/csv' => self::CSV,
        'text/csv' => self::CSV,
        'text/plain' => self::TEXT,
        self::DEFAULT_CONTENT_TYPE => self::DW,
    ];

    private const BOM = "\xEF\xBB\xBF";

    public static function read(Value $input, ?Value $contentType = null, ?Value $properties = null): Value
    {
        if ($input->type !== ValueType::String && $input->type !== ValueType::Binary) {
            throw new RuntimeException(sprintf(
                'read: expected a String to parse, got %s — the value is already structured data, use it directly',
                $input->type->value,
            ));
        }
        $text = (string) $input->data;
        $options = self::options($properties);

        return match (self::format($contentType, 'read')) {
            self::JSON => JsonCodec::read(self::withoutBom($text)),
            self::XML => XmlCodec::read(self::withoutBom($text), $options),
            self::CSV => CsvCodec::read(self::withoutBom($text), $options),
            self::TEXT => Value::string($text),
            default => DwCodec::read($text),
        };
    }

    public static function write(Value $value, ?Value $contentType = null, ?Value $properties = null): string
    {
        $options = self::options($properties);

        return match (self::format($contentType, 'write')) {
            self::JSON => JsonCodec::write($value, self::flag($options, 'indent', true)),
            self::XML => XmlCodec::write($value),
            self::CSV => CsvCodec::write($value, $options),
            self::TEXT => self::writeText($value),
            default => DwCodec::write($value),
        };
    }

    /** A reader/writer boolean property; the strings "true"/"false" are accepted too. */
    public static function flag(array $options, string $name, bool $default): bool
    {
        $value = $options[$name] ?? $default;

        return \is_string($value) ? strtolower($value) !== 'false' : (bool) $value;
    }

    /** Text for a simple value, as the text-based writers render it. */
    public static function scalarText(Value $value, string $contentType): string
    {
        return match ($value->type) {
            ValueType::Null => '',
            ValueType::String, ValueType::Binary, ValueType::Regex => (string) $value->data,
            ValueType::Boolean => $value->data ? 'true' : 'false',
            ValueType::Number, ValueType::Date, ValueType::DateTime, ValueType::Period => (string) $value->toPhp(),
            default => throw new RuntimeException(
                sprintf('write: cannot write a %s as %s', $value->type->value, $contentType)
            ),
        };
    }

    private static function format(?Value $contentType, string $function): string
    {
        if ($contentType === null || $contentType->type === ValueType::Null) {
            return self::DW;
        }
        if ($contentType->type !== ValueType::String) {
            throw new RuntimeException(sprintf(
                '%s: the content type must be a String, got %s',
                $function,
                $contentType->type->value,
            ));
        }
        $format = self::formatOf($contentType->data);
        if ($format === null) {
            throw new RuntimeException(sprintf(
                '%s: unsupported content type "%s" (supported: %s)',
                $function,
                $contentType->data,
                implode(', ', array_keys(self::CONTENT_TYPES)),
            ));
        }

        return $format;
    }

    /** Whether read()/write() know the content type (parameters and case ignored, `+json`/`+xml` suffixes accepted). */
    public static function supports(string $contentType): bool
    {
        return self::formatOf($contentType) !== null;
    }

    private static function formatOf(string $contentType): ?string
    {
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        return self::CONTENT_TYPES[$mime] ?? match (true) {
            str_ends_with($mime, '+json') => self::JSON,
            str_ends_with($mime, '+xml') => self::XML,
            default => null,
        };
    }

    /** @return array<string, mixed> the reader/writer properties object as plain PHP */
    private static function options(?Value $properties): array
    {
        if ($properties === null || $properties->type !== ValueType::Object) {
            return [];
        }
        $options = [];
        foreach ($properties->data as [$key, $value]) {
            $options[(string) $key->data] = $value->toPhp();
        }

        return $options;
    }

    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, \strlen(self::BOM)) : $text;
    }

    private static function writeText(Value $value): string
    {
        if ($value->type === ValueType::Array || $value->type === ValueType::Object) {
            throw new RuntimeException(
                'write: text/plain can only write simple values, got ' . $value->type->value . ' — use application/json'
            );
        }

        return self::scalarText($value, 'text/plain');
    }
}
