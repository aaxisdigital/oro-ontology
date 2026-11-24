<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * What the flow debugger's Variables pane receives: the run context with every object/array
 * holding more than {@see LIMIT} entries cut to its first LIMIT — at any depth — plus where that
 * happened and how big it really was. A reader loading thousands of records would otherwise ship
 * (and render) all of them on every debug tick; the FULL context stays in the server-side session,
 * so the pane's DWL evaluator still sees everything.
 *
 * `truncated` maps a JSON Pointer (RFC 6901: "" = the whole context, "/payload/items", "~0" / "~1"
 * escaping "~" and "/") to the entry count the value had before the cut.
 */
final class DebugContextPreview
{
    public const int LIMIT = 100;

    /**
     * @return array{context: mixed, truncated: array<string, int>}
     */
    public static function of(mixed $context, int $limit = self::LIMIT): array
    {
        $truncated = [];

        return ['context' => self::cut($context, '', $limit, $truncated), 'truncated' => $truncated];
    }

    /**
     * @param array<string, int> $truncated
     */
    private static function cut(mixed $value, string $pointer, int $limit, array &$truncated): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $count = \count($value);
        if ($count > $limit) {
            $truncated[$pointer] = $count;
            // A list stays a list (reindexed); an object keeps its first keys.
            $value = \array_slice($value, 0, $limit, !array_is_list($value));
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::cut($child, $pointer . '/' . strtr((string) $key, ['~' => '~0', '/' => '~1']), $limit, $truncated);
        }

        return $value;
    }
}
