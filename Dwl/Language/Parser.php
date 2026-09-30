<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Language;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ArrayLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BinaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BooleanLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ConditionalExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DescendantSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Directive;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DoExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DollarRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunParam;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Identifier;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ImportDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\IndexAccess;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InfixFunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\LambdaExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MatchCase;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MatchExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MemberAccess;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ModuleRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MultiValueSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NamespaceSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Node;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NsDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NullLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NumberLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectEntry;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\OutputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RegexLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Script;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DateTimeLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\StringLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeCoercion;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeReference;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\UnaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\VarDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\ParserException;

final class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;

    /** @param Token[] $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public static function parse(string $source): Script
    {
        $lexer = new Lexer($source);
        $tokens = $lexer->tokenize();
        $parser = new self($tokens);
        return $parser->parseScript();
    }

    public function parseScript(): Script
    {
        $version = null;
        $directives = [];
        $line = $this->current()->line;
        $col = $this->current()->column;

        // Parse optional %dw version
        if ($this->check(TokenType::DwVersion)) {
            $version = $this->consume(TokenType::DwVersion)->value;
        }

        // Parse header directives until ---
        while (!$this->check(TokenType::Separator) && !$this->check(TokenType::EOF) && $this->isDirectiveKeyword()) {
            $directives[] = $this->parseDirective();
        }

        // Consume --- if present
        if ($this->check(TokenType::Separator)) {
            $this->advance();
        }

        // Parse body — check for shorthand object syntax (identifier: value)
        if ($this->check(TokenType::Identifier) && $this->peek()->type === TokenType::Colon) {
            $body = $this->parseImplicitObject();
        } else {
            $body = $this->parseExpression();
        }

        return new Script($version, $directives, $body, $line, $col);
    }

    private function parseDirective(): Directive
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::Output => $this->parseOutputDirective(),
            TokenType::Input => $this->parseInputDirective(),
            TokenType::Var => $this->parseVarDirective(),
            TokenType::Fun => $this->parseFunDirective(),
            TokenType::Type => $this->parseTypeDirective(),
            TokenType::Ns => $this->parseNsDirective(),
            TokenType::Import => $this->parseImportDirective(),
            TokenType::Private => $this->parsePrivateDirective(),
            default => throw new ParserException(
                "Unexpected token {$token->type->value} '{$token->value}' at line {$token->line}, column {$token->column}"
            ),
        };
    }

    private function parseOutputDirective(): OutputDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Output);
        $mimeType = $this->parseMimeType();
        $options = $this->parseFormatOptions();
        return new OutputDirective($mimeType, $options, $line, $col);
    }

    private function parseInputDirective(): InputDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Input);
        $name = $this->consume(TokenType::Identifier)->value;
        $mimeType = $this->parseMimeType();
        $options = $this->parseFormatOptions();
        return new InputDirective($name, $mimeType, $options, $line, $col);
    }

    private function parseMimeType(): string
    {
        $mime = $this->consume(TokenType::Identifier)->value;
        if ($this->check(TokenType::Divide)) {
            $this->advance();
            $subtype = $this->consumeAny()->value;
            $mime .= '/' . $subtype;
            // Handle subtypes like x-ndjson, x-www-form-urlencoded
            while ($this->check(TokenType::Minus)) {
                $this->advance();
                $mime .= '-' . $this->consumeAny()->value;
            }
        }
        return $mime;
    }

    private function parseFormatOptions(): array
    {
        $options = [];
        while (!$this->check(TokenType::EOF) && !$this->check(TokenType::Separator) && !$this->isDirectiveKeyword()) {
            if ($this->check(TokenType::Comma)) {
                $this->advance();
                continue;
            }
            if (!$this->check(TokenType::Identifier)) {
                break;
            }
            $key = $this->consume(TokenType::Identifier)->value;
            if ($this->check(TokenType::Equal)) {
                $this->advance();
                if ($this->check(TokenType::String)) {
                    $value = $this->advance()->value;
                } else {
                    $value = $this->consumeAny()->value;
                }
                $options[$key] = $value;
            } else {
                $options[$key] = true;
            }
        }
        return $options;
    }

    private function parseVarDirective(): VarDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Var);
        $name = $this->consume(TokenType::Identifier)->value;
        $this->consume(TokenType::Equal);
        $value = $this->parseExpression();
        return new VarDirective($name, $value, $line, $col);
    }

    private function parseFunDirective(): FunDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Fun);
        $name = $this->consume(TokenType::Identifier)->value;
        $this->consume(TokenType::LeftParen);
        $params = $this->parseFunParams();
        $this->consume(TokenType::RightParen);
        $this->consume(TokenType::Equal);
        $body = $this->parseExpression();
        return new FunDirective($name, $params, $body, $line, $col);
    }

    /** @return FunParam[] */
    private function parseFunParams(): array
    {
        $params = [];
        while (!$this->check(TokenType::RightParen)) {
            $name = $this->consume(TokenType::Identifier)->value;
            $type = null;
            $default = null;
            if ($this->check(TokenType::Colon)) {
                $this->advance();
                $type = $this->consume(TokenType::Identifier)->value;
            }
            if ($this->check(TokenType::Equal)) {
                $this->advance();
                $default = $this->parseExpression();
            }
            $params[] = new FunParam($name, $type, $default);
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            }
        }
        return $params;
    }

    private function parseTypeDirective(): TypeDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Type);
        $name = $this->consume(TokenType::Identifier)->value;
        $this->consume(TokenType::Equal);
        $def = $this->parseTypeExpression();
        return new TypeDirective($name, $def, $line, $col);
    }

    private function parseTypeExpression(): Node
    {
        $name = $this->consume(TokenType::Identifier)->value;
        $metadata = $this->parseTypeMetadata();
        return new TypeReference($name, $metadata, $this->current()->line, $this->current()->column);
    }

    /**
     * The optional `{key: value, …}` block after a type name (`String {format: "yyyy"}`).
     *
     * @return array<string, string>
     */
    private function parseTypeMetadata(): array
    {
        $metadata = [];
        if ($this->check(TokenType::LeftBrace)) {
            $this->advance();
            while (!$this->check(TokenType::RightBrace)) {
                $key = $this->consume(TokenType::Identifier)->value;
                $this->consume(TokenType::Colon);
                $val = $this->consumeAny()->value;
                $metadata[$key] = $val;
                if ($this->check(TokenType::Comma)) {
                    $this->advance();
                }
            }
            $this->consume(TokenType::RightBrace);
        }
        return $metadata;
    }

    private function parseNsDirective(): NsDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Ns);
        $prefix = $this->consume(TokenType::Identifier)->value;
        // URI is everything until end of line - read as identifier/string tokens
        $uri = '';
        while (!$this->check(TokenType::EOF) && !$this->isDirectiveKeyword() && !$this->check(TokenType::Separator)) {
            $uri .= $this->consumeAny()->value;
            // Handle :// and other URI parts
            while ($this->check(TokenType::Colon) || $this->check(TokenType::ColonColon) || $this->check(TokenType::Divide) || $this->check(TokenType::Dot) || $this->check(TokenType::Minus)) {
                $uri .= $this->consumeAny()->value;
            }
        }
        return new NsDirective($prefix, trim($uri), $line, $col);
    }

    private function parseImportDirective(): ImportDirective
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Import);

        $names = [];
        $importAll = false;

        if ($this->check(TokenType::Multiply)) {
            $this->advance();
            $importAll = true;
        } else {
            $names[] = $this->consume(TokenType::Identifier)->value;
            while ($this->check(TokenType::Comma)) {
                $this->advance();
                $names[] = $this->consume(TokenType::Identifier)->value;
            }
        }

        $this->consume(TokenType::From);
        $module = $this->parseModulePath();

        return new ImportDirective($names, $module, $importAll, $line, $col);
    }

    private function parseModulePath(): string
    {
        $path = $this->consume(TokenType::Identifier)->value;
        while ($this->check(TokenType::ColonColon)) {
            $this->advance();
            $path .= '::' . $this->consume(TokenType::Identifier)->value;
        }
        return $path;
    }

    private function parsePrivateDirective(): Directive
    {
        $this->consume(TokenType::Private);
        return $this->parseDirective(); // Private is just a visibility modifier
    }

    private function parseImplicitObject(): ObjectLiteral
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $entries = [];

        while ($this->check(TokenType::Identifier) && $this->peek()->type === TokenType::Colon && !$this->check(TokenType::EOF)) {
            $key = $this->parseObjectKey();
            $this->consume(TokenType::Colon);
            $value = $this->parseExpression();
            $entries[] = new ObjectEntry($key, $value);
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            }
        }

        return new ObjectLiteral($entries, $line, $col);
    }

    // === Expression parsing (Pratt parser) ===

    public function parseExpression(int $precedence = 0): Node
    {
        $left = $this->parsePrimary();
        return $this->parseInfix($left, $precedence);
    }

    private function parseInfix(Node $left, int $precedence): Node
    {
        while (true) {
            $token = $this->current();

            // Binary operators
            $opPrec = $this->getInfixPrecedence($token);
            if ($opPrec <= $precedence) {
                break;
            }

            $next = $this->parseInfixOperation($left, $token, $opPrec);
            if ($next === null) {
                break;
            }
            $left = $next;
        }

        return $left;
    }

    /**
     * One infix step on $left; null when the token turns out to start no infix form (a dangling
     * `.`/`..` selector, which is consumed and dropped).
     */
    private function parseInfixOperation(Node $left, Token $token, int $opPrec): ?Node
    {
        return match (true) {
            $this->isBinaryOperator($token)
                => new BinaryOp($left, $token->value, $this->parseRightOperand($opPrec), $token->line, $token->column),
            // Infix function call (value functionName value) and the keyword-based infix
            // operators: default, match
            $this->isInfixCallToken($token)
                => new InfixFunctionCall($left, $token->value, $this->parseRightOperand($opPrec), $token->line, $token->column),
            default => $this->parseSelector($left, $token),
        };
    }

    private function parseRightOperand(int $opPrec): Node
    {
        $this->advance();
        return $this->parseExpression($opPrec);
    }

    private function isInfixCallToken(Token $token): bool
    {
        return ($token->type === TokenType::Identifier && $this->isInfixFunction($token->value))
            || $token->type === TokenType::Default || $token->type === TokenType::Match;
    }

    private function parseSelector(Node $left, Token $token): ?Node
    {
        return match ($token->type) {
            TokenType::Dot => $this->parseDotSelector($left, $token, false),
            TokenType::DotDot => $this->parseDotSelector($left, $token, true),
            TokenType::DotStar => $this->parseMultiValueSelector($left, $token),
            TokenType::LeftBracket => $this->parseIndexAccess($left, $token),
            TokenType::As => $this->parseTypeCoercion($left, $token),
            TokenType::Is => $this->parseIsCheck($left, $token),
            default => null,
        };
    }

    /**
     * Member access `.property` and the descendant selector `..property` (recursively collects all
     * matching values). The key may also be QUOTED (.'some key' / ."some key"), which DataWeave
     * allows for keys that are not valid identifiers (or just quoted, as in `$.'type'`).
     */
    private function parseDotSelector(Node $left, Token $token, bool $descendant): ?Node
    {
        $this->advance();
        if (!$this->check(TokenType::Identifier) && !$this->isKeywordToken($this->current())
            && !$this->check(TokenType::String)
        ) {
            return null;
        }
        $prop = $this->advance()->value;

        return $descendant
            ? new DescendantSelector($left, $prop, $token->line, $token->column)
            : new MemberAccess($left, $prop, $token->line, $token->column);
    }

    /** Multi-value selector: .*property (quoted keys allowed, like the plain selector) */
    private function parseMultiValueSelector(Node $left, Token $token): MultiValueSelector
    {
        $this->advance();
        $prop = $this->check(TokenType::String)
            ? $this->advance()->value
            : $this->consume(TokenType::Identifier)->value;
        return new MultiValueSelector($left, $prop, $token->line, $token->column);
    }

    /** Index access: [expr] */
    private function parseIndexAccess(Node $left, Token $token): IndexAccess
    {
        $this->advance();
        $index = $this->parseExpression();
        $this->consume(TokenType::RightBracket);
        return new IndexAccess($left, $index, $token->line, $token->column);
    }

    /** Type coercion: expr as Type {metadata} */
    private function parseTypeCoercion(Node $left, Token $token): TypeCoercion
    {
        $this->advance();
        $typeName = $this->consume(TokenType::Identifier)->value;
        $metadata = $this->parseTypeMetadata();
        $typeRef = new TypeReference($typeName, $metadata, $token->line, $token->column);
        return new TypeCoercion($left, $typeRef, $token->line, $token->column);
    }

    /** is Type check */
    private function parseIsCheck(Node $left, Token $token): BinaryOp
    {
        $this->advance();
        $typeName = $this->consume(TokenType::Identifier)->value;
        return new BinaryOp($left, 'is', new Identifier($typeName, $token->line, $token->column), $token->line, $token->column);
    }

    private function parsePrimary(): Node
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::DateLiteral, TokenType::Number, TokenType::String, TokenType::True, TokenType::False,
            TokenType::NullKeyword, TokenType::Regex,
            TokenType::Dollar, TokenType::DollarDollar, TokenType::DollarDollarDollar => $this->parseLiteral($token),
            TokenType::Minus, TokenType::Not => $this->parseUnary($token),
            // Parenthesized expression or lambda
            TokenType::LeftParen => $this->parseParenOrLambda(),
            TokenType::LeftBracket => $this->parseArray(),
            TokenType::LeftBrace => $this->parseObject(),
            TokenType::If => $this->parseIfExpression(),
            TokenType::Match => $this->parseMatchExpression(),
            TokenType::Do => $this->parseDoExpression(),
            // Identifier (variable, function call, module ref)
            TokenType::Identifier => $this->parseIdentifierExpression(),
            default => throw new ParserException(
                "Unexpected token {$token->type->value} '{$token->value}' at line {$token->line}, column {$token->column}"
            ),
        };
    }

    /** Literals (date/time/period, number, string, boolean, null, regex) and $ / $$ / $$$. */
    private function parseLiteral(Token $token): Node
    {
        $this->advance();

        return match ($token->type) {
            TokenType::DateLiteral => new DateTimeLiteral($token->value, $token->line, $token->column),
            TokenType::Number => new NumberLiteral(
                str_contains($token->value, '.') ? (float) $token->value : (int) $token->value,
                $token->line,
                $token->column
            ),
            TokenType::String => new StringLiteral($token->value, $token->line, $token->column),
            TokenType::True => new BooleanLiteral(true, $token->line, $token->column),
            TokenType::False => new BooleanLiteral(false, $token->line, $token->column),
            TokenType::NullKeyword => new NullLiteral($token->line, $token->column),
            TokenType::Regex => new RegexLiteral($token->value, $token->line, $token->column),
            TokenType::Dollar => new DollarRef(1, $token->line, $token->column),
            TokenType::DollarDollar => new DollarRef(2, $token->line, $token->column),
            TokenType::DollarDollarDollar => new DollarRef(3, $token->line, $token->column),
        };
    }

    /** Unary minus binds to the next primary; `not` / `!` to a whole comparison. */
    private function parseUnary(Token $token): UnaryOp
    {
        $this->advance();

        return $token->type === TokenType::Minus
            ? new UnaryOp('-', $this->parsePrimary(), $token->line, $token->column)
            : new UnaryOp('not', $this->parseExpression(12), $token->line, $token->column);
    }

    private function parseParenOrLambda(): Node
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::LeftParen);

        // Check if this is a lambda: (params) -> body
        if ($this->isLambdaParams()) {
            $params = $this->parseFunParams();
            $this->consume(TokenType::RightParen);
            $this->consume(TokenType::Arrow);
            $body = $this->parseExpression();
            return new LambdaExpression($params, $body, $line, $col);
        }

        // Dynamic key in object: (expr)
        $expr = $this->parseExpression();
        $this->consume(TokenType::RightParen);

        // If followed by ':', this is an implicit single-entry object: (key): value
        if ($this->check(TokenType::Colon)) {
            $this->advance();
            $value = $this->parseExpression();
            $entry = new ObjectEntry($expr, $value, false, null, true);
            return new ObjectLiteral([$entry], $line, $col);
        }

        return $expr;
    }

    private function isLambdaParams(): bool
    {
        // Look ahead to see if this matches (id, id, ...) ->
        $saved = $this->pos;
        $depth = 0;
        while ($this->pos < count($this->tokens)) {
            $t = $this->tokens[$this->pos];
            if ($t->type === TokenType::LeftParen) {
                $depth++;
            }
            if ($t->type === TokenType::RightParen) {
                if ($depth === 0) {
                    $this->pos++;
                    $isLambda = $this->check(TokenType::Arrow);
                    $this->pos = $saved;
                    return $isLambda;
                }
                $depth--;
            }
            $this->pos++;
        }
        $this->pos = $saved;
        return false;
    }

    private function parseArray(): ArrayLiteral
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::LeftBracket);
        $elements = [];

        while (!$this->check(TokenType::RightBracket) && !$this->check(TokenType::EOF)) {
            $elements[] = $this->parseExpression();
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            }
        }

        $this->consume(TokenType::RightBracket);
        return new ArrayLiteral($elements, $line, $col);
    }

    private function parseObject(): ObjectLiteral
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::LeftBrace);
        $entries = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            // Check for dynamic key with (expr)
            if ($this->check(TokenType::LeftParen)) {
                $this->advance();
                $expr = $this->parseExpression();
                $this->consume(TokenType::RightParen);

                // If followed by colon, it's a dynamic key: (expr): value
                if ($this->check(TokenType::Colon)) {
                    $this->consume(TokenType::Colon);
                    $value = $this->parseExpression();
                    $entries[] = new ObjectEntry($expr, $value, false, null, true);
                } else {
                    // Spread/dynamic entry: (expr)
                    $entries[] = new ObjectEntry($expr, new NullLiteral(), true);
                }
                if ($this->check(TokenType::Comma)) {
                    $this->advance();
                }
                continue;
            }

            $key = $this->parseObjectKey();
            $this->consume(TokenType::Colon);
            $value = $this->parseExpression();

            $entries[] = new ObjectEntry($key, $value);
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            }
        }

        $this->consume(TokenType::RightBrace);
        return new ObjectLiteral($entries, $line, $col);
    }

    private function parseObjectKey(): Node
    {
        $token = $this->current();

        return match (true) {
            $token->type === TokenType::String => new StringLiteral($this->advance()->value, $token->line, $token->column),
            $token->type === TokenType::Identifier => $this->parseIdentifierKey($token),
            // Allow keywords as object keys (e.g., type, name, default, etc.)
            $this->isKeywordToken($token) => new Identifier($this->advance()->value, $token->line, $token->column),
            default => throw new ParserException(
                "Expected object key at line {$token->line}, column {$token->column}"
            ),
        };
    }

    /** A plain identifier key, or a namespace-prefixed one: ns0#name */
    private function parseIdentifierKey(Token $token): Node
    {
        $this->advance();
        if (!$this->check(TokenType::Hash)) {
            return new Identifier($token->value, $token->line, $token->column);
        }
        $this->advance();
        $name = $this->consume(TokenType::Identifier)->value;
        return new NamespaceSelector($token->value, $name, $token->line, $token->column);
    }

    private function isKeywordToken(Token $token): bool
    {
        return in_array($token->type, [
            TokenType::Type, TokenType::Default, TokenType::Match, TokenType::Case,
            TokenType::As, TokenType::Is, TokenType::If, TokenType::Else,
            TokenType::And, TokenType::Or, TokenType::Not, TokenType::Do,
            TokenType::Var, TokenType::Fun, TokenType::Ns, TokenType::Import,
            TokenType::From, TokenType::Input, TokenType::Output, TokenType::Using,
            TokenType::Private, TokenType::Async, TokenType::Yield, TokenType::For,
            TokenType::Throw, TokenType::Enum, TokenType::Unless, TokenType::Matches,
            TokenType::True, TokenType::False, TokenType::NullKeyword,
        ], true);
    }

    private function parseIfExpression(): ConditionalExpression
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::If);
        $this->consume(TokenType::LeftParen);
        $condition = $this->parseExpression();
        $this->consume(TokenType::RightParen);
        $then = $this->parseExpression();
        $this->consume(TokenType::Else);
        $else = $this->parseExpression();
        return new ConditionalExpression($condition, $then, $else, $line, $col);
    }

    private function parseMatchExpression(): MatchExpression
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Match);
        // match is used as infix: expr match { cases }
        // But here we handle standalone match { cases }
        $this->consume(TokenType::LeftBrace);
        $cases = [];
        while (!$this->check(TokenType::RightBrace)) {
            if ($this->check(TokenType::Else)) {
                $this->advance();
                $this->consume(TokenType::Arrow);
                $body = $this->parseExpression();
                $cases[] = new MatchCase(null, $body, true);
            } else {
                if ($this->check(TokenType::Case)) {
                    $this->advance();
                }
                // Check for binding: name if/is pattern
                $pattern = $this->parseExpression();
                $this->consume(TokenType::Arrow);
                $body = $this->parseExpression();
                $cases[] = new MatchCase($pattern, $body);
            }
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            }
        }
        $this->consume(TokenType::RightBrace);
        return new MatchExpression(new NullLiteral(), $cases, $line, $col);
    }

    private function parseDoExpression(): DoExpression
    {
        $line = $this->current()->line;
        $col = $this->current()->column;
        $this->consume(TokenType::Do);
        $this->consume(TokenType::LeftBrace);
        $directives = [];
        while (!$this->check(TokenType::Separator) && !$this->check(TokenType::RightBrace)) {
            $directives[] = $this->parseDirective();
        }
        if ($this->check(TokenType::Separator)) {
            $this->advance();
        }
        $body = $this->parseExpression();
        $this->consume(TokenType::RightBrace);
        return new DoExpression($directives, $body, $line, $col);
    }

    private function parseIdentifierExpression(): Node
    {
        $token = $this->advance();

        // Module reference (Module::member) or plain name
        $callee = $this->check(TokenType::ColonColon)
            ? $this->parseModuleRef($token)
            : new Identifier($token->value, $token->line, $token->column);
        if (!$this->check(TokenType::LeftParen)) {
            return $callee;
        }

        // Function call: name(args) / Module::member(args)
        $this->advance();
        $args = $this->parseArgList();
        $this->consume(TokenType::RightParen);
        return new FunctionCall($callee, $args, $token->line, $token->column);
    }

    private function parseModuleRef(Token $token): ModuleRef
    {
        $module = $token->value;
        while ($this->check(TokenType::ColonColon)) {
            $this->advance();
            $member = $this->consume(TokenType::Identifier)->value;
            $module .= '::' . $member;
        }
        // The last segment is the member, everything before is the module
        $lastSep = strrpos($module, '::');
        $modulePath = substr($module, 0, $lastSep);
        $memberName = substr($module, $lastSep + 2);

        return new ModuleRef($modulePath, $memberName, $token->line, $token->column);
    }

    /** @return Node[] */
    private function parseArgList(): array
    {
        $args = [];
        while (!$this->check(TokenType::RightParen) && !$this->check(TokenType::EOF)) {
            $args[] = $this->parseExpression();
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            }
        }
        return $args;
    }

    // === Operator precedence ===

    private function getInfixPrecedence(Token $token): int
    {
        return match ($token->type) {
            TokenType::Or => 1,
            TokenType::And => 2,
            TokenType::Default => 3,
            TokenType::Match => 3,
            TokenType::Identifier => $this->isInfixFunction($token->value) ? 3 : 0,
            TokenType::Equal, TokenType::NotEqual, TokenType::SimilarTo => 5,
            TokenType::Greater, TokenType::GreaterEqual, TokenType::Less, TokenType::LessEqual => 6,
            TokenType::Is, TokenType::As => 6,
            TokenType::PlusPlus, TokenType::MinusMinus => 7,
            TokenType::Plus, TokenType::Minus => 8,
            TokenType::Multiply, TokenType::Divide, TokenType::Modulo => 9,
            TokenType::Dot, TokenType::DotStar, TokenType::DotDot => 11,
            TokenType::LeftBracket => 11,
            default => 0,
        };
    }

    private function isBinaryOperator(Token $token): bool
    {
        return match ($token->type) {
            TokenType::Plus, TokenType::Minus, TokenType::Multiply, TokenType::Divide, TokenType::Modulo,
            TokenType::PlusPlus, TokenType::MinusMinus,
            TokenType::Greater, TokenType::GreaterEqual, TokenType::Less, TokenType::LessEqual,
            TokenType::Equal, TokenType::NotEqual, TokenType::SimilarTo,
            TokenType::And, TokenType::Or => true,
            default => false,
        };
    }

    private function isInfixFunction(string $name): bool
    {
        return in_array($name, [
            'map', 'flatMap', 'filter', 'reduce', 'groupBy', 'orderBy',
            'distinctBy', 'mapObject', 'pluck', 'joinBy', 'splitBy',
            'contains', 'startsWith', 'endsWith', 'replace', 'with',
            'update', 'to', 'until', 'unless', 'default', 'match',
            'matches', 'scan', 'zip', 'unzip', 'sizeOf', 'sizeof',
            'typeOf', 'typeof', 'maxBy', 'minBy', 'every', 'some',
            // Arithmetic + the two-argument dw::core::Arrays functions (any 2-arg function is
            // infix-callable in DataWeave; the evaluator resolves non-builtins from the env).
            'mod', 'take', 'drop', 'takeWhile', 'dropWhile', 'divideBy',
            'countBy', 'sumBy', 'partition', 'splitAt', 'splitWhere',
            'firstWith', 'indexOf', 'lastIndexOf', 'indexWhere',
        ], true);
    }

    private function isDirectiveKeyword(): bool
    {
        return in_array($this->current()->type, [
            TokenType::Output, TokenType::Input, TokenType::Var, TokenType::Fun,
            TokenType::Type, TokenType::Ns, TokenType::Import, TokenType::Private,
            TokenType::Separator, TokenType::DwVersion,
        ], true);
    }

    // === Token helpers ===

    private function current(): Token
    {
        return $this->tokens[$this->pos] ?? new Token(TokenType::EOF, '', 0, 0);
    }

    private function peek(int $offset = 1): Token
    {
        return $this->tokens[$this->pos + $offset] ?? new Token(TokenType::EOF, '', 0, 0);
    }

    private function advance(): Token
    {
        $token = $this->current();
        $this->pos++;
        return $token;
    }

    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    private function consume(TokenType $type): Token
    {
        $token = $this->current();
        if ($token->type !== $type) {
            throw new ParserException(
                "Expected {$type->value} but got {$token->type->value} '{$token->value}' at line {$token->line}, column {$token->column}"
            );
        }
        $this->pos++;
        return $token;
    }

    private function consumeAny(): Token
    {
        return $this->advance();
    }
}
