<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Language;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\LexerException;

final class Lexer
{
    private string $source;
    private int $pos = 0;
    private int $line = 1;
    private int $column = 1;
    private int $length;

    private const KEYWORDS = [
        'output' => TokenType::Output,
        'input' => TokenType::Input,
        'var' => TokenType::Var,
        'fun' => TokenType::Fun,
        'type' => TokenType::Type,
        'ns' => TokenType::Ns,
        'import' => TokenType::Import,
        'from' => TokenType::From,
        'as' => TokenType::As,
        'is' => TokenType::Is,
        'if' => TokenType::If,
        'else' => TokenType::Else,
        'unless' => TokenType::Unless,
        'default' => TokenType::Default,
        'do' => TokenType::Do,
        'using' => TokenType::Using,
        'and' => TokenType::And,
        'or' => TokenType::Or,
        'not' => TokenType::Not,
        'case' => TokenType::Case,
        'matches' => TokenType::Matches,
        'match' => TokenType::Match,
        'true' => TokenType::True,
        'false' => TokenType::False,
        'null' => TokenType::NullKeyword,
        'private' => TokenType::Private,
        'async' => TokenType::Async,
        'yield' => TokenType::Yield,
        'for' => TokenType::For,
        'throw' => TokenType::Throw,
        'enum' => TokenType::Enum,
    ];

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->length = strlen($source);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $tokens = [];

        while ($this->pos < $this->length) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= $this->length) {
                break;
            }

            $token = $this->nextToken();
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        $tokens[] = new Token(TokenType::EOF, '', $this->line, $this->column);
        return $tokens;
    }

    private function nextToken(): ?Token
    {
        $ch = $this->source[$this->pos];
        $line = $this->line;
        $col = $this->column;

        // %dw version directive
        if ($ch === '%' && $this->lookAhead('dw ')) {
            return $this->readDwVersion();
        }

        // --- separator
        if ($ch === '-' && $this->lookAhead('--')) {
            $this->advance(3);
            return new Token(TokenType::Separator, '---', $line, $col);
        }

        // Numbers
        if (ctype_digit($ch) || ($ch === '.' && $this->pos + 1 < $this->length && ctype_digit($this->source[$this->pos + 1]))) {
            return $this->readNumber();
        }

        // Strings
        if ($ch === '"' || $ch === '\'') {
            return $this->readString($ch);
        }

        // Backtick strings
        if ($ch === '`') {
            return $this->readString('`');
        }

        // Regex
        if ($ch === '/' && $this->isRegexContext()) {
            return $this->readRegex();
        }

        // Identifiers and keywords
        if (ctype_alpha($ch) || $ch === '_') {
            return $this->readIdentifier();
        }

        // Date/time/period literal: |2003-10-01|, |23:57:59|, |PT1S|… — anything not date-shaped
        // between the pipes falls back to the `|` operator (type unions).
        if ($ch === '|') {
            $literal = $this->tryReadDateLiteral();
            if ($literal !== null) {
                return $literal;
            }
        }

        // Operators and delimiters
        return $this->readOperator();
    }

    /**
     * Attempts to read a DataWeave date/time/period literal. Only consumes input when the pipes
     * wrap something date-shaped on ONE line: an ISO-8601 duration (-?P…) or a value starting
     * like a date (2003-…) / time (23:…). Returns null otherwise, leaving `|` untouched.
     */
    private function tryReadDateLiteral(): ?Token
    {
        $line = $this->line;
        $col = $this->column;
        $end = $this->pos + 1;
        while ($end < $this->length && $end - $this->pos <= 64) {
            $c = $this->source[$end];
            if ($c === '|') {
                $inner = substr($this->source, $this->pos + 1, $end - $this->pos - 1);
                if ($inner !== '' && preg_match('/^(-?P[0-9.YMWDTHS]+|\d{2,4}[:\-][0-9:.TZ+\-]+)$/i', $inner)) {
                    $this->advance($end - $this->pos + 1);
                    return new Token(TokenType::DateLiteral, $inner, $line, $col);
                }
                return null;
            }
            if ($c === "\n" || preg_match('/[^0-9A-Za-z:.+\-]/', $c)) {
                return null;
            }
            $end++;
        }
        return null;
    }

    private function readDwVersion(): Token
    {
        $line = $this->line;
        $col = $this->column;
        $this->advance(3); // skip '%dw'
        $this->skipInlineWhitespace();
        $version = '';
        while ($this->pos < $this->length && (ctype_digit($this->source[$this->pos]) || $this->source[$this->pos] === '.')) {
            $version .= $this->source[$this->pos];
            $this->advance();
        }
        return new Token(TokenType::DwVersion, $version, $line, $col);
    }

    private function readNumber(): Token
    {
        $line = $this->line;
        $col = $this->column;
        $num = '';
        $hasDecimal = false;

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if (ctype_digit($ch)) {
                $num .= $ch;
                $this->advance();
            } elseif ($ch === '.' && !$hasDecimal && $this->pos + 1 < $this->length && ctype_digit($this->source[$this->pos + 1])) {
                $hasDecimal = true;
                $num .= $ch;
                $this->advance();
            } else {
                break;
            }
        }

        return new Token(TokenType::Number, $num, $line, $col);
    }

    private function readString(string $quote): Token
    {
        $line = $this->line;
        $col = $this->column;
        $this->advance(); // skip opening quote
        $str = '';

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\' && $this->pos + 1 < $this->length) {
                $next = $this->source[$this->pos + 1];
                $str .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    $quote => $quote,
                    '$' => '$',
                    default => '\\' . $next,
                };
                $this->advance(2);
                continue;
            }
            if ($ch === $quote) {
                $this->advance(); // skip closing quote
                return new Token(TokenType::String, $str, $line, $col);
            }
            $str .= $ch;
            $this->advance();
        }

        throw new LexerException("Unterminated string starting at line $line, column $col");
    }

    private function readRegex(): Token
    {
        $line = $this->line;
        $col = $this->column;
        $this->advance(); // skip /
        $pattern = '';

        while ($this->pos < $this->length && $this->source[$this->pos] !== '/') {
            if ($this->source[$this->pos] === '\\' && $this->pos + 1 < $this->length) {
                $pattern .= $this->source[$this->pos] . $this->source[$this->pos + 1];
                $this->advance(2);
            } else {
                $pattern .= $this->source[$this->pos];
                $this->advance();
            }
        }

        if ($this->pos < $this->length) {
            $this->advance(); // skip closing /
        }

        return new Token(TokenType::Regex, $pattern, $line, $col);
    }

    private function readIdentifier(): Token
    {
        $line = $this->line;
        $col = $this->column;
        $id = '';

        while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $id .= $this->source[$this->pos];
            $this->advance();
        }

        $lower = strtolower($id);
        if (isset(self::KEYWORDS[$lower])) {
            return new Token(self::KEYWORDS[$lower], $id, $line, $col);
        }

        return new Token(TokenType::Identifier, $id, $line, $col);
    }

    private function readOperator(): Token
    {
        $ch = $this->source[$this->pos];
        $line = $this->line;
        $col = $this->column;
        $next = $this->pos + 1 < $this->length ? $this->source[$this->pos + 1] : '';

        // Two-character operators
        $twoChar = $ch . $next;
        $result = match ($twoChar) {
            '++' => [TokenType::PlusPlus, '++'],
            '--' => [TokenType::MinusMinus, '--'],
            '>=' => [TokenType::GreaterEqual, '>='],
            '<=' => [TokenType::LessEqual, '<='],
            '==' => [TokenType::Equal, '=='],
            '!=' => [TokenType::NotEqual, '!='],
            '->' => [TokenType::Arrow, '->'],
            '=>' => [TokenType::FatArrow, '=>'],
            '~=' => [TokenType::SimilarTo, '~='],
            '..' => [TokenType::DotDot, '..'],
            '.*' => [TokenType::DotStar, '.*'],
            '::' => [TokenType::ColonColon, '::'],
            default => null,
        };

        if ($result !== null) {
            $this->advance(2);
            return new Token($result[0], $result[1], $line, $col);
        }

        // Dollar signs
        if ($ch === '$') {
            if ($next === '$') {
                if ($this->pos + 2 < $this->length && $this->source[$this->pos + 2] === '$') {
                    $this->advance(3);
                    return new Token(TokenType::DollarDollarDollar, '$$$', $line, $col);
                }
                $this->advance(2);
                return new Token(TokenType::DollarDollar, '$$', $line, $col);
            }
            $this->advance();
            return new Token(TokenType::Dollar, '$', $line, $col);
        }

        // Single-character operators
        $this->advance();
        return match ($ch) {
            '+' => new Token(TokenType::Plus, '+', $line, $col),
            '-' => new Token(TokenType::Minus, '-', $line, $col),
            '*' => new Token(TokenType::Multiply, '*', $line, $col),
            '/' => new Token(TokenType::Divide, '/', $line, $col),
            '%' => new Token(TokenType::Modulo, '%', $line, $col),
            '>' => new Token(TokenType::Greater, '>', $line, $col),
            '<' => new Token(TokenType::Less, '<', $line, $col),
            '(' => new Token(TokenType::LeftParen, '(', $line, $col),
            ')' => new Token(TokenType::RightParen, ')', $line, $col),
            '[' => new Token(TokenType::LeftBracket, '[', $line, $col),
            ']' => new Token(TokenType::RightBracket, ']', $line, $col),
            '{' => new Token(TokenType::LeftBrace, '{', $line, $col),
            '}' => new Token(TokenType::RightBrace, '}', $line, $col),
            ',' => new Token(TokenType::Comma, ',', $line, $col),
            ':' => new Token(TokenType::Colon, ':', $line, $col),
            '.' => new Token(TokenType::Dot, '.', $line, $col),
            '@' => new Token(TokenType::At, '@', $line, $col),
            '#' => new Token(TokenType::Hash, '#', $line, $col),
            '|' => new Token(TokenType::Pipe, '|', $line, $col),
            '&' => new Token(TokenType::Ampersand, '&', $line, $col),
            '?' => new Token(TokenType::Question, '?', $line, $col),
            '~' => new Token(TokenType::Tilde, '~', $line, $col),
            '!' => new Token(TokenType::Not, '!', $line, $col),
            '=' => new Token(TokenType::Equal, '=', $line, $col),
            default => throw new LexerException("Unexpected character '$ch' at line $line, column $col"),
        };
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $this->advance();
                continue;
            }

            if ($ch === "\n") {
                $this->advance();
                continue;
            }

            // Line comment
            if ($ch === '/' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '/') {
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
                continue;
            }

            // Block comment
            if ($ch === '/' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '*') {
                $this->advance(2);
                while ($this->pos < $this->length) {
                    if ($this->source[$this->pos] === '*' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '/') {
                        $this->advance(2);
                        break;
                    }
                    $this->advance();
                }
                continue;
            }

            break;
        }
    }

    private function skipInlineWhitespace(): void
    {
        while ($this->pos < $this->length && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")) {
            $this->advance();
        }
    }

    private function advance(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            if ($this->pos < $this->length) {
                if ($this->source[$this->pos] === "\n") {
                    $this->line++;
                    $this->column = 1;
                } else {
                    $this->column++;
                }
                $this->pos++;
            }
        }
    }

    private function lookAhead(string $expected): bool
    {
        $len = strlen($expected);
        if ($this->pos + 1 + $len > $this->length) {
            return false;
        }
        return substr($this->source, $this->pos + 1, $len) === $expected;
    }

    private function isRegexContext(): bool
    {
        // Simple heuristic: / is regex if preceded by operator, keyword, or start of expression
        if ($this->pos === 0) {
            return true;
        }
        $prev = $this->pos - 1;
        while ($prev >= 0 && ($this->source[$prev] === ' ' || $this->source[$prev] === "\t")) {
            $prev--;
        }
        if ($prev < 0) {
            return true;
        }
        $ch = $this->source[$prev];
        return in_array($ch, ['(', '[', '{', ',', ':', '=', '>', '<', '+', '-', '*', '/', '!', '&', '|', '~', ';'], true);
    }
}
