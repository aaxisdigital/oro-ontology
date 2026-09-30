<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\BinaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\DynamicSelector;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Identifier;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\InfixFunctionCall;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MatchCase;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\MatchExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\Node;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\NumberLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RangeExpression;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\RegexLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\StringLiteral;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Ast\UnaryOp;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Parser;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Environment;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Evaluator;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\ValueType;
use PHPUnit\Framework\TestCase;

/**
 * The evaluator at AST level: header state, and the node/operator shapes the parser never builds
 * (so the end-to-end tables cannot reach them).
 */
class EvaluatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        class_exists(Node::class);
        class_exists(Value::class);
    }

    public function testRecordsTheHeaderDirectives(): void
    {
        $evaluator = new Evaluator();
        $script = Parser::parse(
            "output application/csv header=true\n"
            . "input payload application/json streaming=true\n"
            . "input vars application/java\n"
            . "ns ns0 urn:x\n"
            . "---\n1"
        );

        self::assertSame(1, $evaluator->evaluate($script)->data);
        self::assertSame('application/csv', $evaluator->getOutputMimeType());
        self::assertSame(['header' => 'true'], $evaluator->getOutputOptions());
        self::assertSame(['payload' => 'application/json', 'vars' => 'application/java'], $evaluator->getInputTypes());
        self::assertSame(['payload' => ['streaming' => 'true'], 'vars' => []], $evaluator->getInputOptions());
    }

    public function testDefaultsWithoutAHeader(): void
    {
        $evaluator = new Evaluator();
        $evaluator->evaluate(Parser::parse('1'));

        self::assertNull($evaluator->getOutputMimeType());
        self::assertSame([], $evaluator->getOutputOptions());
        self::assertSame([], $evaluator->getInputTypes());
        self::assertSame([], $evaluator->getInputOptions());
    }

    public function testAGivenEnvironmentIsUsedAsIs(): void
    {
        $env = new Environment();
        $env->define('answer', Value::number(42));

        self::assertSame(42, (new Evaluator($env))->evaluate(Parser::parse('answer'))->data);
    }

    public function testAMatchComparesItsValueCaseByCase(): void
    {
        $evaluator = new Evaluator();
        $match = static fn (int $value): MatchExpression => new MatchExpression(new NumberLiteral($value), [
            new MatchCase(new NumberLiteral(1), new StringLiteral('one')),
            new MatchCase(new NumberLiteral(2), new StringLiteral('two')),
            new MatchCase(null, new StringLiteral('many'), true),
        ]);

        self::assertSame('one', $evaluator->eval($match(1))->data);
        self::assertSame('two', $evaluator->eval($match(2))->data);
        self::assertSame('many', $evaluator->eval($match(3))->data);
    }

    public function testRangesCountInBothDirections(): void
    {
        $evaluator = new Evaluator();
        $range = static fn (int $a, int $b): array => array_map(
            static fn (Value $v): mixed => $v->data,
            $evaluator->eval(new RangeExpression(new NumberLiteral($a), new NumberLiteral($b)))->data
        );

        self::assertSame([1, 2, 3], $range(1, 3));
        self::assertSame([3, 2, 1], $range(3, 1));
    }

    public function testMatchesAnchorsTheRegex(): void
    {
        $evaluator = new Evaluator();
        $matches = static fn (string $text, Node $right): bool => $evaluator->eval(
            new InfixFunctionCall(new StringLiteral($text), 'matches', $right)
        )->data;

        self::assertTrue($matches('abc', new RegexLiteral('a.c')));
        self::assertFalse($matches('xabc', new RegexLiteral('a.c')));
        self::assertFalse($matches('abc', new StringLiteral('abc')));
    }

    public function testNamespaceSelectorsEvaluateToTheirQualifiedName(): void
    {
        $value = (new Evaluator())->evaluate(Parser::parse('{ns0#name: 1}'));

        self::assertSame('ns0#name', $value->data[0][0]->data);
    }

    /**
     * @dataProvider unsupportedNodes
     */
    public function testRejectsUnsupportedNodes(Node $node, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new Evaluator())->eval($node);
    }

    public static function unsupportedNodes(): array
    {
        class_exists(Node::class);

        return [
            'unknown binary operator' => [new BinaryOp(new NumberLiteral(1), '**', new NumberLiteral(2)), 'Unknown operator: **'],
            'unknown unary operator' => [new UnaryOp('~', new NumberLiteral(1)), 'Unknown unary operator: ~'],
            'unknown infix function' => [new InfixFunctionCall(new NumberLiteral(1), 'nope', new NumberLiteral(2)), 'Unknown infix function: nope'],
            'undefined identifier' => [new Identifier('ghost', 4), "Undefined variable: 'ghost' at line 4"],
        ];
    }

    public function testRejectsNodeTypesItCannotEvaluate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot evaluate node of type ' . DynamicSelector::class);

        (new Evaluator())->eval(new DynamicSelector(new NumberLiteral(1), new NumberLiteral(2)));
    }

    public function testAnInfixCallPrefersAnEnvironmentFunctionOverTheBuiltinFallback(): void
    {
        $env = new Environment();
        $env->define('glue', Value::func(static fn (array $args): Value => Value::string($args[0]->data . '+' . $args[1]->data)));

        $value = (new Evaluator($env))->eval(new InfixFunctionCall(new StringLiteral('a'), 'glue', new StringLiteral('b')));

        self::assertSame(ValueType::String, $value->type);
        self::assertSame('a+b', $value->data);
    }

    public function testDynamicScopesDoNotLeakOutOfIterations(): void
    {
        $env = new Environment();
        $env->define('x', Value::number(1));

        $result = (new Evaluator($env))->evaluate(Parser::parse('[[10, 20] map (x) -> x, x]'));

        self::assertSame([10, 20], array_map(static fn (Value $v): mixed => $v->data, $result->data[0]->data));
        self::assertSame(1, $result->data[1]->data);
    }
}
