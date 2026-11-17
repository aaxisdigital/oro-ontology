<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\DataFormats;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\ValueType;

/**
 * application/csv for read()/write(). Reader: an array of objects keyed by the header row (with
 * `header: false` they are keyed column_0, column_1, …), every cell a String, blank lines skipped,
 * quoted cells may span lines; a short row gets null for its missing columns. Writer: an array of
 * objects, the columns being the union of their keys in first-seen order, nested values JSON-encoded
 * in their cell. Properties: `separator` (","), `quote` ('"'), `escape` (none — RFC 4180 doubled
 * quotes) and `header` (true).
 *
 * @internal used through {@see DataFormats}
 */
final class CsvCodec
{
    public static function read(string $text, array $options): Value
    {
        [$separator, $quote, $escape] = self::dialect($options);
        $header = DataFormats::flag($options, 'header', true);

        $handle = self::stream($text);
        $columns = $header ? null : [];
        $rows = [];
        while (($fields = fgetcsv($handle, null, $separator, $quote, $escape)) !== false) {
            if ($fields === [null]) {
                continue; // a blank line
            }
            if ($columns === null) {
                $columns = array_map(static fn (?string $name): string => (string) $name, $fields);
                continue;
            }
            $rows[] = self::row($fields, $columns);
        }
        fclose($handle);

        return Value::array($rows);
    }

    public static function write(Value $value, array $options): string
    {
        if ($value->type !== ValueType::Array) {
            throw new RuntimeException('write: application/csv needs an array of objects, got ' . $value->type->value);
        }
        [$separator, $quote, $escape] = self::dialect($options);

        $rows = [];
        $columns = [];
        foreach ($value->data as $row) {
            if ($row->type !== ValueType::Object) {
                throw new RuntimeException(
                    'write: application/csv needs an array of objects, found a ' . $row->type->value . ' row'
                );
            }
            $cells = [];
            foreach ($row->data as [$key, $cell]) {
                $cells[(string) $key->data] = self::cell($cell);
                $columns[(string) $key->data] = true;
            }
            $rows[] = $cells;
        }
        $columns = array_keys($columns);

        $handle = self::stream('');
        if ($rows !== [] && DataFormats::flag($options, 'header', true)) {
            fputcsv($handle, $columns, $separator, $quote, $escape);
        }
        foreach ($rows as $cells) {
            $line = array_map(static fn (string|int $column): string => $cells[$column] ?? '', $columns);
            fputcsv($handle, $line, $separator, $quote, $escape);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @return array{string, string, string} separator, quote, escape */
    private static function dialect(array $options): array
    {
        $dialect = [];
        foreach (['separator' => ',', 'quote' => '"', 'escape' => ''] as $name => $default) {
            $character = (string) ($options[$name] ?? $default);
            if (\strlen($character) > 1 || ($character === '' && $name !== 'escape')) {
                throw new RuntimeException(
                    sprintf('CSV property "%s" must be a single character, got "%s"', $name, $character)
                );
            }
            $dialect[] = $character;
        }

        return $dialect;
    }

    /**
     * @param array<int, string|null> $fields
     * @param list<string>            $columns header names; positions beyond them are column_<n>
     */
    private static function row(array $fields, array $columns): Value
    {
        $entries = [];
        $width = max(\count($fields), \count($columns));
        for ($i = 0; $i < $width; ++$i) {
            $entries[] = [
                Value::string($columns[$i] ?? 'column_' . $i),
                isset($fields[$i]) ? Value::string($fields[$i]) : Value::null(),
            ];
        }

        return Value::object($entries);
    }

    private static function cell(Value $cell): string
    {
        return match ($cell->type) {
            ValueType::Array, ValueType::Object => JsonCodec::write($cell, false),
            default => DataFormats::scalarText($cell, 'application/csv'),
        };
    }

    /** @return resource */
    private static function stream(string $content)
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open a temporary stream');
        }
        fwrite($handle, $content);
        rewind($handle);

        return $handle;
    }
}
