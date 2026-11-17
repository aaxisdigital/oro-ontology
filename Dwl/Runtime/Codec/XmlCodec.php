<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Codec;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\DataFormats;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\ValueType;

/**
 * application/xml for read()/write(), DataWeave-shaped: the root element becomes a one-key object,
 * repeated child elements become REPEATED KEYS (so `payload.root.*item` collects them), element text
 * stays a String (no number guessing). The `nullValueOn` reader property — blank (default) | empty |
 * none — decides when a childless element reads as null. Attributes are not read (the engine has no
 * `@` selectors). The writer needs an object with exactly one root key; an array under a key repeats
 * the element, the inverse of the reader.
 *
 * @internal used through {@see DataFormats}
 */
final class XmlCodec
{
    public static function read(string $text, array $options): Value
    {
        if (trim($text) === '') {
            throw new RuntimeException('read: invalid application/xml input (empty document)');
        }
        $nullValueOn = strtolower((string) ($options['nullValueOn'] ?? 'blank'));

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT/DTDLOAD: entities are never substituted from external sources, and
            // LIBXML_NONET forbids any network access while parsing untrusted input.
            $loaded = $document->loadXML($text, LIBXML_NONET);
            $error = libxml_get_last_error();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$loaded || $root === null) {
            $reason = $error !== false ? trim($error->message) : 'not well-formed';
            throw new RuntimeException('read: invalid application/xml input (' . $reason . ')');
        }

        return Value::object([[Value::string($root->localName), self::elementToValue($root, $nullValueOn)]]);
    }

    public static function write(Value $value): string
    {
        $entries = $value->type === ValueType::Object ? array_values($value->data) : [];
        [$rootKey, $rootValue] = $entries[0] ?? [null, null];
        if (\count($entries) !== 1 || $rootValue->type === ValueType::Array) {
            throw new RuntimeException(
                'write: application/xml needs an object with exactly one root key holding a single element'
            );
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        self::append($document, $document, (string) $rootKey->data, $rootValue);

        return rtrim((string) $document->saveXML());
    }

    private static function elementToValue(\DOMElement $element, string $nullValueOn): Value
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = [Value::string($child->localName), self::elementToValue($child, $nullValueOn)];
            }
        }
        if ($children !== []) {
            return Value::object($children);
        }

        $text = $element->textContent;
        $isNull = match ($nullValueOn) {
            'none' => false,
            'empty' => $text === '',
            default => trim($text) === '',
        };

        return $isNull ? Value::null() : Value::string($text);
    }

    private static function append(\DOMDocument $document, \DOMNode $parent, string $name, Value $value): void
    {
        if ($value->type === ValueType::Array) {
            foreach ($value->data as $item) {
                self::append($document, $parent, $name, $item);
            }

            return;
        }

        try {
            $element = $document->createElement($name);
        } catch (\DOMException $e) {
            throw new RuntimeException(sprintf('write: "%s" is not a valid XML element name', $name), 0, $e);
        }
        $parent->appendChild($element);

        if ($value->type === ValueType::Object) {
            foreach ($value->data as [$key, $child]) {
                self::append($document, $element, (string) $key->data, $child);
            }
        } elseif ($value->type !== ValueType::Null) {
            $element->appendChild($document->createTextNode(DataFormats::scalarText($value, 'application/xml')));
        }
    }
}
