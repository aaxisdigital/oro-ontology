<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Language;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\LexerException;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Lexer;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Token;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\TokenType;
use PHPUnit\Framework\TestCase;

class LexerTest extends TestCase
{
    /**
     * @dataProvider tokenStreams
     *
     * @param array<int, array{0: TokenType, 1: string}> $expected every token but the trailing EOF
     */
    public function testTokenizes(string $source, array $expected): void
    {
        $tokens = (new Lexer($source))->tokenize();

        $eof = array_pop($tokens);
        self::assertSame(TokenType::EOF, $eof->type);
        self::assertSame(
            $expected,
            array_map(static fn (Token $t): array => [$t->type, $t->value], $tokens)
        );
    }

    public static function tokenStreams(): array
    {
        return [
            'empty source' => ['', []],
            'only whitespace and comments' => [" \t\r\n// line\n/* block */", []],
            'version directive' => ['%dw 2.0', [[TokenType::DwVersion, '2.0']]],
            'percent alone is modulo' => ['5 % 2', [[TokenType::Number, '5'], [TokenType::Modulo, '%'], [TokenType::Number, '2']]],
            'percent at end' => ['%d', [[TokenType::Modulo, '%'], [TokenType::Identifier, 'd']]],
            'separator' => ['---', [[TokenType::Separator, '---']]],
            'minus minus' => ['a -- b', [[TokenType::Identifier, 'a'], [TokenType::MinusMinus, '--'], [TokenType::Identifier, 'b']]],
            'integer' => ['42', [[TokenType::Number, '42']]],
            'decimal' => ['4.25', [[TokenType::Number, '4.25']]],
            'leading dot decimal' => ['.5', [[TokenType::Number, '.5']]],
            'second dot ends the number' => ['1.2.3', [[TokenType::Number, '1.2'], [TokenType::Number, '.3']]],
            'dot then non digit' => ['1.a', [[TokenType::Number, '1'], [TokenType::Dot, '.'], [TokenType::Identifier, 'a']]],
            'single quoted string' => ["'a b'", [[TokenType::String, 'a b']]],
            'double quoted string' => ['"x"', [[TokenType::String, 'x']]],
            'backtick string' => ['`x`', [[TokenType::String, 'x']]],
            'escapes' => ['"\\n\\t\\r\\\\\\"\\$\\q"', [[TokenType::String, "\n\t\r\\\"\$\\q"]]],
            'regex at start' => ['/a+b/', [[TokenType::Regex, 'a+b']]],
            'regex after paren' => ['(/x\\/y/)', [[TokenType::LeftParen, '('], [TokenType::Regex, 'x\\/y'], [TokenType::RightParen, ')']]],
            'regex after spaced comma' => ['[1,  /a/]', [
                [TokenType::LeftBracket, '['], [TokenType::Number, '1'], [TokenType::Comma, ','],
                [TokenType::Regex, 'a'], [TokenType::RightBracket, ']'],
            ]],
            'regex after leading spaces' => ['  /a/', [[TokenType::Regex, 'a']]],
            'unterminated regex' => ['(/ab', [[TokenType::LeftParen, '('], [TokenType::Regex, 'ab']]],
            'regex with trailing backslash' => ['(/a\\', [[TokenType::LeftParen, '('], [TokenType::Regex, 'a\\']]],
            'division after an operand' => ['a / b', [[TokenType::Identifier, 'a'], [TokenType::Divide, '/'], [TokenType::Identifier, 'b']]],
            'identifier' => ['_foo1', [[TokenType::Identifier, '_foo1']]],
            'keywords are case-insensitive' => ['TRUE False null', [
                [TokenType::True, 'TRUE'], [TokenType::False, 'False'], [TokenType::NullKeyword, 'null'],
            ]],
            'every keyword' => [
                'output input var fun type ns import from as is if else unless default do using and or not case matches match private async yield for throw enum',
                [
                    [TokenType::Output, 'output'], [TokenType::Input, 'input'], [TokenType::Var, 'var'],
                    [TokenType::Fun, 'fun'], [TokenType::Type, 'type'], [TokenType::Ns, 'ns'],
                    [TokenType::Import, 'import'], [TokenType::From, 'from'], [TokenType::As, 'as'],
                    [TokenType::Is, 'is'], [TokenType::If, 'if'], [TokenType::Else, 'else'],
                    [TokenType::Unless, 'unless'], [TokenType::Default, 'default'], [TokenType::Do, 'do'],
                    [TokenType::Using, 'using'], [TokenType::And, 'and'], [TokenType::Or, 'or'],
                    [TokenType::Not, 'not'], [TokenType::Case, 'case'], [TokenType::Matches, 'matches'],
                    [TokenType::Match, 'match'], [TokenType::Private, 'private'], [TokenType::Async, 'async'],
                    [TokenType::Yield, 'yield'], [TokenType::For, 'for'], [TokenType::Throw, 'throw'],
                    [TokenType::Enum, 'enum'],
                ],
            ],
            'date literal' => ['|2003-10-01|', [[TokenType::DateLiteral, '2003-10-01']]],
            'datetime literal' => ['|2003-10-01T10:00:00.5+02:00|', [[TokenType::DateLiteral, '2003-10-01T10:00:00.5+02:00']]],
            'time literal' => ['|23:57:59|', [[TokenType::DateLiteral, '23:57:59']]],
            'period literal' => ['|PT1.5S|', [[TokenType::DateLiteral, 'PT1.5S']]],
            'negative period literal' => ['|-p1d|', [[TokenType::DateLiteral, '-p1d']]],
            'pipe around a non date' => ['|abc|', [[TokenType::Pipe, '|'], [TokenType::Identifier, 'abc'], [TokenType::Pipe, '|']]],
            'empty pipes' => ['||', [[TokenType::Pipe, '|'], [TokenType::Pipe, '|']]],
            'pipe with a space inside' => ['|20 1|', [[TokenType::Pipe, '|'], [TokenType::Number, '20'], [TokenType::Number, '1'], [TokenType::Pipe, '|']]],
            'pipe broken by a newline' => ["|2003\n|", [[TokenType::Pipe, '|'], [TokenType::Number, '2003'], [TokenType::Pipe, '|']]],
            'unclosed pipe' => ['|2003', [[TokenType::Pipe, '|'], [TokenType::Number, '2003']]],
            'overlong pipe content' => ['|' . str_repeat('1', 70) . '|', [
                [TokenType::Pipe, '|'], [TokenType::Number, str_repeat('1', 70)], [TokenType::Pipe, '|'],
            ]],
            'two char operators' => ['++ >= <= == != -> => ~= .. .* ::', [
                [TokenType::PlusPlus, '++'], [TokenType::GreaterEqual, '>='], [TokenType::LessEqual, '<='],
                [TokenType::Equal, '=='], [TokenType::NotEqual, '!='], [TokenType::Arrow, '->'],
                [TokenType::FatArrow, '=>'], [TokenType::SimilarTo, '~='], [TokenType::DotDot, '..'],
                [TokenType::DotStar, '.*'], [TokenType::ColonColon, '::'],
            ]],
            'dollars' => ['$ $$ $$$', [
                [TokenType::Dollar, '$'], [TokenType::DollarDollar, '$$'], [TokenType::DollarDollarDollar, '$$$'],
            ]],
            'dollar at end' => ['$$', [[TokenType::DollarDollar, '$$']]],
            'single char operators' => ['+ - * > < ( ) [ ] { } , : . @ # & ? ~ ! =', [
                [TokenType::Plus, '+'], [TokenType::Minus, '-'], [TokenType::Multiply, '*'],
                [TokenType::Greater, '>'], [TokenType::Less, '<'], [TokenType::LeftParen, '('],
                [TokenType::RightParen, ')'], [TokenType::LeftBracket, '['], [TokenType::RightBracket, ']'],
                [TokenType::LeftBrace, '{'], [TokenType::RightBrace, '}'], [TokenType::Comma, ','],
                [TokenType::Colon, ':'], [TokenType::Dot, '.'], [TokenType::At, '@'], [TokenType::Hash, '#'],
                [TokenType::Ampersand, '&'], [TokenType::Question, '?'], [TokenType::Tilde, '~'],
                [TokenType::Not, '!'], [TokenType::Equal, '='],
            ]],
            'comments between tokens' => ["1 // one\n+ /* two */ 2", [
                [TokenType::Number, '1'], [TokenType::Plus, '+'], [TokenType::Number, '2'],
            ]],
            'unterminated block comment swallows the rest' => ['1 /* 2', [[TokenType::Number, '1']]],
            'star inside a block comment' => ['/* a * b */1', [[TokenType::Number, '1']]],
        ];
    }

    public function testTrailingBackslashLeavesTheStringUnterminated(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unterminated string starting at line 1, column 1');

        (new Lexer('"a\\'))->tokenize();
    }

    public function testRejectsUnknownCharacters(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage("Unexpected character ';' at line 2, column 3");

        (new Lexer("1\n  ;"))->tokenize();
    }

    public function testTracksLinesAndColumns(): void
    {
        $tokens = (new Lexer("%dw 2.0\n---\n  foo\n\"x\ny\" bar"))->tokenize();

        $positions = array_map(static fn (Token $t): array => [$t->value, $t->line, $t->column], $tokens);
        self::assertSame([
            ['2.0', 1, 1],
            ['---', 2, 1],
            ['foo', 3, 3],
            ["x\ny", 4, 1],
            ['bar', 5, 4],
            ['', 5, 7],
        ], $positions);
    }

    public function testVersionDirectiveReadsDigitsAndDotsOnly(): void
    {
        $tokens = (new Lexer("%dw \t2.0x"))->tokenize();

        self::assertSame(TokenType::DwVersion, $tokens[0]->type);
        self::assertSame('2.0', $tokens[0]->value);
        self::assertSame('x', $tokens[1]->value);
    }

    public function testTokensRenderForDiagnostics(): void
    {
        self::assertSame('IDENTIFIER(abc) at 3:7', (string) new Token(TokenType::Identifier, 'abc', 3, 7));
    }
}
