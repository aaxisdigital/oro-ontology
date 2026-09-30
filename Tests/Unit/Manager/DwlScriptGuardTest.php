<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptGuard;
use PHPUnit\Framework\TestCase;

class DwlScriptGuardTest extends TestCase
{
    private const string REFUSAL = 'This script is not allowed to read the server environment '
        . '(dw::Runtime::props is disabled in the playground).';

    /**
     * @dataProvider scripts
     */
    public function testScreensScripts(string $script, bool $refused): void
    {
        self::assertSame($refused ? self::REFUSAL : null, (new DwlScriptGuard())->check($script));
    }

    public static function scripts(): array
    {
        return [
            'plain script' => ['payload map $.id', false],
            'qualified props call' => ['dw::Runtime::props()', true],
            'qualified props reference' => ['{env: dw::Runtime::props}', true],
            'spaced module path' => ['dw :: Runtime :: props()', true],
            'commented module path' => ['dw::/*c*/Runtime::props()', true],
            'deep inside the body' => ['[1, {a: [(x) -> dw::Runtime::props()]}]', true],
            'inside a function directive' => ["fun f() = dw::Runtime::props()\n---\nf()", true],
            'import all from Runtime' => ["import * from dw::Runtime\n---\n1", true],
            'import props by name' => ["import fail, props from dw::Runtime\n---\n1", true],
            'import fail only' => ["import fail from dw::Runtime\n---\nfail('x')", false],
            'fail stays usable' => ["dw::Runtime::fail('x')", false],
            'props as a key or text' => ["{props: 'dw::Runtime::props'}", false],
            'props of another module' => ['dw::core::Strings::props', false],
            'import all from another module' => ["import * from dw::core::Strings\n---\n1", false],
            'unparseable scripts pass' => ['{ dw::Runtime::props(', false],
        ];
    }
}
