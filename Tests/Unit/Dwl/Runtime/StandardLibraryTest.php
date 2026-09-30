<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\StandardLibrary;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use PHPUnit\Framework\TestCase;

/**
 * The importable modules (dw::core::*, dw::Runtime), exercised the way scripts use them.
 */
class StandardLibraryTest extends TestCase
{
    /**
     * @dataProvider moduleCalls
     */
    public function testModuleFunctions(string $script, mixed $expected): void
    {
        self::assertSame($expected, (new DwlTransformer())->transform($script, []));
    }

    public static function moduleCalls(): array
    {
        $arrays = "import * from dw::core::Arrays\n---\n";
        $strings = "import * from dw::core::Strings\n---\n";

        return [
            'countBy' => [$arrays . 'countBy([1,2,3], (x) -> x > 1)', 2],
            'sumBy' => [$arrays . 'sumBy([1,2,3], (x) -> x * 2)', 12],
            'take' => [$arrays . 'take([1,2,3], 2)', [1, 2]],
            'drop' => [$arrays . 'drop([1,2,3], 2)', [3]],
            'every true' => [$arrays . 'every([1,2], (x) -> x > 0)', true],
            'every false' => [$arrays . 'every([1,2], (x) -> x > 1)', false],
            'some true' => [$arrays . 'some([1,2], (x) -> x > 1)', true],
            'some false' => [$arrays . 'some([1,2], (x) -> x > 2)', false],
            'slice' => [$arrays . 'slice([1,2,3,4], 1, 3)', [2, 3]],
            'slice clamps' => [$arrays . 'slice([1,2], -5, 9)', [1, 2]],
            'slice empty range' => [$arrays . 'slice([1,2,3], 2, 1)', []],
            'divideBy' => [$arrays . 'divideBy([1,2,3], 2)', [[1, 2], [3]]],
            'divideBy guards the size' => [$arrays . 'divideBy([1,2], 0)', [[1], [2]]],
            'takeWhile' => [$arrays . 'takeWhile([1,2,5,1], (x) -> x < 3)', [1, 2]],
            'dropWhile' => [$arrays . 'dropWhile([1,2,5,1], (x) -> x < 3)', [5, 1]],
            'dropWhile everything' => [$arrays . 'dropWhile([1,2], (x) -> x < 3)', []],
            'firstWith' => [$arrays . 'firstWith([1,2,3], (x, i) -> i == 1)', 2],
            'firstWith none' => [$arrays . 'firstWith([1], (x) -> false)', null],
            'indexOf' => [$arrays . "indexOf(['a','b'], 'b')", 1],
            'lastIndexOf' => [$arrays . 'lastIndexOf([1,2,1], 1)', 2],
            'lastIndexOf is type strict' => [$arrays . "lastIndexOf([1,2], '1')", -1],
            'indexWhere' => [$arrays . 'indexWhere([5,6], (x, i) -> x == 6)', 1],
            'indexWhere none' => [$arrays . 'indexWhere([5], (x) -> false)', -1],
            'partition' => [$arrays . 'partition([1,2,3], (x) -> x > 1)', ['success' => [2, 3], 'failure' => [1]]],
            'splitAt' => [$arrays . 'splitAt([1,2,3], 1)', ['l' => [1], 'r' => [2, 3]]],
            'splitAt negative' => [$arrays . 'splitAt([1,2], -1)', ['l' => [], 'r' => [1, 2]]],
            'splitWhere' => [$arrays . 'splitWhere([1,2,3], (x) -> x == 2)', ['l' => [1], 'r' => [2, 3]]],
            'splitWhere no match' => [$arrays . 'splitWhere([1], (x) -> false)', ['l' => [1], 'r' => []]],
            'join' => [
                $arrays . "join([{id:1},{id:2}], [{k:1,v:'a'},{k:1,v:'b'},{k:3,v:'c'}], (l) -> l.id, (r) -> r.k)",
                [['l' => ['id' => 1], 'r' => ['k' => 1, 'v' => 'a']], ['l' => ['id' => 1], 'r' => ['k' => 1, 'v' => 'b']]],
            ],
            'join keeps types apart' => [$arrays . "join([{id:1}], [{k:'1'}], (l) -> l.id, (r) -> r.k)", []],
            'leftJoin' => [
                $arrays . 'leftJoin([{id:1},{id:2}], [{k:1}], (l) -> l.id, (r) -> r.k)',
                [['l' => ['id' => 1], 'r' => ['k' => 1]], ['l' => ['id' => 2]]],
            ],
            'outerJoin' => [
                $arrays . 'outerJoin([{id:1},{id:2}], [{k:1},{k:3}], (l) -> l.id, (r) -> r.k)',
                [['l' => ['id' => 1], 'r' => ['k' => 1]], ['l' => ['id' => 2]], ['r' => ['k' => 3]]],
            ],
            'core alias module' => ["import take from dw::Core\n---\ntake([1,2], 1)", [1]],
            'camelize' => [$strings . "camelize('hello_big-world')", 'helloBigWorld'],
            'dasherize' => [$strings . "dasherize('HelloWorld')", 'hello-world'],
            'underscore' => [$strings . "underscore('helloWorld')", 'hello_world'],
            'pluralize' => [$strings . "pluralize('cat')", 'cats'],
            'singularize' => [$strings . "singularize('cats')", 'cat'],
            'repeat' => [$strings . "repeat('ab', 3)", 'ababab'],
            'leftPad' => [$strings . "leftPad('7', 3, '0')", '007'],
            'leftPad default char' => [$strings . "leftPad('7', 2)", ' 7'],
            'rightPad' => [$strings . "rightPad('7', 3, '0')", '700'],
            'mergeWith' => ["import mergeWith from dw::core::Objects\n---\nmergeWith({a:1}, {b:2})", ['a' => 1, 'b' => 2]],
            'isNaN' => ["import * from dw::core::Numbers\n---\nisNaN(1)", false],
            'isInfinite' => ["import * from dw::core::Numbers\n---\nisInfinite(1)", false],
        ];
    }

    public function testRuntimePropsExposesTheProcessEnvironment(): void
    {
        $props = StandardLibrary::getModule('dw::Runtime')['props'];

        $value = ($props->data)([]);

        self::assertSame(array_keys(getenv()), array_map(static fn (array $pair): string => $pair[0]->data, $value->data));
    }

    public function testUnknownModulesResolveToNull(): void
    {
        self::assertNull(StandardLibrary::getModule('dw::core::Nope'));
    }

    public function testEveryModuleMemberIsAFunction(): void
    {
        foreach (['dw::core::Arrays', 'dw::core::Strings', 'dw::core::Objects', 'dw::core::Numbers', 'dw::Runtime'] as $module) {
            foreach (StandardLibrary::getModule($module) as $name => $member) {
                self::assertInstanceOf(Value::class, $member, $module . '::' . $name);
                self::assertIsCallable($member->data, $module . '::' . $name);
            }
        }
    }
}
