<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ImportDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ModuleRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Parser;

/**
 * Refuses DWL scripts that reach for the engine's environment-introspection function before the
 * DWL playground evaluates them.
 *
 * Why: the playground runs *user-typed* scripts server-side under a read-level permission, and
 * `dw::Runtime::props()` is implemented as `foreach (getenv() as ...)` — it returns the whole
 * process environment. Deployments that pass secrets as real environment variables (docker-compose
 * `environment:`, Kubernetes env, ECS task definitions) would hand a DB DSN / APP_SECRET to anyone
 * who can open the playground, and the Export button would let them save it. The rest of the
 * standard library is pure data manipulation (audited: `getenv()` is its only such sink), so exactly
 * one member needs denying.
 *
 * The check runs on the PARSED AST, never on the script text: the parser normalizes `dw :: Runtime`,
 * `dw::/*c*\/Runtime` and friends to the single string `dw::Runtime`, so a text pattern would be
 * trivially bypassed while an AST equality test is exact. `dw::Runtime::fail` stays usable — only
 * `props` is blocked.
 *
 * Scoped to the playground on purpose: the flow-editor's DWL path is gated on an EDIT-level
 * capability and is not affected.
 */
class DwlScriptGuard
{
    /** Module member that exposes the process environment. */
    private const string DENIED_MODULE = 'dw::Runtime';
    private const string DENIED_MEMBER = 'props';

    private const string MESSAGE = 'This script is not allowed to read the server environment '
        . '(dw::Runtime::props is disabled in the playground).';

    /**
     * @return string|null a user-facing refusal message, or null when the script may run
     */
    public function check(string $script): ?string
    {
        self::loadAst();

        try {
            $ast = Parser::parse($script);
        } catch (\Throwable) {
            // Unparseable: nothing to inspect. Let the transform run so the user sees the real
            // parse error instead of a guard message about it.
            return null;
        }

        $seen = [];

        return $this->walk($ast, $seen) ? self::MESSAGE : null;
    }

    /**
     * Depth-first search for a denied reference. Walks public properties generically (the AST is a
     * large single-file class hierarchy, so a shape-agnostic walk survives upstream node changes).
     *
     * @param array<int, true> $seen visited object ids, guarding against a cyclic tree
     */
    private function walk(mixed $node, array &$seen): bool
    {
        if (\is_array($node)) {
            foreach ($node as $item) {
                if ($this->walk($item, $seen)) {
                    return true;
                }
            }

            return false;
        }
        if (!\is_object($node) || isset($seen[spl_object_id($node)])) {
            return false;
        }
        $seen[spl_object_id($node)] = true;

        if ($this->isDenied($node)) {
            return true;
        }

        foreach (get_object_vars($node) as $value) {
            if ($this->walk($value, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function isDenied(object $node): bool
    {
        // Qualified call: dw::Runtime::props()
        if ($node instanceof ModuleRef) {
            return $node->module === self::DENIED_MODULE && $node->member === self::DENIED_MEMBER;
        }

        // Unqualified use after an import: `import * from dw::Runtime` or `import props from ...`.
        if ($node instanceof ImportDirective && $node->module === self::DENIED_MODULE) {
            return $node->importAll || \in_array(self::DENIED_MEMBER, $node->names, true);
        }

        return false;
    }

    /**
     * The engine ships every AST class in ONE file (upstream relies on a Composer classmap), so
     * PSR-4 cannot autoload them. Mirrors DwlTransformer's own private loader — duplicated rather
     * than shared because Dwl/ is vendored code this bundle must not modify.
     */
    private static function loadAst(): void
    {
        if (!class_exists(ModuleRef::class, false)) {
            require_once __DIR__ . '/../Dwl/Language/Ast/Node.php';
        }
    }
}
