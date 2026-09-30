<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\DwlOutputFormatter;
use PHPUnit\Framework\TestCase;

class DwlOutputFormatterTest extends TestCase
{
    private DwlOutputFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DwlOutputFormatter();
    }

    /**
     * @dataProvider headers
     *
     * @param array{format: string, mime: string, extension: string} $expected
     */
    public function testDetectsTheOutputDirective(string $script, array $expected): void
    {
        self::assertSame($expected, $this->formatter->detect($script));
    }

    public static function headers(): array
    {
        $json = ['format' => 'json', 'mime' => 'application/json', 'extension' => 'json'];

        return [
            'no header' => ['payload', $json],
            'json' => ["%dw 2.0\noutput application/json\n---\npayload", $json],
            'csv subtype' => ["output application/csv\n---\n1", ['format' => 'csv', 'mime' => 'application/csv', 'extension' => 'csv']],
            'x- prefix is ignored' => ["output application/x-csv\n---\n1", ['format' => 'csv', 'mime' => 'application/x-csv', 'extension' => 'csv']],
            'xml' => ["  OUTPUT Application/XML indent=true\n---\n1", ['format' => 'xml', 'mime' => 'application/xml', 'extension' => 'xml']],
            'text plain' => ["output text/plain\n---\n1", ['format' => 'text', 'mime' => 'text/plain', 'extension' => 'txt']],
            'unknown subtype falls back to the type' => ["output text/markdown\n---\n1", ['format' => 'text', 'mime' => 'text/markdown', 'extension' => 'txt']],
            'unknown mime falls back to json' => ["output application/yaml\n---\n1", ['format' => 'json', 'mime' => 'application/yaml', 'extension' => 'json']],
            'directive in the body is ignored' => ["%dw 2.0\n---\n\"x\"\noutput application/csv", $json],
            'header-less directive still counts' => ['output application/csv', ['format' => 'csv', 'mime' => 'application/csv', 'extension' => 'csv']],
        ];
    }

    /**
     * @dataProvider serializations
     */
    public function testSerializes(mixed $data, string $format, string $expected): void
    {
        self::assertSame($expected, $this->formatter->serialize($data, $format));
    }

    public static function serializations(): array
    {
        return [
            'json pretty unescaped' => [['a' => 'é/x', 'b' => [1]], 'json', "{\n    \"a\": \"é/x\",\n    \"b\": [\n        1\n    ]\n}"],
            'unknown format is json' => [1, 'yaml', '1'],
            'unencodable json is empty' => [NAN, 'json', ''],
            'text string' => ['hello', 'text', 'hello'],
            'text null' => [null, 'text', ''],
            'text booleans' => [false, 'text', 'false'],
            'text number' => [1.5, 'text', '1.5'],
            'text list of scalars' => [['a', 1, true, null], 'text', "a\n1\ntrue\n"],
            'text rich list is json' => [[['a' => 1]], 'text', "[\n    {\n        \"a\": 1\n    }\n]"],
            'text empty list is json' => [[], 'text', '[]'],
            'text object is json' => [['a' => 1], 'text', "{\n    \"a\": 1\n}"],
            'csv rows with the union of keys' => [
                [['a' => 1, 'b' => true], ['b' => null, 'c' => ['x' => '/']], 'scalar'],
                'csv',
                "a,b,c,value\n1,true,,\n,,\"{\"\"x\"\":\"\"/\"\"}\",\n,,,scalar\n",
            ],
            'csv quotes' => [[['q' => 'say "hi", ok']], 'csv', "q\n\"say \"\"hi\"\", ok\"\n"],
            'csv single object' => [['a' => false], 'csv', "a\nfalse\n"],
            'csv scalar' => ['x', 'csv', "value\nx\n"],
            'csv null' => [null, 'csv', ''],
            'csv empty list' => [[], 'csv', ''],
            'csv nested list cell' => [[[1, 2]], 'csv', "value\n\"[1,2]\"\n"],
            'xml nested' => [
                ['name' => 'a&b', 'items' => [1, null, true], '1bad key' => 'x', '' => 'e'],
                'xml',
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n  <name>a&amp;b</name>\n  <items>\n    <item>1</item>\n    <item/>\n    <item>true</item>\n  </items>\n  <item_1bad_key>x</item_1bad_key>\n  <item>e</item>\n</result>\n",
            ],
            'xml scalar' => ['v', 'xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>v</result>\n"],
            'xml null' => [null, 'xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result/>\n"],
        ];
    }
}
