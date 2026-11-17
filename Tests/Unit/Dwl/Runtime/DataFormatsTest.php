<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Dwl\Language\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * read()/write() and the data formats behind them, exercised the way scripts use them. Raw input
 * text travels as the `text` binding so the scripts stay readable.
 */
class DataFormatsTest extends TestCase
{
    /**
     * @dataProvider reads
     */
    public function testRead(string $script, string $text, mixed $expected): void
    {
        self::assertSame($expected, (new DwlTransformer())->transform($script, ['text' => $text]));
    }

    public static function reads(): array
    {
        $csv = "id,name,note\n1,Ann,\"a, b\"\n\n2,Bob,\"two\nlines\"\n3,Cy\n";

        return [
            // JSON
            'json object' => ['read(text, "application/json").salesOrg', '{"salesOrg": "BR10"}', 'BR10'],
            'json nested' => ['read(text, "application/json").a.b[1]', '{"a": {"b": [1, 2.5]}}', 2.5],
            'json scalars' => [
                'read(text, "application/json")',
                '[true, null, "x", -3]',
                [true, null, 'x', -3],
            ],
            'json keeps an empty object an object' => [
                'write(read(text, "application/json"), "application/json", {indent: false})',
                '{"a": {}, "b": []}',
                '{"a":{},"b":[]}',
            ],
            'json strips a BOM' => ['read(text, "application/json").a', "\xEF\xBB\xBF{\"a\": 1}", 1],
            'json content type parameters' => ['read(text, "application/json; charset=UTF-8").a', '{"a": 1}', 1],
            'json structured suffix' => ['read(text, "application/vnd.api+json").a', '{"a": 1}', 1],
            'json content type is case-insensitive' => ['read(text, "Application/JSON").a', '{"a": 1}', 1],
            // XML
            'xml repeated elements' => [
                'read(text, "application/xml").order.*item',
                '<order><item>1</item><item>2</item></order>',
                ['1', '2'],
            ],
            'xml nested' => [
                'read(text, "text/xml").a.b.c',
                '<?xml version="1.0"?><a><b><c>deep</c></b></a>',
                'deep',
            ],
            'xml blank element is null' => ['read(text, "application/xml").a.b', '<a><b>  </b></a>', null],
            'xml nullValueOn empty keeps blanks' => [
                'read(text, "application/xml", {nullValueOn: "empty"}).a.b',
                '<a><b>  </b></a>',
                '  ',
            ],
            'xml nullValueOn none keeps empties' => [
                'read(text, "application/xml", {nullValueOn: "none"}).a.b',
                '<a><b/></a>',
                '',
            ],
            'xml cdata' => ['read(text, "application/xml").a', '<a><![CDATA[x < y]]></a>', 'x < y'],
            // CSV
            'csv with header' => [
                'read(text, "application/csv")',
                $csv,
                [
                    ['id' => '1', 'name' => 'Ann', 'note' => 'a, b'],
                    ['id' => '2', 'name' => 'Bob', 'note' => "two\nlines"],
                    ['id' => '3', 'name' => 'Cy', 'note' => null],
                ],
            ],
            'csv without header' => [
                'read(text, "text/csv", {header: false, separator: ";"})',
                "a;b\nc;d;e\n",
                [
                    ['column_0' => 'a', 'column_1' => 'b'],
                    ['column_0' => 'c', 'column_1' => 'd', 'column_2' => 'e'],
                ],
            ],
            'csv header as string flag' => [
                'read(text, "application/csv", {header: "false", separator: ";"})[1].column_2',
                "a;b\nc;d;e\n",
                'e',
            ],
            'csv extra cells are numbered' => [
                'read(text, "application/csv")[0]',
                "a\n1,2\n",
                ['a' => '1', 'column_1' => '2'],
            ],
            'csv empty input' => ['read(text, "application/csv")', '', []],
            // text/plain
            'text is kept verbatim' => ['read(text, "text/plain")', " {a: 1}\n", " {a: 1}\n"],
            // application/dw
            'dw default content type' => ['read(text).salesOrg', "{salesOrg: 'BR10'}", 'BR10'],
            'dw explicit' => [
                'read(text, "application/dw")',
                "{a: [1, -2.5, true, null], 'b c': {d: \"e\"}}",
                ['a' => [1, -2.5, true, null], 'b c' => ['d' => 'e']],
            ],
            'dw date literal' => ['read(text)', '|2024-01-31|', '2024-01-31'],
            'dw with version header' => ['read(text).a', "%dw 2.0\n---\n{a: 1}", 1],
            'dw keyword key' => ['read(text).type', '{type: "x"}', 'x'],
        ];
    }

    /**
     * The exact script from the bug report — read() used to hand the string back untouched, so the
     * selector ran against a String and produced null.
     */
    public function testReadJsonInsideAScript(): void
    {
        $script = <<<'DWL'
            %dw 2.0
            output application/json
            var c = read('{"salesOrg": "BR10"}', "application/json")
            ---
            c.salesOrg
            DWL;

        self::assertSame('BR10', (new DwlTransformer())->transform($script, []));
    }

    /**
     * @dataProvider writes
     */
    public function testWrite(string $script, string $expected): void
    {
        self::assertSame($expected, (new DwlTransformer())->transform($script, []));
    }

    public static function writes(): array
    {
        return [
            // JSON
            'json pretty uses 2 spaces' => [
                'write({a: [1, 2], b: {}}, "application/json")',
                "{\n  \"a\": [\n    1,\n    2\n  ],\n  \"b\": {}\n}",
            ],
            'json compact' => ['write({a: "x/y", b: 1.0}, "application/json", {indent: false})', '{"a":"x/y","b":1.0}'],
            'json date' => ['write(|2024-01-31|, "application/json")', '"2024-01-31"'],
            // XML
            'xml' => [
                'write({root: {item: [1, 2], name: "a&b", none: null, ok: true}}, "application/xml")',
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root>\n  <item>1</item>\n  <item>2</item>\n"
                . "  <name>a&amp;b</name>\n  <none/>\n  <ok>true</ok>\n</root>",
            ],
            // CSV
            'csv' => [
                'write([{a: 1, b: "x,y"}, {a: 2, c: true}], "application/csv")',
                "a,b,c\n1,\"x,y\",\n2,,true\n",
            ],
            'csv without header, custom separator' => [
                'write([{a: 1, b: null}], "text/csv", {header: false, separator: ";"})',
                "1;\n",
            ],
            'csv nested cell' => ['write([{a: {b: [1]}}], "application/csv")', "a\n\"{\"\"b\"\":[1]}\"\n"],
            'csv empty' => ['write([], "application/csv")', ''],
            // text/plain
            'text string' => ['write("hi", "text/plain")', 'hi'],
            'text number' => ['write(1.5, "text/plain")', '1.5'],
            'text boolean' => ['write(false, "text/plain")', 'false'],
            'text null' => ['write(null, "text/plain")', ''],
            // application/dw
            'dw' => [
                'write({a: [1, -2.5], "b c": "q\"\n", "true": null, e: [], f: {}})',
                "{\n  a: [\n    1,\n    -2.5\n  ],\n  \"b c\": \"q\\\"\\n\",\n  \"true\": null,\n  e: [],\n  f: {}\n}",
            ],
            'dw scalars' => ['write([true, |2024-01-31|, |PT1H|, /a+/])', "[\n  true,\n  |2024-01-31|,\n  |PT1H|,\n  /a+/\n]"],
            'dw float keeps its fraction' => ['write(2.0, "application/dw")', '2.0'],
        ];
    }

    /**
     * @dataProvider roundTrips
     */
    public function testRoundTrip(string $contentType): void
    {
        $script = sprintf(
            'var v = {id: "1", tags: ["a", "b"], nested: {ok: "yes", "odd key": "$x"}}'
            . "\n---\nread(write(v, \"%s\"), \"%s\") == v",
            $contentType,
            $contentType,
        );

        self::assertTrue((new DwlTransformer())->transform($script, []));
    }

    public static function roundTrips(): array
    {
        return [
            'json' => ['application/json'],
            'dw' => ['application/dw'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testFailure(string $script, string $text, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches($message);

        (new DwlTransformer())->transform($script, ['text' => $text]);
    }

    public static function failures(): array
    {
        return [
            'dw literal is not json' => [
                'read(text, "application/json")',
                "{salesOrg: 'BR10'}",
                '~invalid application/json input .*use "application/dw"~',
            ],
            'read of a structure' => ['read({a: 1}, "application/json")', '', '~expected a String to parse, got Object~'],
            'unknown content type' => ['read(text, "application/yaml")', 'a: 1', '~unsupported content type "application/yaml"~'],
            'content type not a string' => ['read(text, 1)', '{}', '~content type must be a String, got Number~'],
            'malformed xml' => ['read(text, "application/xml")', '<a><b></a>', '~invalid application/xml input \(.+\)~'],
            'empty xml' => ['read(text, "application/xml")', ' ', '~invalid application/xml input \(empty document\)~'],
            'csv separator too long' => [
                'read(text, "application/csv", {separator: ";;"})',
                'a',
                '~"separator" must be a single character~',
            ],
            'csv empty quote' => ['read(text, "application/csv", {quote: ""})', 'a', '~"quote" must be a single character~'],
            'dw syntax error' => ['read(text)', '{a: ', '~invalid application/dw input~'],
            'dw expression is not evaluated' => ['read(text)', '1 + 1', '~must be a literal value~'],
            'dw identifier value' => ['read(text)', '{a: payload}', '~must be a literal value~'],
            'dw function call' => ['read(text)', '{a: dw::Runtime::props()}', '~must be a literal value~'],
            'dw directive' => ['read(text)', "import * from dw::Runtime\n---\n{a: 1}", '~must be a literal value~'],
            'dw dynamic key' => ['read(text)', '{(k): 1}', '~must be a literal value~'],
            'dw conditional entry' => ['read(text)', '{a: 1 if true}', '~must be a literal value|invalid application/dw~'],
            'xml needs one root' => ['write({a: 1, b: 2}, "application/xml")', '', '~exactly one root key~'],
            'xml root cannot repeat' => ['write({a: [1, 2]}, "application/xml")', '', '~exactly one root key~'],
            'xml of an array' => ['write([1], "application/xml")', '', '~exactly one root key~'],
            'xml invalid element name' => ['write({"a b": 1}, "application/xml")', '', '~"a b" is not a valid XML element name~'],
            'csv of an object' => ['write({a: 1}, "application/csv")', '', '~needs an array of objects, got Object~'],
            'csv of scalars' => ['write([1], "application/csv")', '', '~found a Number row~'],
            'text of a structure' => ['write([1], "text/plain")', '', '~can only write simple values, got Array~'],
            'dw of a function' => ['write((x) -> x)', '', '~cannot write a Function~'],
            'json of infinity' => ['write(pow(10, 400), "application/json")', '', '~cannot write application/json~'],
            'xml of a function' => ['write({a: (x) -> x}, "application/xml")', '', '~cannot write a Function as application/xml~'],
        ];
    }
}
