<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;

/**
 * The INPUTS of a Data Playground run: several entities, each bound to the script under a
 * variable NAME of its own (`orders map …`, `customers filter …`). Shared by the run endpoint and
 * the saved scripts' defaults ({@see DwlScriptManager}), so both accept exactly the same lists.
 *
 * A name must be a DWL identifier the engine reads as a variable — letters, digits and `_`, not
 * starting with a digit, not one of the lexer's keywords (case-insensitive, see
 * {@see \Aaxis\Bundle\OntologyBundle\Dwl\Language\Lexer}) — and unique within the list.
 */
final class DwlInputs
{
    public const int MAX_INPUTS = 10;
    public const int NAME_MAX_LENGTH = 64;

    /** Mirrors the DWL lexer's KEYWORDS: a variable named like one would not parse as a variable. */
    public const array KEYWORDS = [
        'output', 'input', 'var', 'fun', 'type', 'ns', 'import', 'from', 'as', 'is', 'if', 'else', 'unless',
        'default', 'do', 'using', 'and', 'or', 'not', 'case', 'matches', 'match', 'true', 'false', 'null',
        'private', 'async', 'yield', 'for', 'throw', 'enum',
    ];

    public static function isName(string $name): bool
    {
        return preg_match('/^[A-Za-z_]\w*$/', $name) === 1
            && \strlen($name) <= self::NAME_MAX_LENGTH
            && !\in_array(strtolower($name), self::KEYWORDS, true);
    }

    /**
     * Reads `[{entityId, name}, …]` (null = no inputs). Returns the inputs with their entities, or
     * the reason the list is refused (a translation key suffix: `inputs_invalid`,
     * `input_name_invalid`, `input_name_duplicate`, `entity_missing`).
     *
     * @param callable(int): ?OntologyEntity $find
     *
     * @return list<array{name: string, entity: OntologyEntity}>|string
     */
    public static function parse(mixed $raw, callable $find): array|string
    {
        if ($raw === null) {
            return [];
        }
        $refusal = !\is_array($raw) || !array_is_list($raw) || \count($raw) > self::MAX_INPUTS ? 'inputs_invalid' : null;
        $inputs = [];
        foreach ($refusal === null ? $raw : [] as $input) {
            [$entry, $refusal] = self::entry($input, $inputs, $find);
            if ($entry === null) {
                break;
            }
            $inputs[] = $entry;
        }

        return $refusal ?? $inputs;
    }

    /**
     * Inputs as they are stored with a script.
     *
     * @param list<array{name: string, entity: OntologyEntity}> $inputs
     *
     * @return list<array{entityId: int, name: string}>
     */
    public static function toStored(array $inputs): array
    {
        return array_map(
            static fn (array $input): array => ['entityId' => (int) $input['entity']->getId(), 'name' => $input['name']],
            $inputs
        );
    }

    /**
     * @param list<array{name: string, entity: OntologyEntity}> $taken
     * @param callable(int): ?OntologyEntity                     $find
     *
     * @return array{0: array{name: string, entity: OntologyEntity}|null, 1: string|null}
     */
    private static function entry(mixed $input, array $taken, callable $find): array
    {
        $name = \is_array($input) && \is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $id = \is_array($input) ? ($input['entityId'] ?? null) : null;
        $entity = \is_int($id) ? $find($id) : null;
        $refusal = match (true) {
            !\is_int($id) => 'inputs_invalid',
            !self::isName($name) => 'input_name_invalid',
            \in_array($name, array_column($taken, 'name'), true) => 'input_name_duplicate',
            !$entity instanceof OntologyEntity => 'entity_missing',
            default => null,
        };

        return [$refusal === null ? ['name' => $name, 'entity' => $entity] : null, $refusal];
    }
}
