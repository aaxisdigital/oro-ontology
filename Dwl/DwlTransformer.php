<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl;

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
    /** @return string|null a parse error message, or null when the script is valid */
    public function validate(string $script): ?string
    {
        self::loadAst();
        try {
            Parser::parse($script);
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
        $env = new Environment();
        StandardLibrary::register($env);
        // The whole binding set is also reachable as ONE object — the only way to scripts for
        // keys that are not valid DWL identifiers, e.g. hyphenated destinations via context["some-key"]. Defined first so
        // an actual "context" binding (a step destination named so) still wins.
        $env->define('context', $this->toValue($bindings));
        foreach ($bindings as $name => $value) {
            if (\is_string($name) && $name !== '') {
                $env->define($name, $this->toValue($value));
            }
        }

        $evaluator = new Evaluator($env);

        return $this->toPlainPhp($evaluator->evaluate(Parser::parse($script))->toPhp());
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
