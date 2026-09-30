<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Language;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ArrayLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BinaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BooleanLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ConditionalExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DateTimeLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DescendantSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DoExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DollarRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\FunDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Identifier;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ImportDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\IndexAccess;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InfixFunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\LambdaExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MatchExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MemberAccess;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ModuleRef;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MultiValueSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NamespaceSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Node;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NsDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NullLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NumberLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\ObjectLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\OutputDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RegexLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\StringLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeCoercion;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\TypeReference;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\UnaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\VarDirective;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\ParserException;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Parser;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Token;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\TokenType;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Every AST class lives in Node.php — autoloading Node loads them all.
        class_exists(Node::class);
    }

    private static function body(string $source): Node
    {
        return Parser::parse($source)->body;
    }

    public function testParsesTheHeader(): void
    {
        $script = Parser::parse(
            "%dw 2.0\n"
            . "output application/x-www-form-urlencoded indent=false, encoding=\"UTF-8\", streaming\n"
            . "input payload application/json\n"
            . "var x = 1\n"
            . "fun f(a: Number, b = 2) = a + b\n"
            . "type T = String {format: \"yyyy\", class: 'x'}\n"
            . "ns ns0 urn:example.com/a-b.c::d\n"
            . "import * from dw::core::Strings\n"
            . "import a, b from dw::core::Arrays\n"
            . "private var hidden = 2\n"
            . "---\n"
            . 'x'
        );

        self::assertSame('2.0', $script->version);
        [$output, $input, $var, $fun, $type, $ns, $importAll, $import, $private] = $script->directives;

        self::assertInstanceOf(OutputDirective::class, $output);
        self::assertSame('application/x-www-form-urlencoded', $output->mimeType);
        self::assertSame(['indent' => 'false', 'encoding' => 'UTF-8', 'streaming' => true], $output->options);

        self::assertInstanceOf(InputDirective::class, $input);
        self::assertSame(['payload', 'application/json', []], [$input->name, $input->mimeType, $input->options]);

        self::assertInstanceOf(VarDirective::class, $var);
        self::assertSame('x', $var->name);
        self::assertInstanceOf(NumberLiteral::class, $var->value);

        self::assertInstanceOf(FunDirective::class, $fun);
        self::assertSame('f', $fun->name);
        self::assertSame(['a', 'Number', null], [$fun->params[0]->name, $fun->params[0]->type, $fun->params[0]->defaultValue]);
        self::assertSame('b', $fun->params[1]->name);
        self::assertInstanceOf(NumberLiteral::class, $fun->params[1]->defaultValue);
        self::assertInstanceOf(BinaryOp::class, $fun->body);

        self::assertInstanceOf(TypeDirective::class, $type);
        self::assertInstanceOf(TypeReference::class, $type->definition);
        self::assertSame('String', $type->definition->name);
        self::assertSame(['format' => 'yyyy', 'class' => 'x'], $type->definition->metadata);

        self::assertInstanceOf(NsDirective::class, $ns);
        self::assertSame(['ns0', 'urn:example.com/a-b.c::d'], [$ns->prefix, $ns->uri]);

        self::assertInstanceOf(ImportDirective::class, $importAll);
        self::assertTrue($importAll->importAll);
        self::assertSame('dw::core::Strings', $importAll->module);

        self::assertInstanceOf(ImportDirective::class, $import);
        self::assertFalse($import->importAll);
        self::assertSame(['a', 'b'], $import->names);

        // "private" is only a visibility modifier: the directive it wraps is returned as-is.
        self::assertInstanceOf(VarDirective::class, $private);
        self::assertSame('hidden', $private->name);

        self::assertInstanceOf(Identifier::class, $script->body);
    }

    public function testHeaderAndSeparatorAreOptional(): void
    {
        $script = Parser::parse('1');

        self::assertNull($script->version);
        self::assertSame([], $script->directives);
        self::assertInstanceOf(NumberLiteral::class, $script->body);
        self::assertSame(1, $script->body->value);
    }

    public function testFormatOptionsStopAtTheNextDirective(): void
    {
        $script = Parser::parse("output application/json\nvar a = 1\n---\na");

        self::assertSame([], $script->directives[0]->options);
        self::assertInstanceOf(VarDirective::class, $script->directives[1]);
    }

    public function testFormatOptionsStopAtANonIdentifier(): void
    {
        $script = Parser::parse('output json 5');

        self::assertSame('json', $script->directives[0]->mimeType);
        self::assertSame([], $script->directives[0]->options);
        self::assertInstanceOf(NumberLiteral::class, $script->body);
    }

    public function testFormatOptionValueMayBeAnyToken(): void
    {
        $script = Parser::parse("output application/json indent=2\n---\n1");

        self::assertSame(['indent' => '2'], $script->directives[0]->options);
    }

    /**
     * @dataProvider primaries
     */
    public function testParsesPrimaries(string $source, string $class, callable $check): void
    {
        $node = self::body($source);

        self::assertInstanceOf($class, $node);
        self::assertTrue($check($node));
    }

    public static function primaries(): array
    {
        return [
            'integer' => ['7', NumberLiteral::class, static fn (NumberLiteral $n): bool => $n->value === 7],
            'float' => ['7.5', NumberLiteral::class, static fn (NumberLiteral $n): bool => $n->value === 7.5],
            'string' => ["'s'", StringLiteral::class, static fn (StringLiteral $n): bool => $n->value === 's'],
            'true' => ['true', BooleanLiteral::class, static fn (BooleanLiteral $n): bool => $n->value === true],
            'false' => ['false', BooleanLiteral::class, static fn (BooleanLiteral $n): bool => $n->value === false],
            'null' => ['null', NullLiteral::class, static fn (): bool => true],
            'regex' => ['/a+/', RegexLiteral::class, static fn (RegexLiteral $n): bool => $n->pattern === 'a+'],
            'date' => ['|2020-01-01|', DateTimeLiteral::class, static fn (DateTimeLiteral $n): bool => $n->text === '2020-01-01'],
            'dollar' => ['$', DollarRef::class, static fn (DollarRef $n): bool => $n->index === 1],
            'double dollar' => ['$$', DollarRef::class, static fn (DollarRef $n): bool => $n->index === 2],
            'triple dollar' => ['$$$', DollarRef::class, static fn (DollarRef $n): bool => $n->index === 3],
            'unary minus' => ['-x', UnaryOp::class, static fn (UnaryOp $n): bool => $n->operator === '-' && $n->operand instanceof Identifier],
            'not binds tighter than and' => ['not a and b', BinaryOp::class, static fn (BinaryOp $n): bool => $n->operator === 'and'
                && $n->left instanceof UnaryOp && $n->left->operator === 'not'],
            'bang' => ['!a', UnaryOp::class, static fn (UnaryOp $n): bool => $n->operator === 'not'],
            'parenthesized' => ['(1)', NumberLiteral::class, static fn (NumberLiteral $n): bool => $n->value === 1],
            'lambda' => ['(a, b) -> a', LambdaExpression::class, static fn (LambdaExpression $n): bool => \count($n->params) === 2
                && $n->body instanceof Identifier],
            'nested parens are not a lambda' => ['((1))', NumberLiteral::class, static fn (): bool => true],
            'dynamic single-entry object' => ['(k): 1', ObjectLiteral::class, static fn (ObjectLiteral $n): bool => $n->entries[0]->dynamicKey
                && $n->entries[0]->key instanceof Identifier],
            'array' => ['[1, 2,]', ArrayLiteral::class, static fn (ArrayLiteral $n): bool => \count($n->elements) === 2],
            'object entries' => ['{a: 1, "b": 2, type: 3, ns0#c: 4, (d): 5, (e)}', ObjectLiteral::class, static function (ObjectLiteral $n): bool {
                [$a, $b, $type, $ns, $dynamic, $spread] = $n->entries;

                return $a->key instanceof Identifier && $b->key instanceof StringLiteral
                    && $type->key instanceof Identifier && $type->key->name === 'type'
                    && $ns->key instanceof NamespaceSelector && $ns->key->prefix === 'ns0' && $ns->key->localName === 'c'
                    && $dynamic->dynamicKey && !$dynamic->conditional
                    && $spread->conditional && $spread->value instanceof NullLiteral;
            }],
            'if' => ['if (a) 1 else 2', ConditionalExpression::class, static fn (ConditionalExpression $n): bool => $n->condition instanceof Identifier],
            'match block' => ["match { case 1 -> 'a', else -> 'b' }", MatchExpression::class, static fn (MatchExpression $n): bool => $n->value instanceof NullLiteral
                && \count($n->cases) === 2 && !$n->cases[0]->isDefault && $n->cases[1]->isDefault && $n->cases[1]->pattern === null],
            'do block' => ["do { var a = 1\n---\na }", DoExpression::class, static fn (DoExpression $n): bool => \count($n->directives) === 1],
            'identifier' => ['foo', Identifier::class, static fn (Identifier $n): bool => $n->name === 'foo'],
            'call' => ['f(1, 2)', FunctionCall::class, static fn (FunctionCall $n): bool => $n->callee instanceof Identifier && \count($n->args) === 2],
            'module ref' => ['dw::core::Strings::camelize', ModuleRef::class, static fn (ModuleRef $n): bool => $n->module === 'dw::core::Strings'
                && $n->member === 'camelize'],
            'module call' => ['dw::Runtime::fail("x")', FunctionCall::class, static fn (FunctionCall $n): bool => $n->callee instanceof ModuleRef
                && $n->callee->module === 'dw::Runtime' && $n->callee->member === 'fail'],
        ];
    }

    /**
     * @dataProvider infixForms
     */
    public function testParsesInfixForms(string $source, callable $check): void
    {
        self::assertTrue($check(self::body($source)));
    }

    public static function infixForms(): array
    {
        return [
            'arithmetic precedence' => ['1 + 2 * 3', static fn (BinaryOp $n): bool => $n->operator === '+' && $n->right instanceof BinaryOp],
            'left associative' => ['1 - 2 - 3', static fn (BinaryOp $n): bool => $n->left instanceof BinaryOp && $n->right instanceof NumberLiteral],
            'comparison under logic' => ['a > 1 or b', static fn (BinaryOp $n): bool => $n->operator === 'or' && $n->left instanceof BinaryOp],
            'infix function' => ['a map b', static fn (InfixFunctionCall $n): bool => $n->name === 'map'],
            'default keyword' => ['a default 1', static fn (InfixFunctionCall $n): bool => $n->name === 'default'],
            'match keyword' => ['a match {}', static fn (InfixFunctionCall $n): bool => $n->name === 'match' && $n->right instanceof ObjectLiteral],
            'member' => ['a.b', static fn (MemberAccess $n): bool => $n->property === 'b'],
            'keyword member' => ['a.type', static fn (MemberAccess $n): bool => $n->property === 'type'],
            'quoted member' => ["a.'b c'", static fn (MemberAccess $n): bool => $n->property === 'b c'],
            'dangling dot is dropped' => ['a.', static fn (Identifier $n): bool => $n->name === 'a'],
            'descendant' => ['a..b', static fn (DescendantSelector $n): bool => $n->property === 'b'],
            'keyword descendant' => ['a..default', static fn (DescendantSelector $n): bool => $n->property === 'default'],
            'quoted descendant' => ['a.."b"', static fn (DescendantSelector $n): bool => $n->property === 'b'],
            'dangling descendant is dropped' => ['a..', static fn (Identifier $n): bool => $n->name === 'a'],
            'multi value' => ['a.*b', static fn (MultiValueSelector $n): bool => $n->property === 'b'],
            'quoted multi value' => ["a.*'b'", static fn (MultiValueSelector $n): bool => $n->property === 'b'],
            'index' => ['a[0]', static fn (IndexAccess $n): bool => $n->index instanceof NumberLiteral],
            'chained selectors' => ['a.b[0].c', static fn (MemberAccess $n): bool => $n->object instanceof IndexAccess],
            'coercion' => ['a as Number', static fn (TypeCoercion $n): bool => $n->targetType->name === 'Number' && $n->targetType->metadata === []],
            'coercion with format' => ["a as Date {format: 'yyyy', x: 1}", static fn (TypeCoercion $n): bool => $n->targetType->metadata === ['format' => 'yyyy', 'x' => '1']],
            'is check' => ['a is String', static fn (BinaryOp $n): bool => $n->operator === 'is' && $n->right instanceof Identifier && $n->right->name === 'String'],
            'implicit object' => ['a: 1, b: 2', static fn (ObjectLiteral $n): bool => \count($n->entries) === 2],
        ];
    }

    /**
     * @dataProvider invalidSources
     */
    public function testRejectsInvalidSources(string $source, string $message): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage($message);

        Parser::parse($source);
    }

    public static function invalidSources(): array
    {
        return [
            'unexpected primary' => [')', "Unexpected token RIGHT_PAREN ')' at line 1, column 1"],
            'unexpected directive' => ["private 1\n---\n1", "Unexpected token NUMBER '1' at line 1, column 9"],
            'bad object key' => ['{[1]: 2}', 'Expected object key at line 1, column 2'],
            'missing colon' => ['{a 1}', "Expected COLON but got NUMBER '1' at line 1, column 4"],
            'multi value needs a name' => ['a.*1', "Expected IDENTIFIER but got NUMBER '1'"],
            'unclosed index' => ['a[0', "Expected RIGHT_BRACKET but got EOF ''"],
            'coercion needs a type' => ['a as 1', "Expected IDENTIFIER but got NUMBER '1'"],
            'is needs a type' => ['a is 1', "Expected IDENTIFIER but got NUMBER '1'"],
            'module member must be an identifier' => ['dw::1', "Expected IDENTIFIER but got NUMBER '1'"],
            'unclosed paren' => ['(a', "Expected RIGHT_PAREN but got EOF ''"],
            'unclosed do block' => ['do { 1', "Unexpected token NUMBER '1'"],
            'if needs else' => ['if (a) 1', "Expected ELSE but got EOF ''"],
        ];
    }

    public function testTokenHelpersTolerateAnExhaustedStream(): void
    {
        // A token list without its EOF sentinel: the parser synthesizes one.
        $parser = new Parser([new Token(TokenType::Identifier, 'a', 1, 1)]);

        $script = $parser->parseScript();

        self::assertInstanceOf(Identifier::class, $script->body);
    }

    public function testParseExpressionIsUsableOnItsOwn(): void
    {
        $parser = new Parser([
            new Token(TokenType::Number, '1', 1, 1),
            new Token(TokenType::Plus, '+', 1, 3),
            new Token(TokenType::Number, '2', 1, 5),
        ]);

        $node = $parser->parseExpression();

        self::assertInstanceOf(BinaryOp::class, $node);
        self::assertSame('+', $node->operator);
    }
}
