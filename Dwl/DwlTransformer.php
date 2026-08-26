<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Identifier;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Node;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Parser;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Environment;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Evaluator;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\StandardLibrary;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;

/**
 * Thin execution facade over the DataWeave engine imported under Dwl/ (see Dwl/LICENSE — a PHP
 * port of the MuleSoft DataWeave language, BSD-3-Clause): parses/validates a DWL script and
 * evaluates it against a set of PHP bindings (the flow debug context — payload etc.), returning
 * the result as plain PHP.
 *
 * The `%dw`/`output` header and the `---` separator are optional — bare expressions work.
 */
class DwlTransformer
{
    /**
     * Parsed scripts keyed by hash: {ast, names (every Identifier in the AST)}. AST nodes are all
     * public readonly — immutable — so reusing one across evaluations is safe. Flows re-run the
     * SAME scripts constantly (every foreach iteration, every choice evaluation, every debug
     * tick), and under php-fpm the cache even survives across requests. Bounded: see MAX_CACHE.
     *
     * @var array<string, array{ast: object, names: array<string, true>}>
     */
    private static array $scriptCache = [];
    private const MAX_CACHE = 64;

    /** @return string|null a parse error message, or null when the script is valid */
    public function validate(string $script): ?string
    {
        self::loadAst();
        try {
            self::parseCached($script);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $bindings every key becomes a variable visible to the script
     *
     * @throws \Throwable lexer/parser/runtime errors from the engine, message is user-readable
     */
    public function transform(string $script, array $bindings): mixed
    {
        self::loadAst();
        ['ast' => $ast, 'names' => $referenced] = self::parseCached($script);

        $env = new Environment();
        StandardLibrary::register($env);

        // Convert ONLY the bindings the script actually references. A flow's context accumulates
        // every destination produced so far — converting megabytes of unreferenced records into
        // engine Values (and, before this, converting the WHOLE set TWICE: once for the `context`
        // object, once per key) dominated every DWL call. `names` is a superset of what the
        // evaluator can ever look up (every Identifier in the AST), so nothing resolvable is lost.
        $values = [];
        foreach ($bindings as $name => $value) {
            $key = (string) $name;
            if ($key !== '' && isset($referenced[$key])) {
                $values[$key] = $this->toValue($value);
            }
        }

        // The whole binding set is also reachable as ONE object — the only way to scripts for
        // keys that are not valid DWL identifiers, e.g. hyphenated destinations via
        // context["some-key"] — built only when the script mentions `context`, reusing the Values
        // already converted above. Defined first so an actual "context" binding (a step
        // destination named so) still wins.
        if (isset($referenced['context'])) {
            $entries = [];
            foreach ($bindings as $name => $value) {
                $key = (string) $name;
                $entries[] = [Value::string($key), $values[$key] ?? $this->toValue($value)];
            }
            $env->define('context', Value::object($entries));
        }
        foreach ($values as $name => $value) {
            $env->define($name, $value);
        }

        $evaluator = new Evaluator($env);

        return $this->toPlainPhp($evaluator->evaluate($ast)->toPhp());
    }

    /**
     * Parses via the bounded per-process cache and collects the script's Identifier names.
     *
     * @return array{ast: object, names: array<string, true>}
     */
    private static function parseCached(string $script): array
    {
        $key = hash('sha256', $script);
        if (!isset(self::$scriptCache[$key])) {
            if (\count(self::$scriptCache) >= self::MAX_CACHE) {
                self::$scriptCache = [];
            }
            $ast = Parser::parse($script);
            $names = [];
            self::collectIdentifiers($ast, $names);
            self::$scriptCache[$key] = ['ast' => $ast, 'names' => $names];
        }

        return self::$scriptCache[$key];
    }

    /**
     * Every Identifier name anywhere in the AST — a deliberate SUPERSET of the variables the
     * evaluator may resolve from the environment (lambda parameters, do-block vars and object
     * keys parsed as identifiers are included too; an extra name only means one binding is
     * converted unnecessarily, which was the old behavior for ALL of them).
     *
     * @param array<string, true> $names
     */
    private static function collectIdentifiers(mixed $node, array &$names): void
    {
        if ($node instanceof Identifier) {
            $names[$node->name] = true;
        }
        // Descend into EVERY object, not just Node: the AST file also holds plain helper classes
        // (ObjectEntry, …) that do not extend Node but carry Node children.
        if (\is_object($node)) {
            foreach (get_object_vars($node) as $property) {
                self::collectIdentifiers($property, $names);
            }

            return;
        }
        if (\is_array($node)) {
            foreach ($node as $item) {
                self::collectIdentifiers($item, $names);
            }
        }
    }

    /**
     * Value::toPhp() renders DWL objects as stdClass (upstream keeps {} vs [] apart when
     * re-encoding to JSON). Downstream flow steps expect the same shape readers produce
     * (json_decode assoc) — plain associative arrays — so flatten recursively here.
     */
    private function toPlainPhp(mixed $data): mixed
    {
        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }
        if (\is_array($data)) {
            return array_map(fn (mixed $item): mixed => $this->toPlainPhp($item), $data);
        }

        return $data;
    }

    private function toValue(mixed $data): Value
    {
        if ($data === null) {
            return Value::null();
        }
        if (\is_bool($data)) {
            return Value::boolean($data);
        }
        if (\is_int($data) || \is_float($data)) {
            return Value::number($data);
        }
        if (\is_string($data)) {
            return Value::string($data);
        }
        if (\is_array($data)) {
            if (array_is_list($data)) {
                return Value::array(array_map(fn (mixed $item): Value => $this->toValue($item), $data));
            }
            $entries = [];
            foreach ($data as $key => $item) {
                $entries[] = [Value::string((string) $key), $this->toValue($item)];
            }

            return Value::object($entries);
        }
        if ($data instanceof \stdClass) {
            return $this->toValue((array) $data);
        }

        // Anything exotic degrades to its JSON string representation.
        return Value::string((string) json_encode($data));
    }

    /**
     * The AST ships as ONE file holding every node class (upstream uses a composer classmap),
     * so PSR-4 cannot autoload anything but `Node` from it — load it explicitly once.
     */
    private static function loadAst(): void
    {
        if (!class_exists(Language\Ast\Script::class, false)) {
            require_once __DIR__ . '/Language/Ast/Node.php';
        }
    }
}
