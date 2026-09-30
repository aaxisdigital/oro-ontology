<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Exception\PhpInvocationFailure;
use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\InvokableService;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ServiceProviderInterface;

class PhpMethodInvokerTest extends TestCase
{
    private const string SERVICE = InvokableService::class;

    private ConfigManager&MockObject $config;
    private PhpMethodInvoker $invoker;

    protected function setUp(): void
    {
        $services = $this->createMock(ServiceProviderInterface::class);
        $provided = [self::SERVICE => self::SERVICE, 'Other\\Vendor\\Thing' => 'Other\\Vendor\\Thing', 'Edge\\Api' => 'Edge\\Api'];
        $services->method('getProvidedServices')->willReturn($provided);
        $services->method('has')->willReturnCallback(static fn (string $id): bool => isset($provided[$id]));
        $services->method('get')->willReturnCallback(static fn (string $id): object => new InvokableService());
        $this->config = $this->createMock(ConfigManager::class);
        $this->config->method('get')->with('aaxis_ontology.invoke_php_namespaces')->willReturnCallback(fn () => $this->prefixes);
        $this->invoker = new PhpMethodInvoker($services, new DwlTransformer(), $this->config);
    }

    private ?string $prefixes = 'Aaxis\\';

    public function testListsTheClassesUnderTheConfiguredPrefixes(): void
    {
        self::assertSame([self::SERVICE], $this->invoker->invokableClasses());

        $this->prefixes = "\\Edge\\ ,\r\n  Other\\\n\n";
        self::assertSame(['Other\\Vendor\\Thing', 'Edge\\Api'], $this->invoker->invokableClasses());

        $this->prefixes = null;
        self::assertSame([], $this->invoker->invokableClasses());
    }

    public function testIsInvokableNeedsAServiceAndAnAllowedPrefix(): void
    {
        self::assertTrue($this->invoker->isInvokable(self::SERVICE));
        self::assertFalse($this->invoker->isInvokable('Other\\Vendor\\Thing'));
        self::assertFalse($this->invoker->isInvokable('Aaxis\\Not\\A\\Service'));
    }

    /**
     * @dataProvider successfulCalls
     */
    public function testInvokesWithParametersBoundByName(string $method, string $params, array $context, mixed $expected): void
    {
        $result = $this->invoker->invoke('Step', ['class' => '\\' . self::SERVICE . ' ', 'method' => " $method ", 'params' => $params], $context);

        self::assertSame($expected, $result);
    }

    public static function successfulCalls(): array
    {
        return [
            'scalars with a default' => ['scalars', '{count: 2, ratio: 1, label: name}', ['name' => 'x'], [2, 1.0, 'x', false]],
            'float and bool given' => ['scalars', '{count: 2, ratio: 0.5, label: "l", flag: true}', [], [2, 0.5, 'l', true]],
            'loose types' => ['loose', '{anything: null, list: [1], items: {a: 1}, untyped: "u"}', [], [null, [1], ['a' => 1], 'u', null]],
            'nullable given null' => ['loose', '{anything: 1, list: [], items: [], untyped: 1, maybe: null}', [], [1, [], [], 1, null]],
            'union' => ['union', '{key: 5}', [], 'key:5'],
            'nullable union' => ['nullableUnion', '{key: null}', [], 'key:'],
            'no parameters, null result' => ['nothing', '', [], null],
            'null parameters object' => ['nothing', 'null', [], null],
            'empty parameters object' => ['nothing', '{}', [], null],
            'object result is flattened' => ['dto', '', [], ['id' => 7, 'nested' => ['ok' => true]]],
            'objects inside arrays are flattened' => ['listOfDtos', '', [], [['a' => 1], 'plain', 2]],
            'static method' => ['twice', '{n: 21}', [], 42],
        ];
    }

    /**
     * @dataProvider failingCalls
     */
    public function testRefusesWhatCannotBeCalledSafely(array $config, string $message): void
    {
        $this->expectException(PhpInvocationFailure::class);
        $this->expectExceptionMessage($message);

        $this->invoker->invoke('Step', $config + ['class' => self::SERVICE], []);
    }

    public static function failingCalls(): array
    {
        $service = self::SERVICE;

        return [
            'no class' => [['class' => ' ', 'method' => 'x'], 'Step "Step" is not configured.'],
            'no method' => [['method' => ''], 'Step "Step" is not configured.'],
            'not invokable' => [['class' => 'Other\\Vendor\\Thing', 'method' => 'x'], 'Step "Step": "Other\\Vendor\\Thing" is not an invokable service'],
            'failing parameters' => [['method' => 'nothing', 'params' => 'nope'], "Step \"Step\": Undefined variable: 'nope'"],
            'list parameters' => [['method' => 'nothing', 'params' => '[1]'], 'Step "Step": the parameters must resolve to an object of named values, got array.'],
            'scalar parameters' => [['method' => 'nothing', 'params' => '1'], 'got int.'],
            'unknown method' => [['method' => 'missing'], "Step \"Step\": $service has no method \"missing\"."],
            'private method' => [['method' => 'secret'], "Step \"Step\": $service::secret is not callable."],
            'constructor' => [['method' => '__construct'], "Step \"Step\": $service::__construct is not callable."],
            'destructor' => [['method' => '__destruct'], "Step \"Step\": $service::__destruct is not callable."],
            'missing required' => [['method' => 'scalars', 'params' => '{count: 1}'], "Step \"Step\": the required parameter \"ratio\" of $service::scalars was not provided."],
            'unknown names' => [['method' => 'twice', 'params' => '{n: 1, x: 2, y: 3}'], "Step \"Step\": $service::twice has no parameter named \"x\", \"y\"."],
            'numeric string for int' => [['method' => 'twice', 'params' => "{n: '1'}"], "Step \"Step\": parameter \"n\" of $service::twice expects int, got string."],
            'string for float' => [['method' => 'scalars', 'params' => "{count: 1, ratio: '1', label: 'l'}"], 'expects float, got string.'],
            'int for string' => [['method' => 'scalars', 'params' => '{count: 1, ratio: 1, label: 1}'], 'expects string, got int.'],
            'string for bool' => [['method' => 'scalars', 'params' => "{count: 1, ratio: 1, label: 'l', flag: 'yes'}"], 'expects bool, got string.'],
            'scalar for array' => [['method' => 'loose', 'params' => '{anything: 1, list: 1, items: [], untyped: 1}'], 'expects array, got int.'],
            'null for non-nullable' => [['method' => 'twice', 'params' => '{n: null}'], 'expects int, got null.'],
            'union mismatch' => [['method' => 'nullableUnion', 'params' => '{key: true}'], 'expects string|int|null, got bool.'],
            'intersection' => [['method' => 'intersection', 'params' => '{things: []}'], 'expects Countable&Traversable, got array.'],
            'class type' => [['method' => 'object', 'params' => "{at: '2026-01-01'}"], 'expects DateTimeInterface, got string.'],
            'method throws' => [['method' => 'explode'], "Step \"Step\": $service::explode failed — kaboom"],
        ];
    }
}
