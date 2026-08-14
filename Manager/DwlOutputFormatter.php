<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * Renders a DWL transform result according to the `output <mime>` directive of the script that
 * produced it — used by the DWL playground so both the on-screen Result and the exported file
 * follow the format the script declares.
 *
 * The engine ({@see \Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer}) parses the header but returns
 * plain PHP regardless, so the directive is read from the source here. Unknown/absent MIME types
 * fall back to JSON, matching the engine's tolerance for header-less scripts.
 */
class DwlOutputFormatter
{
    public const string FORMAT_JSON = 'json';
    public const string FORMAT_XML = 'xml';
    public const string FORMAT_CSV = 'csv';
    public const string FORMAT_TEXT = 'text';

    /** @var array<string, string> MIME subtype (or full type) => format key */
    private const array MIME_FORMATS = [
        'json' => self::FORMAT_JSON,
        'xml' => self::FORMAT_XML,
        'csv' => self::FORMAT_CSV,
        'plain' => self::FORMAT_TEXT,
        'text' => self::FORMAT_TEXT,
    ];

    private const array EXTENSIONS = [
        self::FORMAT_JSON => 'json',
        self::FORMAT_XML => 'xml',
        self::FORMAT_CSV => 'csv',
        self::FORMAT_TEXT => 'txt',
    ];

    /**
     * Reads the `output <mime>` directive from a script's header (before the `---` separator).
     *
     * @return array{format: string, mime: string, extension: string}
     */
    public function detect(string $script): array
    {
        $header = $script;
        $separator = strpos($script, "\n---");
        if ($separator !== false) {
            $header = substr($script, 0, $separator);
        }

        $mime = '';
        if (preg_match('~^\s*output\s+([a-z0-9]+/[a-z0-9.+-]+)~mi', $header, $matches) === 1) {
            $mime = strtolower($matches[1]);
        }

        $format = self::FORMAT_JSON;
        if ($mime !== '') {
            // Prefer the subtype ("application/csv" → csv), then the whole type ("text/..." → text).
            [$type, $subtype] = array_pad(explode('/', $mime, 2), 2, '');
            $subtype = preg_replace('~^x-~', '', $subtype) ?? $subtype;
            $format = self::MIME_FORMATS[$subtype] ?? self::MIME_FORMATS[$type] ?? self::FORMAT_JSON;
        }

        return [
            'format' => $format,
            'mime' => $mime !== '' ? $mime : 'application/json',
            'extension' => self::EXTENSIONS[$format],
        ];
    }

    /** Serializes a transform result to text in the given format (see {@see detect()}). */
    public function serialize(mixed $data, string $format): string
    {
        return match ($format) {
            self::FORMAT_XML => $this->toXml($data),
            self::FORMAT_CSV => $this->toCsv($data),
            self::FORMAT_TEXT => $this->toText($data),
            default => $this->toJson($data),
        };
    }

    private function toJson(mixed $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    private function toText(mixed $data): string
    {
        if (\is_string($data)) {
            return $data;
        }
        if ($data === null) {
            return '';
        }
        if (\is_bool($data)) {
            return $data ? 'true' : 'false';
        }
        if (is_scalar($data)) {
            return (string) $data;
        }
        // A list of scalars reads best as one value per line; anything richer falls back to JSON.
        if (\is_array($data) && array_is_list($data) && $data !== []) {
            $scalars = array_filter($data, static fn (mixed $item): bool => is_scalar($item) || $item === null);
            if (\count($scalars) === \count($data)) {
                return implode("\n", array_map(fn (mixed $item): string => $this->toText($item), $data));
            }
        }

        return $this->toJson($data);
    }

    /**
     * CSV of a list of rows (the common `payload map {...}` shape). Columns are the union of the
     * rows' keys, in first-seen order; nested values are JSON-encoded inside their cell. A single
     * object becomes a one-row CSV; a list of scalars a one-column CSV.
     */
    private function toCsv(mixed $data): string
    {
        $rows = $this->toRowList($data);
        if ($rows === []) {
            return '';
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }
        $columns = array_keys($columns);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        // $escape is passed explicitly ('' = none, plain RFC 4180 quoting): PHP 8.4+ deprecates
        // relying on the default, and the historical "\" escape is not valid CSV anyway.
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->toCell($row[$column] ?? null);
            }
            fputcsv($handle, $line, ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toRowList(mixed $data): array
    {
        if (!\is_array($data)) {
            return $data === null ? [] : [['value' => $data]];
        }
        if (!array_is_list($data)) {
            return [$data];
        }

        $rows = [];
        foreach ($data as $item) {
            if (\is_array($item) && !array_is_list($item)) {
                $rows[] = $item;
            } else {
                $rows[] = ['value' => $item];
            }
        }

        return $rows;
    }

    private function toCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** XML with a `<result>` root; list items become `<item>`, non-identifier keys are sanitized. */
    private function toXml(mixed $data): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElement('result');
        $document->appendChild($root);
        $this->appendXml($document, $root, $data);

        return (string) $document->saveXML();
    }

    private function appendXml(\DOMDocument $document, \DOMElement $parent, mixed $data): void
    {
        if ($data === null) {
            return;
        }
        if (!\is_array($data)) {
            $parent->appendChild($document->createTextNode($this->toCell($data)));

            return;
        }
        foreach ($data as $key => $value) {
            $child = $document->createElement($this->xmlName($key));
            $parent->appendChild($child);
            $this->appendXml($document, $child, $value);
        }
    }

    /** XML element names cannot be numeric or hold arbitrary characters. */
    private function xmlName(string|int $key): string
    {
        if (\is_int($key)) {
            return 'item';
        }
        $name = preg_replace('~[^a-zA-Z0-9_.-]~', '_', $key) ?? 'item';
        if ($name === '' || preg_match('~^[a-zA-Z_]~', $name) !== 1) {
            $name = 'item' . ($name === '' ? '' : '_' . $name);
        }

        return $name;
    }
}
