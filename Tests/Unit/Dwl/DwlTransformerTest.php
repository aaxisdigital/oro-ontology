<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\LexerException;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\ParserException;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end behaviour of the DataWeave engine through its facade: script (+ bindings) in, plain
 * PHP out. The tables pin the language as the flows use it — operators, selectors, the infix
 * builtins, the standard library, directives and temporal values.
 */
class DwlTransformerTest extends TestCase
{
    private DwlTransformer $dwl;

    protected function setUp(): void
    {
        $this->dwl = new DwlTransformer();
    }

    /**
     * @dataProvider arithmeticAndOperators
     * @dataProvider selectorsAndStructures
     * @dataProvider infixBuiltins
     * @dataProvider standardLibrary
     * @dataProvider directivesAndModules
     * @dataProvider temporalValues
     */
    public function testEvaluatesScripts(string $script, mixed $expected): void
    {
        self::assertSame($expected, $this->dwl->transform($script, []));
    }

    public static function arithmeticAndOperators(): array
    {
        return [
            'addition' => ['1 + 2', 3],
            'float addition' => ['.5 + 1', 1.5],
            'precedence' => ['1 + 2 * 3', 7],
            'parentheses' => ['(1 + 2) * 3', 9],
            'division yields float' => ['7 / 2', 3.5],
            'modulo' => ['5 % 3', 2],
            'unary minus' => ['-5', -5],
            'string plus number concatenates' => ["'a' + 1", 'a1'],
            'number plus string concatenates' => ["1 + 'a'", '1a'],
            'numeric string subtraction coerces' => ["'5' - 2", 3],
            'numeric float string coerces' => ["'3.5' * 2", 7.0],
            'boolean coerces to one' => ['true * 2', 2],
            'null coerces to zero' => ['null - 1', -1],
            'string concat' => ['"a" ++ "b"', 'ab'],
            'array concat' => ['[1,2] ++ [3]', [1, 2, 3]],
            'object concat' => ['{a:1} ++ {b:2}', ['a' => 1, 'b' => 2]],
            'array append' => ['[1] ++ 2', [1, 2]],
            'array prepend' => ['1 ++ [2]', [1, 2]],
            'scalar concat stringifies' => ['1 ++ 2', '12'],
            'boolean and null stringify' => ['true ++ null', 'truenull'],
            'float stringifies compactly' => ["1.5 ++ ''", '1.5'],
            'small float stringifies' => ["0.1 ++ ''", '0.1'],
            'object stringifies as json' => ["{a: [1, {b: true}]} ++ ''", '{"a":"[\"1\",\"{\\\\\"b\\\\\":\\\\\"true\\\\\"}\"]"}'],
            'regex stringifies as its pattern' => ["(/ab/) ++ ''", 'ab'],
            'period stringifies as iso' => ["|P1D| ++ ''", 'P1D'],
            'date stringifies as iso' => ["|2020-01-01| ++ ''", '2020-01-01'],
            'object minus key' => ["{a:1, b:2} - 'a'", ['b' => 2]],
            'empty object minus key' => ["{} - 'a'", []],
            'array minus item' => ['[1,2,1] - 1', [2]],
            'array minus array removes all' => ['[1,2,3] -- [2]', [1, 3]],
            'object minus key list' => ["{a:1,b:2} -- ['a']", ['b' => 2]],
            'remove-all on scalars is identity' => ['5 -- 1', 5],
            'equality' => ['1 == 1', true],
            'equality is type strict' => ["1 == '1'", false],
            'int and float differ' => ['0 == 0.0', false],
            'inequality' => ['1 != 2', true],
            'array equality' => ['[1,2] == [1,2]', true],
            'array length mismatch' => ['[1] == [1,2]', false],
            'nested array mismatch' => ['[1,[2]] == [1,[3]]', false],
            'object equality' => ['{a:1} == {a:1}', true],
            'object key mismatch' => ['{a:1} == {b:1}', false],
            'object size mismatch' => ['{a:1} == {a:1,b:2}', false],
            'null equality' => ['null == null', true],
            'similar string to number' => ["'1' ~= 1", true],
            'similar decimal string to number' => ["'1.0' ~= 1", true],
            'similar non-numeric string to number' => ["'x' ~= 1", false],
            'similar strings' => ["'a' ~= 'a'", true],
            'similar string to boolean' => ["'true' ~= true", true],
            'similar identical arrays' => ['[1] ~= [1]', true],
            'similar never matches structures' => ['[1] ~= 1', false],
            'similar null to zero' => ['null ~= 0', false],
            'less than' => ['1 < 2', true],
            'less or equal' => ['2 <= 2', true],
            'greater or equal' => ['1 >= 2', false],
            'strings compare lexically' => ["'a' > 'b'", false],
            'booleans compare' => ['true > false', true],
            'dates compare' => ['|2020-01-02| > |2020-01-01|', true],
            'and' => ['true and false', false],
            'or' => ['true or false', true],
            'not keyword' => ['not true', false],
            'bang' => ['!false', true],
            'if true' => ['if (true) 1 else 2', 1],
            'zero is falsy' => ['if (0) 1 else 2', 2],
            'float zero is falsy' => ['if (0.0) 1 else 2', 2],
            'empty string is falsy' => ["if ('') 1 else 2", 2],
            'empty array is falsy' => ['if ([]) 1 else 2', 2],
            'empty object is falsy' => ['if ({}) 1 else 2', 2],
            'regex is truthy' => ['if (/a/) 1 else 2', 1],
            'text is truthy' => ["if ('x') 1 else 2", 1],
            'default on null' => ['null default 5', 5],
            'default keeps a value' => ['3 default 5', 3],
            'line and block comments' => ["// comment\n1 /* block */ + 2", 3],
            'string escapes' => ["'a\\nb\\tc\\rd\\\\e\\'f\\\$g\\qh'", "a\nb\tc\rd\\e'f\$g\\qh"],
            'escaped double quote' => ['"x\\"y"', 'x"y'],
            'backtick string' => ['`back`', 'back'],
        ];
    }

    public static function selectorsAndStructures(): array
    {
        return [
            'member access' => ['{a: 1}.a', 1],
            'missing member is null' => ['{a: 1}.b', null],
            'member of a scalar is null' => ['(1).a', null],
            'member over an array of objects' => ['[{a:1},{a:2},3].a', [1, 2]],
            'quoted member' => ["{'a b': 1}.'a b'", 1],
            'double-quoted member' => ['{"a": 1}."a"', 1],
            'keyword member' => ['{type: 1}.type', 1],
            'multi-value selector' => ['{a:1, a:2, b:3}.*a', [1, 2]],
            'quoted multi-value selector' => ["{a: 1}.*'a'", [1]],
            'multi-value on non-object' => ['[1].*a', []],
            'descendant selector' => ['{a:{b:{a:3}}}..a', [['b' => ['a' => 3]], 3]],
            'descendant into arrays' => ['[{x:1},{y:{x:2}}]..x', [1, 2]],
            'descendant keyword key' => ['{a:{default:2}}..default', [2]],
            'negative array index' => ['[1,2,3][-1]', 3],
            'array index out of range' => ['[1][5]', null],
            'string index' => ["'abc'[1]", 'b'],
            'negative string index' => ["'abc'[-1]", 'c'],
            'object by key' => ["{a:1}['a']", 1],
            'object by numeric key' => ['{"1": 9}[1]', 9],
            'index of a scalar is null' => ['true[0]', null],
            'implicit object body' => ['a: 1, b: 2', ['a' => 1, 'b' => 2]],
            'implicit object over lines' => ["a: 1\nb: 2", ['a' => 1, 'b' => 2]],
            'dynamic key entry' => ['("k"): 1', ['k' => 1]],
            'dynamic key in object' => ['{("k" ++ "2"): 1}', ['k2' => 1]],
            'object spread' => ['{({a: 1})}', ['a' => 1]],
            'array of objects spread' => ['{([{a:1},{b:2}, 3])}', ['a' => 1, 'b' => 2]],
            'scalar spread adds nothing' => ['{(1)}', []],
            'namespaced key' => ['{ns0#name: 1}', ['ns0#name' => 1]],
            'string key' => ['{"a b": 1}', ['a b' => 1]],
            'keyword keys' => ['{type: 1, default: 2, null: 3}', ['type' => 1, 'default' => 2, 'null' => 3]],
            'range with to' => ['1 to 3', [1, 2, 3]],
            'descending range' => ['3 to 1', [3, 2, 1]],
            'do block' => ['do { var x = 1 --- x + 1 }', 2],
            'do block with function' => ['do { fun f(a) = a * 2 --- f(3) }', 6],
            'standalone match picks default' => ["match { 1 -> 'one', else -> 'other' }", 'other'],
            'standalone match compares against null' => ["match { case null -> 'nil' }", 'nil'],
            'standalone match without hit' => ["match { 1 -> 'one' }", null],
            'infix match with empty object' => ['{a:1} match {}', []],
            'infix match with regex' => ["'abc' match (/(b)(c)/)", ['bc', 'b', 'c']],
            'infix match with other value' => ["'abc' match ('b')", null],
        ];
    }

    public static function infixBuiltins(): array
    {
        return [
            'map with dollar' => ['[1,2,3] map ($ * 2)', [2, 4, 6]],
            'map with index' => ['[1,2] map $$', [0, 1]],
            'map with lambda' => ['[1,2,3] map (x, i) -> x + i', [1, 3, 5]],
            'map lambda extra params are null' => ['[1] map (a, b, c, d) -> d', [null]],
            'flatMap flattens arrays' => ['[[1,2],[3]] flatMap $', [1, 2, 3]],
            'flatMap keeps scalars' => ['[1,2] flatMap $', [1, 2]],
            'filter' => ['[1,2,3] filter ($ > 1)', [2, 3]],
            'reduce without seed' => ['[1,2,3] reduce ($$ + $)', 6],
            'reduce with seed' => ['[1,2,3] reduce ((item, acc = 10) -> acc + item)', 16],
            'reduce lambda without default' => ['[1,2] reduce ((a, b) -> a + b)', 3],
            'reduce empty with seed' => ['[] reduce ((item, acc = 5) -> acc + item)', 5],
            'reduce empty without seed' => ['[] reduce ($$ + $)', null],
            'groupBy' => ['[1,2,3] groupBy ($ mod 2)', [1 => [1, 3], 0 => [2]]],
            'groupBy string keys' => ["['ab','ac','b'] groupBy ($[0])", ['a' => ['ab', 'ac'], 'b' => ['b']]],
            'orderBy numbers' => ['[3,1,2] orderBy $', [1, 2, 3]],
            'orderBy strings' => ["['b','a'] orderBy $", ['a', 'b']],
            'distinctBy' => ['[1,2,1] distinctBy $', [1, 2]],
            'distinctBy string key' => ["['a','a','b'] distinctBy $", ['a', 'b']],
            'mapObject' => ['{a:1} mapObject {($$): $ + 1}', ['a' => 2]],
            'mapObject drops non-objects' => ['{a:1} mapObject $', []],
            'mapObject index' => ['{a:1} mapObject {x: $$$}', ['x' => 0]],
            'pluck keys' => ['{a:1,b:2} pluck $$', ['a', 'b']],
            'pluck lambda index' => ['{a:1} pluck (v, k, i) -> i', [0]],
            'joinBy' => ["['a','b'] joinBy ','", 'a,b'],
            'joinBy formats floats' => ["[1.0, 2.5] joinBy ','", '1,2.5'],
            'splitBy' => ["'a,b' splitBy ','", ['a', 'b']],
            'contains in string' => ["'abc' contains 'b'", true],
            'contains in array' => ['[1,2] contains 2', true],
            'contains on scalar' => ['5 contains 1', false],
            'startsWith' => ["'abc' startsWith 'a'", true],
            'endsWith' => ["'abc' endsWith 'c'", true],
            'replace text' => ["'abc' replace 'b'", 'ac'],
            'replace regex' => ["'abc' replace (/b+/)", 'ac'],
            'maxBy' => ['[1,3,2] maxBy $', 3],
            'minBy' => ['[1,3,2] minBy $', 1],
            'maxBy empty' => ['[] maxBy $', null],
            'minBy empty' => ['[] minBy $', null],
            'every true' => ['[1,2] every ($ > 0)', true],
            'every false' => ['[1,2] every ($ > 1)', false],
            'some true' => ['[1,2] some ($ > 1)', true],
            'some false' => ['[1,2] some ($ > 5)', false],
            'mod infix via the environment' => ['7 mod 2', 1],
            'float mod' => ['7.5 mod 2', 1.5],
            'user function as infix' => ["fun take(a, n) = [a, n]\n---\n3 take 2", [3, 2]],
        ];
    }

    public static function standardLibrary(): array
    {
        return [
            'sizeOf array' => ['sizeOf([1,2])', 2],
            'sizeOf string' => ["sizeOf('abc')", 3],
            'sizeOf object' => ['sizeOf({a:1})', 1],
            'sizeOf scalar' => ['sizeOf(1)', 0],
            'sizeof alias' => ['sizeof([1])', 1],
            'typeOf' => ['typeOf(1)', 'Number'],
            'typeof alias' => ["typeof('a')", 'String'],
            'typeOf now' => ['typeOf(now())', 'DateTime'],
            'isEmpty array' => ['isEmpty([])', true],
            'isEmpty object' => ['isEmpty({})', true],
            'isEmpty string' => ["isEmpty('')", true],
            'isEmpty null' => ['isEmpty(null)', true],
            'isEmpty number' => ['isEmpty(1)', false],
            'trim' => ["trim(' a ')", 'a'],
            'upper' => ["upper('a')", 'A'],
            'lower' => ["lower('A')", 'a'],
            'capitalize' => ["capitalize('hello WORLD')", 'Hello World'],
            'abs' => ['abs(-1)', 1],
            'ceil' => ['ceil(1.2)', 2],
            'floor' => ['floor(1.8)', 1],
            'round' => ['round(1.5)', 2],
            'sqrt' => ['sqrt(4)', 2.0],
            'pow' => ['pow(2,3)', 8.0],
            'mod' => ['mod(7,2)', 1],
            'min of array' => ['min([3,1,2])', 1],
            'max of array' => ['max([3,1,2])', 3],
            'max of strings' => ["max(['a','b'])", 'b'],
            'min of empty' => ['min([])', null],
            'legacy two-number min' => ['min(3, 1)', 1.0],
            'legacy two-number max' => ['max(3, 1)', 3.0],
            'sum' => ['sum([1,2])', 3],
            'sum of scalar' => ['sum(1)', 0],
            'avg' => ['avg([1,2])', 1.5],
            'avg of empty' => ['avg([])', 0],
            'length' => ["length('ab')", 2],
            'substring with length' => ["substring('abcdef', 1, 3)", 'bcd'],
            'substring to end' => ["substring('abcdef', 2)", 'cdef'],
            'replace function text' => ["replace('abc', 'b', 'x')", 'axc'],
            'replace function regex' => ["replace('abc', /b/, 'x')", 'axc'],
            'flatten' => ['flatten([[1],[2,3],4])', [1, 2, 3, 4]],
            'flatten scalar' => ['flatten(1)', 1],
            'reverse array' => ['reverse([1,2])', [2, 1]],
            'reverse string' => ["reverse('ab')", 'ba'],
            'reverse scalar' => ['reverse(1)', 1],
            'first' => ['first([1,2])', 1],
            'first of empty' => ['first([])', null],
            'last' => ['last([1,2])', 2],
            'last of empty' => ['last([])', null],
            'indexOf array' => ['indexOf([1,2], 2)', 1],
            'indexOf array missing' => ['indexOf([1,2], 3)', -1],
            'indexOf string' => ["indexOf('abc', 'c')", 2],
            'indexOf string missing' => ["indexOf('abc', 'z')", -1],
            'indexOf scalar' => ['indexOf(1, 1)', -1],
            'indexOf counts characters, not bytes' => ["indexOf('ação_x', '_')", 4],
            // dw::Core lastIndexOf (DataWeave 2.4): arrays and strings, prefix or infix.
            'lastIndexOf string' => ["lastIndexOf('a_b_c', '_')", 3],
            'lastIndexOf infix' => ["'a_b_c' lastIndexOf '_'", 3],
            'lastIndexOf string missing' => ["'abc' lastIndexOf '_'", -1],
            'lastIndexOf counts characters' => ["'ção_ção' lastIndexOf 'ç'", 4],
            'lastIndexOf array' => ['[1, 2, 1, 3] lastIndexOf 1', 2],
            'lastIndexOf array missing' => ['lastIndexOf([1, 2], 3)', -1],
            'lastIndexOf scalar' => ['lastIndexOf(1, 1)', -1],
            'lastIndexOf from the Arrays module' => ['dw::core::Arrays::lastIndexOf([1, 2, 1], 1)', 2],
            'sizeOf counts characters' => ["sizeOf('ação')", 4],
            'substring counts characters' => ["substring('ação!', 1, 3)", 'ção'],
            'distinctBy function without criteria' => ['distinctBy([1,1,2])', [1, 2]],
            'distinctBy function with criteria' => ['distinctBy([{a:1},{a:1}], (x) -> x.a)', [['a' => 1]]],
            'distinctBy function on scalar' => ['distinctBy(1)', 1],
            'zip' => ['zip([1,2],[3])', [[1, 3]]],
            'unzip' => ['unzip([[1,2],[3,4],[5]])', [[1, 3], [2, 4]]],
            'keys' => ['keys({a:1})', ['a']],
            'keys of scalar' => ['keys(1)', []],
            'namesOf' => ['namesOf({a:1})', ['a']],
            'values' => ['values({a:1})', [1]],
            'values of scalar' => ['values(1)', []],
            'entries' => ['entries({a:1})', [['key' => 'a', 'value' => 1]]],
            'entries of scalar' => ['entries(1)', []],
            'isBlank spaces' => ["isBlank('  ')", true],
            'isBlank null' => ['isBlank(null)', true],
            'isBlank text' => ["isBlank('a')", false],
            'isInteger' => ['isInteger(1)', true],
            'isDecimal' => ['isDecimal(1.5)', true],
            'isEven' => ['isEven(2)', true],
            'isOdd' => ['isOdd(3)', true],
            'read json' => ["read('{\"a\": 1}', 'application/json').a", 1],
            'read defaults to dw' => ["read(\"{a: 'x'}\").a", 'x'],
            'write defaults to dw' => ['write({a: 1})', "{\n  a: 1\n}"],
            'uuid shape' => ['sizeOf(uuid())', 36],
            'random in range' => ['random() < 1', true],
        ];
    }

    public static function directivesAndModules(): array
    {
        return [
            'full header' => ["%dw 2.0\noutput application/json\n---\n{a: 1}", ['a' => 1]],
            'output options' => ["%dw 2.0\noutput application/x-ndjson indent=false, x=\"y\"\n---\n1", 1],
            'input directive' => ["input payload application/json\n---\n1", 1],
            'var directives' => ["var a = 1\nvar b = a + 1\n---\nb", 2],
            'fun directive' => ["fun inc(x) = x + 1\n---\ninc(1)", 2],
            'fun default parameter' => ["fun add(x, y = 10) = x + y\n---\nadd(1)", 11],
            'fun missing parameter is null' => ["fun second(x, y) = y\n---\nsecond(1)", null],
            'typed fun parameter' => ["fun typed(x: Number) = x\n---\ntyped(3)", 3],
            'type directive' => ["type Foo = String {format: 'x', y: 'z'}\n---\n1", 1],
            'ns directive' => ["ns ns0 http://example.com/a-b.c\n---\n1", 1],
            'private directive' => ["private var x = 1\n---\nx", 1],
            'import all' => ["import * from dw::core::Strings\n---\ncamelize('hello-world')", 'helloWorld'],
            'import names' => ["import camelize, dasherize from dw::core::Strings\n---\ndasherize('helloWorld')", 'hello-world'],
            'import skips unknown names' => ["import nope, camelize from dw::core::Strings\n---\ncamelize('a_b')", 'aB'],
            'import unknown module is ignored' => ["import foo from dw::nope\n---\n1", 1],
            'qualified module call' => ["dw::core::Strings::camelize('a-b')", 'aB'],
            'qualified module value' => ["sizeOf(dw::core::Strings::camelize('ab'))", 2],
            'lambda value' => ["var f = (x) -> x * 3\n---\nf(2)", 6],
            'lambda missing argument' => ["var f = (x, y) -> y\n---\nf(1)", null],
        ];
    }

    public static function temporalValues(): array
    {
        return [
            'date literal' => ['|2003-10-01|', '2003-10-01'],
            'datetime literal' => ['|2003-10-01T10:00:00Z|', '2003-10-01T10:00:00+00:00'],
            'local datetime literal' => ['|2003-10-01T10:00:00|', '2003-10-01T10:00:00'],
            'time literal' => ['|23:57:59|', '23:57:59'],
            'time with fraction' => ['|10:00:00.25|', '10:00:00.250000'],
            'datetime with fraction' => ['|2020-01-02T03:04:05.5Z|', '2020-01-02T03:04:05.500000+00:00'],
            'period literal' => ['|PT1S|', 'PT1S'],
            'fractional period' => ['|PT1.5S|', 'PT1.5S'],
            'date plus period' => ['|2003-10-01| + |P1D|', '2003-10-02'],
            'period plus date' => ['|P1D| + |2003-10-01|', '2003-10-02'],
            'date minus period' => ['|2003-10-01| - |P1D|', '2003-09-30'],
            'negative period' => ['|2020-01-01| + |-P1D|', '2019-12-31'],
            'minus negative period' => ['|2020-01-01| - |-P1D|', '2020-01-02'],
            'null plus period stays null' => ['null + |P1D|', null],
            'date minus number is numeric' => ['|2020-01-01| - 1', -1],
            'string as Number' => ["'12' as Number", 12],
            'non-numeric as Number' => ["'x' as Number", 0],
            'number as String' => ['12 as String', '12'],
            'as Boolean' => ['1 as Boolean', true],
            'unknown type passes through' => ['1 as Foo', 1],
            'null coerces to null' => ['null as Date', null],
            'string as Period' => ["'P1D' as Period", 'P1D'],
            'period as Period' => ['|P1D| as Period', 'P1D'],
            'string as DateTime' => ["'2020-01-02 10:00' as DateTime", '2020-01-02T10:00:00+00:00'],
            'string as Time' => ["'10:00:00' as Time", '10:00:00'],
            'string as LocalTime' => ["'10:00:00' as LocalTime", '10:00:00'],
            'date as LocalDateTime' => ['|2020-01-02| as LocalDateTime', '2020-01-02T00:00:00'],
            'date as Date keeps it' => ['|2020-01-02T10:00:00Z| as Date', '2020-01-02'],
            'formatted parse' => ["'2020-01-02' as Date {format: 'yyyy-MM-dd'}", '2020-01-02'],
            'formatted render' => ["|2020-01-02T03:04:05Z| as String {format: 'yyyy/MM/dd HH:mm'}", '2020/01/02 03:04'],
            'formatted render with names' => [
                "|2020-01-02T03:04:05Z| as String {format: \"EEEE dd MMMM yyyy hh:mm a 'T' XXX Z z SSS\"}",
                'Thursday 02 January 2020 03:04 AM T +00:00 +0000 Z 000',
            ],
            'formatted render short fields' => [
                "|2020-01-02T03:04:05.123456Z| as String {format: \"SSSSSS H d M yy EEE h MMM X '' Q\"}",
                '123456 3 2 1 20 Thu 3 Jan +00:00  Q',
            ],
            'empty format is ignored' => ["|2020-01-02| as String {format: ''}", '2020-01-02'],
            'format on a non-temporal' => ["1 as String {format: 'yyyy'}", '1'],
        ];
    }

    /**
     * @dataProvider failingScripts
     */
    public function testReportsErrors(string $script, string $class, string $message): void
    {
        $this->expectException($class);
        $this->expectExceptionMessage($message);

        $this->dwl->transform($script, []);
    }

    public static function failingScripts(): array
    {
        return [
            'unterminated string' => ["'abc", LexerException::class, 'Unterminated string starting at line 1, column 1'],
            'unexpected character' => ['1 ; 2', LexerException::class, "Unexpected character ';' at line 1, column 3"],
            'unexpected token' => [']', ParserException::class, "Unexpected token RIGHT_BRACKET ']' at line 1, column 1"],
            'missing paren' => ['if (true 1 else 2', ParserException::class, "Expected RIGHT_PAREN but got NUMBER '1'"],
            'bad object key' => ['{1: 2}', ParserException::class, 'Expected object key at line 1, column 2'],
            'bad directive' => ["var 1 = 2\n---\n1", ParserException::class, "Expected IDENTIFIER but got NUMBER '1'"],
            'bad do directive' => ['do { 1 --- 2 }', ParserException::class, "Unexpected token NUMBER '1'"],
            'regex after an identifier' => ["'abc' match /b/", ParserException::class, "Unexpected token DIVIDE '/'"],
            'unterminated block comment' => ['/* never closed', ParserException::class, "Unexpected token EOF ''"],
            'undefined variable' => ['nope', RuntimeException::class, "Undefined variable: 'nope' at line 1"],
            'dollar out of scope' => ['$', RuntimeException::class, "Dollar reference '\$' not in scope"],
            'double dollar out of scope' => ['$$', RuntimeException::class, "Dollar reference '\$\$' not in scope"],
            'triple dollar out of scope' => ['$$$', RuntimeException::class, "Dollar reference '\$\$\$' not in scope"],
            'division by zero' => ['1 / 0', RuntimeException::class, 'Division by zero'],
            'call of a non-function' => ["var x = 1\n---\nx(2)", RuntimeException::class, 'Cannot call non-function value'],
            'map over a non-array' => ['5 map $', RuntimeException::class, 'map expects Array but got Number'],
            'mapObject over a non-object' => ['[1] mapObject $', RuntimeException::class, 'mapObject expects Object but got Array'],
            'splitBy on a non-string' => ["1 splitBy ','", RuntimeException::class, 'splitBy expects String but got Number'],
            'unknown infix builtin' => ['1 until 2', RuntimeException::class, 'Unknown infix function: until'],
            'range selector on an object' => [
                '{a: 1}[0 to 1]',
                RuntimeException::class,
                'The range selector [a to b] slices an array or a string, not an object.',
            ],
            'unresolved module member' => ['dw::core::Strings::nope', RuntimeException::class, 'Cannot resolve dw::core::Strings::nope'],
            'unknown module' => ['dw::nope::x', RuntimeException::class, 'Cannot resolve dw::nope::x'],
            'runtime fail' => ["dw::Runtime::fail('boom')", RuntimeException::class, 'boom'],
            'runtime fail default message' => ['dw::Runtime::fail()', RuntimeException::class, 'Execution failed'],
            'invalid period' => ["'bad' as Period", RuntimeException::class, "Invalid period '|bad|' at line 1"],
            'invalid date' => ['|2003-13-45|', RuntimeException::class, "Invalid date/time '|2003-13-45|' at line 1"],
            'unparseable formatted date' => [
                "'abc' as Date {format: 'yyyy'}",
                RuntimeException::class,
                "Cannot parse 'abc' as date with format \"yyyy\" at line 1",
            ],
        ];
    }

    /**
     * The RANGE selector `value[a to b]` on strings (characters) and arrays: inclusive bounds,
     * negative indices from the end, reversed when a > b, null outside the value.
     *
     * @dataProvider rangeSelectors
     */
    public function testRangeSelectors(string $script, mixed $expected): void
    {
        self::assertSame($expected, $this->dwl->transform($script, []));
    }

    public static function rangeSelectors(): array
    {
        return [
            'string slice' => ["'DataWeave'[0 to 3]", 'Data'],
            'string slice to the end' => ["'DataWeave'[4 to -1]", 'Weave'],
            'string all but the ends' => ["'[x]'[1 to -2]", 'x'],
            'string reversed' => ["'abc'[2 to 0]", 'cba'],
            'string of characters, not bytes' => ["'ação'[1 to 2]", 'çã'],
            'string out of range' => ["'abc'[1 to 5]", null],
            'string single character' => ["'ação'[1]", 'ç'],
            'string single character from the end' => ["'abc'[-1]", 'c'],
            'array slice' => ['[0, 1, 2, 3, 4][1 to 3]', [1, 2, 3]],
            'array tail' => ['[0, 1, 2, 3][1 to -1]', [1, 2, 3]],
            'array reversed' => ['[0, 1, 2, 3][3 to 1]', [3, 2, 1]],
            'array out of range' => ['[0, 1][0 to 4]', null],
            'null stays null' => ['null[0 to 1]', null],
            'computed bounds' => ["var s = 'OUT_ORO_PRODUCTS_CLASSIFICATION_OF_PRODUCT_20261124.csv'\n---\ns[sizeOf('OUT_ORO_PRODUCTS_') to (s lastIndexOf '_') - 1]", 'CLASSIFICATION_OF_PRODUCT'],
            'a plain range still builds a list' => ['(1 to 3) map $ * 2', [2, 4, 6]],
        ];
    }

    /** The bug report's exact expression (flow 45), with `item` a file NAME and a prefix-sized mapping. */
    public function testTheReportedExpressionSlicesTheFileName(): void
    {
        $result = $this->dwl->transform("item[sizeOf(file_mapping) to (item lastIndexOf  '_') - 1]", [
            'item' => 'OUT_ORO_PRODUCTS_CLASSIFICATION_OF_PRODUCT_20261124.csv',
            'file_mapping' => 'OUT_ORO_PRODUCTS_',
        ]);

        self::assertSame('CLASSIFICATION_OF_PRODUCT', $result);
    }

    public function testBindingsBecomeVariables(): void
    {
        $bindings = [
            'payload' => ['id' => 7, 'tags' => ['a', 'b'], 'ok' => true, 'score' => 1.5, 'none' => null],
            'meta' => (object) ['source' => 'api'],
            'unused' => ['never' => 'converted'],
        ];

        self::assertSame(
            ['id' => 7, 'tags' => ['a', 'b'], 'ok' => true, 'score' => 1.5, 'none' => null, 'src' => 'api'],
            $this->dwl->transform('payload ++ {src: meta.source}', $bindings)
        );
    }

    public function testExoticBindingsDegradeToTheirJson(): void
    {
        $value = new \ArrayObject([1]);

        self::assertSame('{"0":1}', $this->dwl->transform('thing', ['thing' => $value]));
    }

    public function testContextExposesEveryBindingIncludingNonIdentifierKeys(): void
    {
        $bindings = ['some-key' => 1, 'payload' => 2, 3 => 'numeric'];

        self::assertSame(
            [1, 2, 'numeric'],
            $this->dwl->transform('[context["some-key"], context.payload, context["3"]]', $bindings)
        );
    }

    public function testAContextBindingWinsOverTheSynthesizedObject(): void
    {
        self::assertSame('mine', $this->dwl->transform('context', ['context' => 'mine']));
    }

    public function testUnreferencedBindingsAreNotVisibleThroughOtherNames(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Undefined variable: 'nope'");

        $this->dwl->transform('nope', ['payload' => 1]);
    }

    public function testValidateReportsParseErrorsOnly(): void
    {
        self::assertNull($this->dwl->validate('payload map $.id'));
        self::assertNull($this->dwl->validate('undefinedIsFineUntilRuntime'));
        self::assertSame("Unexpected token RIGHT_BRACE '}' at line 1, column 1", $this->dwl->validate('}'));
        self::assertSame('Unterminated string starting at line 1, column 1', $this->dwl->validate('"abc'));
    }

    public function testTheParseCacheIsBoundedAndStillServesResults(): void
    {
        $cache = new \ReflectionProperty(DwlTransformer::class, 'scriptCache');
        for ($i = 0; $i < 70; $i++) {
            self::assertSame($i, $this->dwl->transform((string) $i, []));
        }

        self::assertLessThanOrEqual(64, \count($cache->getValue()));
        // A cached script evaluates exactly like a fresh one.
        self::assertSame(69, $this->dwl->transform('69', []));
    }

    public function testFunctionResultsLeaveTheEngineAsClosures(): void
    {
        self::assertInstanceOf(\Closure::class, $this->dwl->transform('(x) -> x', []));
    }
}
