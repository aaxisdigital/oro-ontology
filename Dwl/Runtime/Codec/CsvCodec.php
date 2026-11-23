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
 * quoted cells may span lines; a short row gets null for its missing columns. Lines may end in LF,
 * CRLF or a lone CR (a lone CR — inside a quoted cell too — reads as LF). Writer: an array of
 * objects, the columns being the union of their keys in first-seen order, nested values JSON-encoded
 * in their cell. Properties: `separator`, `quote` ('"'), `escape` (none — RFC 4180 doubled
 * quotes) and `header` (true). The writer's separator defaults to ","; the reader SNIFFS it from
 * the header line when the script gives none ({@see sniffSeparator()} — `,` `;` TAB or `|`), a
 * deliberate departure from DataWeave's fixed comma.
 *
 * @internal used through {@see DataFormats}
 */
final class CsvCodec
{
    /** The separators the reader sniffs for, in tie-break order. */
    private const array SNIFFED_SEPARATORS = [',', ';', "\t", '|'];

    /** How far into the text the header line is looked for. */
    private const int SNIFF_BYTES = 65536;

    public static function read(string $text, array $options): Value
    {
        [$separator, $quote, $escape] = self::dialect($options);
        if (($options['separator'] ?? null) === null) {
            $separator = self::sniffSeparator($text, $quote);
        }
        $header = DataFormats::flag($options, 'header', true);

        // fgetcsv only breaks lines on LF (PHP 8.1 dropped auto_detect_line_endings), so a file
        // whose lines end in a lone CR — Excel's "CSV (Macintosh)", classic Mac exports — would
        // read as ONE header row and come back as []. Lone CRs become LF; CRLF stays as it is.
        $handle = self::stream(preg_replace('/\r(?!\n)/', "\n", $text) ?? $text);
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

    /**
     * The reader's separator when the script names none: the candidate found most often OUTSIDE
     * quotes on the first non-blank line, a comma on a tie or when none appears. Excel saves
     * "CSV" with the locale's list separator (`;` wherever the decimal mark is a comma — pt-BR
     * among them) and SAP exports often do the same. With a fixed comma such a file read as ONE
     * column per row, keyed by the whole header line, so every `row.field` came back null.
     */
    private static function sniffSeparator(string $text, string $quote): string
    {
        $counts = array_fill_keys(self::SNIFFED_SEPARATORS, 0);
        $quoted = false;
        $seen = false;
        $length = min(\strlen($text), self::SNIFF_BYTES);
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if (!$quoted && ($char === "\n" || $char === "\r")) {
                if ($seen) {
                    break; // the end of the header line (blank lines before it are skipped)
                }
                continue;
            }
            $seen = true;
            if ($char === $quote) {
                $quoted = !$quoted; // a doubled quote toggles twice — still quoted
            } elseif (!$quoted && isset($counts[$char])) {
                ++$counts[$char];
            }
        }

        $best = self::SNIFFED_SEPARATORS[0];
        foreach ($counts as $candidate => $count) {
            if ($count > $counts[$best]) {
                $best = (string) $candidate;
            }
        }

        return $best;
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
