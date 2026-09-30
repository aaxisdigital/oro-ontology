<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\EndpointResponseFailure;
use Aaxis\Bundle\OntologyBundle\Manager\EndpointFlowRunner;
use Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EndpointFlowRunnerTest extends TestCase
{
    private ObjectRepository&MockObject $flows;
    private FlowDebugExecutor&MockObject $executor;
    private EndpointFlowRunner $runner;

    protected function setUp(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $this->flows = $this->createMock(ObjectRepository::class);
        $doctrine->method('getRepository')->with(OntologyFlow::class)->willReturn($this->flows);
        $this->executor = $this->createMock(FlowDebugExecutor::class);
        $this->runner = new EndpointFlowRunner($doctrine, $this->executor, new DwlTransformer());
    }

    private static function flow(string $name, ?array $triggerConfig): OntologyFlow
    {
        return (new OntologyFlow())->setName($name)->setDesign([
            'steps' => [['id' => 'e', 'type' => 'endpoint', 'name' => $name, 'config' => $triggerConfig]],
            'links' => [],
        ]);
    }

    /**
     * @param OntologyFlow[] $flows
     */
    private function withFlows(array $flows): void
    {
        $this->flows->method('findBy')
            ->with(['type' => OntologyFlow::TYPE_FLOW, 'enabled' => true, 'triggerType' => 'endpoint'], ['id' => 'ASC'])
            ->willReturn($flows);
    }

    public function testMatchesMethodAndPathCapturingParameters(): void
    {
        $orders = self::flow('Order by id', ['method' => 'get', 'path' => '/orders/{id}/lines/{line_no}']);
        $this->withFlows([
            (new OntologyFlow())->setName('No design'),
            self::flow('Wrong method', ['method' => 'POST', 'path' => 'orders/{id}/lines/{n}']),
            self::flow('Wrong length', ['method' => 'GET', 'path' => 'orders/{id}']),
            self::flow('Wrong literal', ['method' => 'GET', 'path' => 'order/{id}/lines/{n}']),
            $orders,
        ]);

        $match = $this->runner->match('get', ' /orders/42/lines/7/ ');

        self::assertSame($orders, $match['flow']);
        self::assertSame(['id' => '42', 'line_no' => '7'], $match['params']);
        self::assertSame(['method' => 'get', 'path' => '/orders/{id}/lines/{line_no}'], $match['config']);
        self::assertSame('e', $match['steps'][0]['id']);
        self::assertSame([], $match['links']);
    }

    public function testTheMostLiteralPatternWinsAndTiesGoToTheFirst(): void
    {
        $byId = self::flow('By id', ['path' => 'orders/{id}']);
        $latest = self::flow('Latest', ['method' => 'GET', 'path' => 'orders/latest']);
        $latestTwin = self::flow('Latest twin', ['method' => 'GET', 'path' => 'orders/latest']);
        $this->withFlows([$byId, $latest, $latestTwin]);

        self::assertSame($latest, $this->runner->match('GET', 'orders/latest')['flow']);
        self::assertSame($byId, $this->runner->match('GET', 'orders/9')['flow']);
    }

    public function testAnEmptyTriggerPathOrConfigNeverMatches(): void
    {
        $this->withFlows([self::flow('Unconfigured', null), self::flow('No path', ['method' => 'GET'])]);

        self::assertNull($this->runner->match('GET', 'anything'));
    }

    public function testAnEmptyRequestPathNeverQueries(): void
    {
        $this->flows->expects(self::never())->method('findBy');

        self::assertNull($this->runner->match('GET', '//'));
    }

    public function testRunSeedsTheRequestIntoTheExecutor(): void
    {
        $flow = self::flow('F', ['path' => 'x']);
        $match = ['flow' => $flow, 'steps' => [['id' => 'e']], 'links' => [['from' => 'e']], 'config' => [], 'params' => ['id' => '1']];
        $this->executor->expects(self::once())->method('execute')->with(
            [['id' => 'e']],
            [['from' => 'e']],
            ['params' => ['id' => '1'], 'body' => ['a' => 1], 'headers' => ['accept' => 'json'], 'queryParams' => ['q' => '2'], 'OAuthApplication' => 'app'],
            $flow,
            null,
            null,
            ['trigger' => 'endpoint']
        )->willReturn(['flowUuid' => 'u']);

        self::assertSame(
            ['flowUuid' => 'u'],
            $this->runner->run($match, ['a' => 1], ['accept' => 'json'], ['q' => '2'], ['OAuthApplication' => 'app'])
        );
    }

    public function testRunForwardsAnExplicitRunInfo(): void
    {
        $match = ['flow' => self::flow('F', []), 'steps' => [], 'links' => [], 'config' => [], 'params' => []];
        $this->executor->expects(self::once())->method('execute')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), null, null, ['trigger' => 'endpoint', 'user' => ['id' => 1]])
            ->willReturn([]);

        $this->runner->run($match, null, [], [], [], ['trigger' => 'endpoint', 'user' => ['id' => 1]]);
    }

    /**
     * @dataProvider responses
     */
    public function testRespondEvaluatesTheResponseBinding(array $config, ?array $expected): void
    {
        self::assertSame($expected, $this->runner->respond($config, ['payload' => ['id' => 5], 'code' => 201]));
    }

    public static function responses(): array
    {
        return [
            'no binding' => [[], null],
            'blank binding' => [['response' => '  '], null],
            'fixed' => [['response' => "{statusCode: 202, body: 'queued'}"], ['statusCode' => 202, 'body' => 'queued']],
            'variables' => [['response' => '{statusCode: code, body: payload}'], ['statusCode' => 201, 'body' => ['id' => 5]]],
            'defaults' => [['response' => '{other: 1}'], ['statusCode' => 200, 'body' => null]],
            'whole float status' => [['response' => '{statusCode: 204.0}'], ['statusCode' => 204, 'body' => null]],
        ];
    }

    /**
     * @dataProvider brokenResponses
     */
    public function testRespondRejectsUnusableBindings(string $binding, string $message): void
    {
        // A \RuntimeException subtype: the API controller's catch-all still maps it to flow_failed.
        $this->expectException(EndpointResponseFailure::class);
        $this->expectExceptionMessage($message);

        $this->runner->respond(['response' => $binding], []);
    }

    public static function brokenResponses(): array
    {
        return [
            'failing expression' => ['nope', "the response binding failed: Undefined variable: 'nope' at line 1"],
            'list' => ['[1]', 'the response binding must produce an object like {statusCode: 200, body: payload}, got array'],
            'scalar' => ['1', 'the response binding must produce an object like {statusCode: 200, body: payload}, got int'],
            'status too low' => ['{statusCode: 99}', 'the response statusCode must be an HTTP status between 100 and 599, got 99'],
            'status too high' => ['{statusCode: 600}', 'got 600'],
            'fractional status' => ['{statusCode: 200.5}', 'got 200.5'],
            'text status' => ["{statusCode: '200'}", "got '200'"],
            'structured status' => ['{statusCode: [200]}', 'got array'],
        ];
    }
}
