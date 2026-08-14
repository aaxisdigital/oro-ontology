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
        return new TypeReference($name, $metadata, $this->current()->line, $this->current()->column);
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
        $directive = $this->parseDirective();
        return $directive; // Private is just a visibility modifier
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

            // Handle different infix forms
            if ($this->isBinaryOperator($token)) {
                $op = $token->value;
                $this->advance();
                $right = $this->parseExpression($opPrec);
                $left = new BinaryOp($left, $op, $right, $token->line, $token->column);
                continue;
            }

            // Infix function call: value functionName value
            if ($token->type === TokenType::Identifier && $this->isInfixFunction($token->value)) {
                $name = $token->value;
                $this->advance();
                $right = $this->parseExpression($opPrec);
                $left = new InfixFunctionCall($left, $name, $right, $token->line, $token->column);
                continue;
            }

            // Keyword-based infix operators: default, match
            if ($token->type === TokenType::Default || $token->type === TokenType::Match) {
                $name = $token->value;
                $this->advance();
                $right = $this->parseExpression($opPrec);
                $left = new InfixFunctionCall($left, $name, $right, $token->line, $token->column);
                continue;
            }

            // Member access: .property
            if ($token->type === TokenType::Dot) {
                $this->advance();
                if ($this->check(TokenType::Identifier) || $this->isKeywordToken($this->current())) {
                    $prop = $this->advance()->value;
                    $left = new MemberAccess($left, $prop, $token->line, $token->column);
                    continue;
                }
            }

            // Descendant selector: ..property (recursively collects all matching values)
            if ($token->type === TokenType::DotDot) {
                $this->advance();
                if ($this->check(TokenType::Identifier) || $this->isKeywordToken($this->current())) {
                    $prop = $this->advance()->value;
                    $left = new DescendantSelector($left, $prop, $token->line, $token->column);
                    continue;
                }
            }

            // Multi-value selector: .*property
            if ($token->type === TokenType::DotStar) {
                $this->advance();
                $prop = $this->consume(TokenType::Identifier)->value;
                $left = new MultiValueSelector($left, $prop, $token->line, $token->column);
                continue;
            }

            // Index access: [expr]
            if ($token->type === TokenType::LeftBracket) {
                $this->advance();
                $index = $this->parseExpression();
                $this->consume(TokenType::RightBracket);
                $left = new IndexAccess($left, $index, $token->line, $token->column);
                continue;
            }

            // Type coercion: expr as Type
            if ($token->type === TokenType::As) {
                $this->advance();
                $typeName = $this->consume(TokenType::Identifier)->value;
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
                $typeRef = new TypeReference($typeName, $metadata, $token->line, $token->column);
                $left = new TypeCoercion($left, $typeRef, $token->line, $token->column);
                continue;
            }

            // is Type check
            if ($token->type === TokenType::Is) {
                $this->advance();
                $typeName = $this->consume(TokenType::Identifier)->value;
                $left = new BinaryOp($left, 'is', new Identifier($typeName, $token->line, $token->column), $token->line, $token->column);
                continue;
            }

            break;
        }

        return $left;
    }

    private function parsePrimary(): Node
    {
        $token = $this->current();

        // Date/time/period literal (|2003-10-01|, |PT1S|…)
        if ($token->type === TokenType::DateLiteral) {
            $this->advance();
            return new DateTimeLiteral($token->value, $token->line, $token->column);
        }

        // Number literal
        if ($token->type === TokenType::Number) {
            $this->advance();
            $val = str_contains($token->value, '.') ? (float) $token->value : (int) $token->value;
            return new NumberLiteral($val, $token->line, $token->column);
        }

        // String literal
        if ($token->type === TokenType::String) {
            $this->advance();
            return new StringLiteral($token->value, $token->line, $token->column);
        }

        // Boolean
        if ($token->type === TokenType::True) {
            $this->advance();
            return new BooleanLiteral(true, $token->line, $token->column);
        }
        if ($token->type === TokenType::False) {
            $this->advance();
            return new BooleanLiteral(false, $token->line, $token->column);
        }

        // Null
        if ($token->type === TokenType::NullKeyword) {
            $this->advance();
            return new NullLiteral($token->line, $token->column);
        }

        // Regex
        if ($token->type === TokenType::Regex) {
            $this->advance();
            return new RegexLiteral($token->value, $token->line, $token->column);
        }

        // Dollar references
        if ($token->type === TokenType::Dollar) {
            $this->advance();
            return new DollarRef(1, $token->line, $token->column);
        }
        if ($token->type === TokenType::DollarDollar) {
            $this->advance();
            return new DollarRef(2, $token->line, $token->column);
        }
        if ($token->type === TokenType::DollarDollarDollar) {
            $this->advance();
            return new DollarRef(3, $token->line, $token->column);
        }

        // Unary minus
        if ($token->type === TokenType::Minus) {
            $this->advance();
            $operand = $this->parsePrimary();
            return new UnaryOp('-', $operand, $token->line, $token->column);
        }

        // Not operator
        if ($token->type === TokenType::Not) {
            $this->advance();
            $operand = $this->parseExpression(12);
            return new UnaryOp('not', $operand, $token->line, $token->column);
        }

        // Parenthesized expression or lambda
        if ($token->type === TokenType::LeftParen) {
            return $this->parseParenOrLambda();
        }

        // Array literal
        if ($token->type === TokenType::LeftBracket) {
            return $this->parseArray();
        }

        // Object literal
        if ($token->type === TokenType::LeftBrace) {
            return $this->parseObject();
        }

        // If expression
        if ($token->type === TokenType::If) {
            return $this->parseIfExpression();
        }

        // Match expression
        if ($token->type === TokenType::Match) {
            return $this->parseMatchExpression();
        }

        // Do expression
        if ($token->type === TokenType::Do) {
            return $this->parseDoExpression();
        }

        // Identifier (variable, function call, module ref)
        if ($token->type === TokenType::Identifier) {
            return $this->parseIdentifierExpression();
        }

        throw new ParserException(
            "Unexpected token {$token->type->value} '{$token->value}' at line {$token->line}, column {$token->column}"
        );
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

        if ($token->type === TokenType::String) {
            $this->advance();
            return new StringLiteral($token->value, $token->line, $token->column);
        }

        if ($token->type === TokenType::Identifier) {
            $this->advance();
            // Check for namespace prefix: ns0#name
            if ($this->check(TokenType::Hash)) {
                $prefix = $token->value;
                $this->advance();
                $name = $this->consume(TokenType::Identifier)->value;
                return new NamespaceSelector($prefix, $name, $token->line, $token->column);
            }
            return new Identifier($token->value, $token->line, $token->column);
        }

        // Allow keywords as object keys (e.g., type, name, default, etc.)
        if ($this->isKeywordToken($token)) {
            $this->advance();
            return new Identifier($token->value, $token->line, $token->column);
        }

        throw new ParserException(
            "Expected object key at line {$token->line}, column {$token->column}"
        );
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
                $binding = null;
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
        $token = $this->current();
        $this->advance();
        $name = $token->value;

        // Module reference: Module::member
        if ($this->check(TokenType::ColonColon)) {
            $module = $name;
            while ($this->check(TokenType::ColonColon)) {
                $this->advance();
                $member = $this->consume(TokenType::Identifier)->value;
                $module .= '::' . $member;
            }
            // The last segment is the member, everything before is the module
            $lastSep = strrpos($module, '::');
            $modulePath = substr($module, 0, $lastSep);
            $memberName = substr($module, $lastSep + 2);

            // Check if it's a function call
            if ($this->check(TokenType::LeftParen)) {
                $this->advance();
                $args = $this->parseArgList();
                $this->consume(TokenType::RightParen);
                return new FunctionCall(
                    new ModuleRef($modulePath, $memberName, $token->line, $token->column),
                    $args,
                    $token->line,
                    $token->column
                );
            }

            return new ModuleRef($modulePath, $memberName, $token->line, $token->column);
        }

        // Function call: name(args)
        if ($this->check(TokenType::LeftParen)) {
            $this->advance();
            $args = $this->parseArgList();
            $this->consume(TokenType::RightParen);
            return new FunctionCall(
                new Identifier($name, $token->line, $token->column),
                $args,
                $token->line,
                $token->column
            );
        }

        return new Identifier($name, $token->line, $token->column);
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
