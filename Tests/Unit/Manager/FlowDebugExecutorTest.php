<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FlowDebugExecutor::execute() — the run lifecycle (gate, order, events, stamps, choices) and the
 * execution semantics of every step type.
 */
class FlowDebugExecutorTest extends FlowDebugExecutorTestCase
{
    // --- run lifecycle ---------------------------------------------------------------------------

    public function testAnInvalidFlowNeverRuns(): void
    {
        $validator = $this->createMock(\Aaxis\Bundle\OntologyBundle\Manager\FlowStepValidator::class);
        $validator->method('validate')->willReturn(['first problem', 'second']);
        $executor = new \Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor(
            $this->dataApi,
            $this->doctrine,
            new \Symfony\Component\HttpClient\MockHttpClient(),
            new \Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer(),
            $this->files,
            $validator,
            $this->database,
            $this->logger,
            $this->php,
            $this->emitter,
            $this->createMock(\Aaxis\Bundle\OntologyBundle\Manager\RestConnectorClient::class),
            $this->producer
        );
        $this->em->expects(self::never())->method('flush');

        try {
            $executor->execute([self::step('t', 'cron')], [], [], $this->savedFlow(1, 'F'));
            self::fail('Expected the run gate to refuse the flow.');
        } catch (\RuntimeException $e) {
            self::assertSame('first problem', $e->getMessage());
        }
        self::assertSame([], $this->events);
    }

    public function testAFlowWithoutATriggerCannotRun(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The flow has no trigger to debug.');

        $this->executor->execute([self::step('a', 'logger', ['message' => 'x'])], [], []);
    }

    public function testRunsTheReachableStepsBreadthFirstAndRecordsTheTrail(): void
    {
        $flow = $this->savedFlow(3, 'Orders');
        $this->em->expects(self::exactly(2))->method('persist')->with($flow);
        $this->em->expects(self::exactly(2))->method('flush');

        $context = $this->executor->execute(
            [
                self::step('d2', 'dwl_transform', ['code' => 'first.n + 1', 'destination' => 'second']),
                self::step('t', 'entity_change', ['enabled' => true], 'Orders'),
                self::step('d1', 'dwl_transform', ['code' => '{n: payload.n * 10}', 'destination' => 'first']),
                self::step('orphan', 'dwl_transform', ['code' => 'boom', 'destination' => 'never']),
            ],
            [self::link('d1', 'd2'), self::link('t', 'd1'), self::link('d1', 'missing'), self::link('d2', 't')],
            ['payload' => ['n' => 4]],
            $flow,
            null,
            null,
            ['trigger' => 'schedule', 'user' => ['id' => 1]]
        );

        self::assertSame(['flowUuid' => 'run-uuid', 'payload' => ['n' => 4], 'first' => ['n' => 40], 'second' => 41], $context);
        self::assertSame(['t', 'd1', 'd2'], $this->executor->lastExecutedIds());
        self::assertSame([
            ['flow-start', 'Orders', ['trigger' => 'schedule', 'user' => ['id' => 1]]],
            ['step', 'Orders', ['name' => 'Orders', 'type' => 'entity_change']],
            ['step', 'Orders', ['name' => 'd1', 'type' => 'dwl_transform']],
            ['step', 'Orders', ['name' => 'd2', 'type' => 'dwl_transform']],
            ['flow-finish', 'Orders', []],
        ], $this->events);
        self::assertNotNull($flow->getLastExecuted());
        self::assertNotNull($flow->getLastFinished());
    }

    public function testAnUnsavedFlowRunsWithoutStampsAndDefaultsToADebugTrigger(): void
    {
        $this->em->expects(self::never())->method('persist');

        $context = $this->executor->execute([self::step('t', 'cron')], [], ['payload' => 'ignored'], null, null, null, ['user' => 'not-an-array']);

        self::assertSame(['flowUuid' => 'run-uuid'], $context);
        self::assertSame(['flow-start', null, ['trigger' => 'debug']], $this->events[0]);
    }

    public function testAGivenExecutionUuidAndSeedContextAreUsed(): void
    {
        $this->dataApi->expects(self::never())->method('generateUuid');

        $context = $this->executor->execute(
            [self::step('t', 'subflow'), self::step('d', 'dwl_transform', ['code' => 'flowUuid', 'destination' => 'seen'])],
            [self::link('t', 'd')],
            [],
            null,
            'caller-uuid',
            ['flowUuid' => 'caller-uuid', 'kept' => 1]
        );

        self::assertSame(['flowUuid' => 'caller-uuid', 'kept' => 1, 'seen' => 'caller-uuid'], $context);
    }

    public function testEndpointTriggersSeeTheRequest(): void
    {
        $context = $this->executor->execute([self::step('t', 'endpoint')], [], [
            'params' => ['id' => '7', 5 => 'numeric'],
            'body' => ['a' => 1],
            'headers' => ['accept' => 'json'],
            'queryParams' => ['q' => 'x'],
            'OAuthApplication' => null,
            'payload' => 'not for endpoints',
        ]);

        self::assertSame([
            'flowUuid' => 'run-uuid',
            'id' => '7',
            '5' => 'numeric',
            'body' => ['a' => 1],
            'headers' => ['accept' => 'json'],
            'queryParams' => ['q' => 'x'],
            'OAuthApplication' => null,
        ], $context);
    }

    public function testAnEndpointRunWithoutRequestPartsSeesOnlyItsUuid(): void
    {
        self::assertSame(['flowUuid' => 'run-uuid'], $this->executor->execute([self::step('t', 'endpoint')], [], ['params' => 'x']));
    }

    public function testAFailingStepReportsItsIdAndTheTrailAndStillReleasesTheFlow(): void
    {
        $flow = $this->savedFlow(3, 'Orders');

        try {
            $this->executor->execute(
                [
                    self::step('t', 'cron'),
                    self::step('ok', 'dwl_transform', ['code' => '1', 'destination' => 'one']),
                    self::step('bad', 'dwl_transform', ['code' => 'nope', 'destination' => 'two'], 'Broken'),
                ],
                [self::link('t', 'ok'), self::link('ok', 'bad')],
                [],
                $flow
            );
            self::fail('Expected the step to fail.');
        } catch (FlowStepFailure $e) {
            self::assertSame("Step \"Broken\": Undefined variable: 'nope' at line 1", $e->getMessage());
            self::assertSame('bad', $e->stepId);
            self::assertSame(['t', 'ok'], $e->executedIds);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
        self::assertSame(['flow-start', 'step', 'step', 'flow-exception'], $this->eventKinds());
        self::assertSame(['message' => "Step \"Broken\": Undefined variable: 'nope' at line 1"], $this->events[3][2]);
        self::assertNotNull($flow->getLastFinished());
    }

    /**
     * @dataProvider nonRuntimeErrors
     */
    public function testANonRuntimeStepErrorStillNamesTheFailingStep(\Throwable $error, string $expected): void
    {
        $this->logger->method('info')->willThrowException($error);

        try {
            $this->executor->execute(
                [self::step('t', 'cron'), self::step('log', 'logger', ['message' => 'hi'], 'Log')],
                [self::link('t', 'log')],
                []
            );
            self::fail('Expected the step to fail.');
        } catch (FlowStepFailure $e) {
            self::assertSame($expected, $e->getMessage());
            self::assertSame('log', $e->stepId);
            self::assertSame(['t'], $e->executedIds);
            self::assertSame($error, $e->getPrevious());
        }
        self::assertSame(['flow-start', 'step', 'flow-exception'], $this->eventKinds());
        self::assertSame(['message' => $expected], $this->events[2][2]);
    }

    public static function nonRuntimeErrors(): array
    {
        return [
            'a type error' => [new \TypeError('bad argument'), 'Step "Log": bad argument'],
            'a JSON error' => [new \JsonException('Syntax error'), 'Step "Log": Syntax error'],
            'a PHP warning' => [new \ErrorException('Undefined array key "x"'), 'Step "Log": Undefined array key "x"'],
            // Runtime exceptions already name their step: kept as they are.
            'a runtime exception' => [new \RuntimeException('Step "Log": refused'), 'Step "Log": refused'],
        ];
    }

    /**
     * @dataProvider choices
     *
     * @param array<int, string> $expectedTrail
     */
    public function testAChoiceFollowsTheBranchItsVerdictPicks(string $expression, array $links, array $expectedTrail, bool $verdict): void
    {
        $context = $this->executor->execute(
            [
                self::step('t', 'cron'),
                self::step('c', 'choice', ['expression' => $expression]),
                self::step('yes', 'dwl_transform', ['code' => "'green'", 'destination' => 'branch']),
                self::step('no', 'dwl_transform', ['code' => "'red'", 'destination' => 'branch']),
            ],
            array_merge([self::link('t', 'c')], $links),
            []
        );

        self::assertSame($expectedTrail, $this->executor->lastExecutedIds());
        self::assertSame(['c' => $verdict], $context['choiceResults']);
    }

    public static function choices(): array
    {
        $both = [self::link('c', 'no', 1), self::link('c', 'yes', 0)];

        return [
            'truthy goes green' => ['1 == 1', $both, ['t', 'c', 'yes'], true],
            'falsy goes red' => ['[]', $both, ['t', 'c', 'no'], false],
            'a missing red link ends the flow' => ['false', [self::link('c', 'yes', 0)], ['t', 'c'], false],
        ];
    }

    public function testAChoiceNeedsAnExpression(): void
    {
        $this->expectException(FlowStepFailure::class);
        $this->expectExceptionMessage('Step "Step" is not configured.');

        $this->runStep('choice', ['expression' => '  ']);
    }

    public function testAChoiceExpressionFailureNamesTheStep(): void
    {
        $this->expectExceptionMessage('Step "Step": Division by zero');

        $this->runStep('choice', ['expression' => '1 / 0']);
    }

    public function testPlaceholderStepsAreNoOps(): void
    {
        self::assertSame(['flowUuid' => 'run-uuid', 'payload' => []], $this->runStep('email', null));
    }

    public function testExecutableStepsNeedAConfig(): void
    {
        $this->expectExceptionMessage('Step "Step" is not configured.');

        $this->runStep('dwl_transform', null);
    }

    public function testStepsWithAResultNeedADestination(): void
    {
        $this->expectExceptionMessage('Step "Step" has no destination.');

        $this->runStep('dwl_transform', ['code' => '1', 'destination' => ' ']);
    }

    public function testDwlTransformSeesTheWholeContext(): void
    {
        $context = $this->runStep('dwl_transform', ['code' => 'context.payload.items map ($ * 2)', 'destination' => 'out'], ['items' => [1, 2]]);

        self::assertSame([2, 4], $context['out']);
    }

    public function testAnEmptyTransformYieldsAParseError(): void
    {
        $this->expectExceptionMessage('Step "Step": Unexpected token EOF');

        $this->runStep('dwl_transform', ['destination' => 'out']);
    }

    // --- logger / event --------------------------------------------------------------------------

    /**
     * @dataProvider loggerMessages
     */
    public function testLoggerWritesOneLine(array $config, string $expected, ?OntologyFlow $flow = null): void
    {
        $this->logger->expects(self::once())->method('info')->with($expected);

        $this->runStep('logger', $config, ['id' => 5, 'tags' => ['a/b', 'é']], $flow);
    }

    public static function loggerMessages(): array
    {
        return [
            'plain text' => [['message' => ' Hello '], '[Aaxis Flow - unsaved] Hello'],
            'dwl text' => [['message' => "'id ' ++ payload.id", 'message_dwl' => true], '[Aaxis Flow - unsaved] id 5'],
            'dwl structure as json' => [['message' => 'payload.tags', 'message_dwl' => true], '[Aaxis Flow - unsaved] ["a/b","é"]'],
            'dwl number' => [['message' => 'payload.id', 'message_dwl' => true], '[Aaxis Flow - unsaved] 5'],
            'toggle must be a real true' => [['message' => 'payload.id', 'message_dwl' => 'true'], '[Aaxis Flow - unsaved] payload.id'],
        ];
    }

    public function testLoggerPrefixesTheFlowName(): void
    {
        $this->logger->expects(self::once())->method('info')->with('[Aaxis Flow - Orders] hi');

        $this->runStep('logger', ['message' => 'hi'], [], $this->savedFlow(2, 'Orders'));
    }

    public function testLoggerNeedsAMessage(): void
    {
        $this->expectExceptionMessage('Step "Step" is not configured.');

        $this->runStep('logger', ['message' => '']);
    }

    public function testLoggerDwlFailureNamesTheStep(): void
    {
        $this->expectExceptionMessage("Step \"Step\": Undefined variable: 'x'");

        $this->runStep('logger', ['message' => 'x', 'message_dwl' => true]);
    }

    public function testEventQueuesALogMessage(): void
    {
        $this->runStep('event', ['value' => 'payload', 'value_dwl' => true], ['k' => 'v']);
        $this->runStep('event', ['value' => ' plain ']);

        $messages = array_values(array_filter($this->events, static fn (array $e): bool => $e[0] === 'log-message'));
        self::assertSame([['message' => '{"k":"v"}'], ['message' => 'plain']], array_column($messages, 2));
    }

    public function testEventStringResultsGoVerbatim(): void
    {
        $this->runStep('event', ['value' => "'x/y'", 'value_dwl' => true]);

        self::assertContains(['log-message', null, ['message' => 'x/y']], $this->events);
    }

    public function testEventNeedsAValue(): void
    {
        $this->expectExceptionMessage('Step "Step" is not configured.');

        $this->runStep('event', []);
    }

    public function testEventDwlFailureNamesTheStep(): void
    {
        $this->expectExceptionMessage('Step "Step": Division by zero');

        $this->runStep('event', ['value' => '1/0', 'value_dwl' => true]);
    }

    // --- MS Teams --------------------------------------------------------------------------------

    /**
     * @dataProvider teamsMessages
     */
    public function testMsTeamsPostsAnAdaptiveCard(mixed $value, string $text): void
    {
        $this->responses[] = new MockResponse('', ['http_code' => 202]);

        $this->executor->execute(
            [self::step('t', 'cron'), self::step('d', 'dwl_transform', ['code' => 'v', 'destination' => 'msg']), self::step('m', 'ms_teams', ['webhook' => ' HTTPS://hooks.example/x ', 'message' => 'msg'])],
            [self::link('t', 'd'), self::link('d', 'm')],
            [],
            null,
            null,
            ['flowUuid' => 'u', 'v' => $value]
        );

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://hooks.example/x', $this->requests[0]['url']);
        $body = json_decode($this->requests[0]['options']['body'], true);
        self::assertSame('message', $body['type']);
        self::assertSame('application/vnd.microsoft.card.adaptive', $body['attachments'][0]['contentType']);
        self::assertSame([['type' => 'TextBlock', 'text' => $text, 'wrap' => true]], $body['attachments'][0]['content']['body']);
    }

    public static function teamsMessages(): array
    {
        return [
            'text' => ['hello', 'hello'],
            'null' => [null, 'null'],
            'boolean' => [false, 'false'],
            'number' => [1.5, '1.5'],
            'structure' => [['a' => 'b/c'], "{\n    \"a\": \"b/c\"\n}"],
        ];
    }

    /**
     * @dataProvider brokenTeamsSteps
     */
    public function testMsTeamsFailures(array $config, string $message, ?MockResponse $response = null): void
    {
        if ($response !== null) {
            $this->responses[] = $response;
        }
        $this->expectExceptionMessage($message);

        $this->runStep('ms_teams', $config, ['x' => 1]);
    }

    public static function brokenTeamsSteps(): array
    {
        return [
            'no webhook' => [['message' => 'payload'], 'Step "Step" is not configured.'],
            'no message' => [['webhook' => 'https://h'], 'Step "Step" is not configured.'],
            'plain http' => [['webhook' => 'http://h', 'message' => 'payload'], 'Step "Step" is not configured.'],
            'undefined variable' => [['webhook' => 'https://h', 'message' => 'nope'], 'Step "Step": the variable "nope" is not defined.'],
            'rejected' => [['webhook' => 'https://h', 'message' => 'payload'], 'Step "Step": the webhook responded HTTP 400.', new MockResponse('bad', ['http_code' => 400])],
            'transport error' => [['webhook' => 'https://h', 'message' => 'payload'], 'Step "Step": boom', new MockResponse('', ['error' => 'boom'])],
        ];
    }

    // --- entity read / write -----------------------------------------------------------------------

    public function testEntityReadAllIsUncappedUnlessConfigured(): void
    {
        $this->dataApi->expects(self::exactly(2))->method('queryForFlow')
            ->willReturnCallback(static fn (string $s, string $e, ?string $order, string $dir, ?int $limit): array => [[$s, $e, $order, $dir, $limit]]);

        $all = $this->runStep('entity_read', ['destination' => 'rows', 'system' => 'Erp', 'entity' => 'Order', 'limit' => '10', 'order_by' => ' ']);
        $ordered = $this->runStep('entity_read', ['destination' => 'rows', 'system' => 'Erp', 'entity' => 'Order', 'mode' => 'all', 'order_by' => ' total ', 'order_dir' => 'DESC', 'limit' => 100]);

        self::assertSame([['Erp', 'Order', null, 'ASC', null]], $all['rows']);
        self::assertSame([['Erp', 'Order', 'total', 'DESC', 100]], $ordered['rows']);
    }

    public function testEntityReadById(): void
    {
        $this->dataApi->expects(self::once())->method('read')->with('Erp', 'Order', '42')->willReturn(['id' => 42]);

        $context = $this->runStep('entity_read', ['destination' => 'order', 'system' => 'Erp', 'entity' => 'Order', 'mode' => 'by_id', 'record_id' => 42]);

        self::assertSame(['id' => 42], $context['order']);
    }

    public function testAMissingRecordReadsAsNull(): void
    {
        $this->dataApi->method('read')->willThrowException(OntologyApiException::recordNotFound('42'));

        $context = $this->runStep('entity_read', ['destination' => 'order', 'mode' => 'by_id']);

        self::assertArrayHasKey('order', $context);
        self::assertNull($context['order']);
    }

    public function testEntityReadByAttribute(): void
    {
        $this->dataApi->expects(self::once())->method('queryForFlowByAttribute')->with('Erp', 'Order', 'status', 'open')->willReturn([]);

        $context = $this->runStep('entity_read', ['destination' => 'open', 'system' => 'Erp', 'entity' => 'Order', 'mode' => 'by_attribute', 'attribute' => 'status', 'attr_value' => 'open']);

        self::assertSame([], $context['open']);
    }

    public function testEntityReadApiErrorsNameTheStep(): void
    {
        $this->dataApi->method('queryForFlow')->willThrowException(OntologyApiException::unknownSystem('Erp'));
        $this->expectExceptionMessage('Step "Step": Unknown system "Erp".');

        $this->runStep('entity_read', ['destination' => 'rows', 'system' => 'Erp']);
    }

    public function testEntityWriteUpsertsSynchronouslyUnderTheRunUuid(): void
    {
        $entity = $this->entity('Erp', 'Order');
        $flow = $this->savedFlow(8, 'Import');
        $this->dataApi->expects(self::once())->method('upsertRecordsSync')
            ->with($entity, [['id' => 1], ['id' => 2]], $flow, 'run-uuid')
            ->willReturn(['uuid' => 'run-uuid', 'seen' => ['1', '2'], 'changed' => ['2']]);

        $context = $this->runStep('entity_write', ['destination' => 'receipt', 'system' => 'Erp', 'entity' => 'Order', 'content' => 'payload'], [['id' => 1], ['id' => 2]], $flow);

        self::assertSame(['uuid' => 'run-uuid', 'count' => 2, 'upsert' => 1, 'changedIds' => ['2']], $context['receipt']);
    }

    public function testEntityWriteWithLogEventOffRecordsNoDataUpsert(): void
    {
        $entity = $this->entity('Erp', 'Order');
        $flow = $this->savedFlow(8, 'Import');
        $this->dataApi->expects(self::once())->method('upsertRecordsSync')
            ->with($entity, [['id' => 1]], $flow, 'run-uuid', false)
            ->willReturn(['uuid' => 'run-uuid', 'seen' => ['1'], 'changed' => ['1']]);

        $this->runStep('entity_write', ['destination' => 'receipt', 'system' => 'Erp', 'entity' => 'Order', 'content' => 'payload', 'log_event' => false], [['id' => 1]], $flow);
    }

    public function testEntityWriteOfOneObjectFromDwlStampsTheManualFlowWhenUnsaved(): void
    {
        $entity = $this->entity('Erp', 'Order');
        $manual = (new OntologyFlow())->setName(OntologyFlow::NAME_MANUAL);
        $this->dataApi->expects(self::once())->method('requireEnabledFlow')->with(OntologyFlow::NAME_MANUAL)->willReturn($manual);
        $this->dataApi->expects(self::once())->method('upsertRecordsSync')
            ->with($entity, [['id' => 9]], $manual, 'run-uuid')
            ->willReturn(['uuid' => 'run-uuid', 'seen' => ['9'], 'changed' => []]);

        $context = $this->runStep('entity_write', ['destination' => 'receipt', 'system' => 'Erp', 'entity' => 'Order', 'content' => '{id: 9}', 'content_dwl' => true]);

        self::assertSame(['uuid' => 'run-uuid', 'count' => 1, 'upsert' => 0, 'changedIds' => []], $context['receipt']);
    }

    /**
     * @dataProvider emptyContents
     */
    public function testNothingToWriteIsAnEmptyReceipt(string $code): void
    {
        $this->dataApi->expects(self::never())->method('upsertRecordsSync');

        $context = $this->runStep('entity_write', ['destination' => 'receipt', 'content' => $code, 'content_dwl' => true]);

        self::assertSame(['uuid' => 'run-uuid', 'count' => 0, 'upsert' => 0, 'changedIds' => []], $context['receipt']);
    }

    public static function emptyContents(): array
    {
        return ['null' => ['null'], 'empty list' => ['[]'], 'empty text' => ["''"]];
    }

    /**
     * @dataProvider brokenWrites
     */
    public function testEntityWriteFailures(array $config, string $message): void
    {
        $this->entity('Erp', 'Order');
        $this->dataApi->method('upsertRecordsSync')->willThrowException(OntologyApiException::invalidPayload('Missing id.'));
        $this->expectExceptionMessage($message);

        $this->runStep('entity_write', $config + ['destination' => 'r'], [['id' => 1]], $this->savedFlow(1, 'F'));
    }

    public static function brokenWrites(): array
    {
        return [
            'content not in context' => [['content' => 'nope'], 'Step "Step": content "nope" is not available in the context.'],
            'content dwl failure' => [['content' => 'nope', 'content_dwl' => true], "Step \"Step\": Undefined variable: 'nope'"],
            'scalar content' => [['content' => '5', 'content_dwl' => true], 'Step "Step": the content must resolve to an object or an array of objects.'],
            'unknown system' => [['content' => 'payload', 'system' => 'Crm', 'entity' => 'Order'], 'Step "Step": unknown entity "Order" in system "Crm".'],
            'unknown entity' => [['content' => 'payload', 'system' => 'Erp', 'entity' => 'Invoice'], 'Step "Step": unknown entity "Invoice" in system "Erp".'],
            'upsert rejected' => [['content' => 'payload', 'system' => 'Erp', 'entity' => 'Order'], 'Step "Step": Missing id.'],
        ];
    }

    // --- invoke PHP / SQL -----------------------------------------------------------------------

    public function testInvokePhpDelegatesToTheInvoker(): void
    {
        $config = ['destination' => 'r', 'class' => 'A', 'method' => 'b'];
        $this->php->expects(self::once())->method('invoke')
            ->with('Step', $config, self::callback(static fn (array $c): bool => $c['payload'] === ['x' => 1]))
            ->willReturn(['ok' => true]);

        self::assertSame(['ok' => true], $this->runStep('invoke_php', $config, ['x' => 1])['r']);
    }

    public function testSqlQueryRunsAgainstTheDatabaseConnector(): void
    {
        $connector = $this->connector(4, 'database');
        $this->database->expects(self::exactly(2))->method('run')
            ->willReturnCallback(static fn ($c, string $sql, mixed $bindings): array => [$c === $connector, $sql, $bindings]);

        $plain = $this->runStep('sql_query', ['destination' => 'q', 'connector' => '4', 'sql' => ' select 1 '], ['id' => 3]);
        $dwl = $this->runStep('sql_query', [
            'destination' => 'q', 'connector' => 4,
            'sql' => "'select ' ++ payload.id", 'sql_dwl' => true,
            'binding' => '{id: payload.id}', 'binding_dwl' => true,
        ], ['id' => 3]);

        self::assertSame([true, 'select 1', null], $plain['q']);
        self::assertSame([true, 'select 3', ['id' => 3]], $dwl['q']);
    }

    public function testSqlQueryBindingsFromAContextKey(): void
    {
        $this->connector(4, 'database');
        $this->database->method('run')->willReturnCallback(static fn ($c, string $sql, mixed $bindings): mixed => $bindings);

        self::assertSame(['id' => 3], $this->runStep('sql_query', ['destination' => 'q', 'connector' => 4, 'sql' => 's', 'binding' => 'payload'], ['id' => 3])['q']);
    }

    /**
     * @dataProvider brokenQueries
     */
    public function testSqlQueryFailures(array $config, string $message): void
    {
        $this->connector(4, 'database');
        $this->database->method('run')->willThrowException(new \RuntimeException('syntax error'));
        $this->expectExceptionMessage($message);

        $this->runStep('sql_query', $config + ['destination' => 'q']);
    }

    public static function brokenQueries(): array
    {
        return [
            'missing connector' => [['connector' => 99, 'sql' => 's'], 'Step "Step": the configured connector no longer exists.'],
            'empty dwl sql' => [['connector' => 4, 'sql' => "''", 'sql_dwl' => true], 'Step "Step": the SQL expression must produce a non-empty text, got string.'],
            'structured dwl sql' => [['connector' => 4, 'sql' => '[1]', 'sql_dwl' => true], 'Step "Step": the SQL expression must produce a non-empty text, got array.'],
            'failing dwl sql' => [['connector' => 4, 'sql' => 'x', 'sql_dwl' => true], "Step \"Step\": Undefined variable: 'x'"],
            'driver error' => [['connector' => 4, 'sql' => 's'], 'Step "Step": syntax error.'],
        ];
    }

    // --- file operations -----------------------------------------------------------------------

    public function testFileOperationsGoThroughTheTransfer(): void
    {
        $connector = $this->connector(5, 'sftp');
        $this->files->expects(self::exactly(2))->method('read')->with($connector, '/in/a.csv')->willReturn('csv');
        $this->files->expects(self::exactly(2))->method('write')
            ->willReturnCallback(static fn ($c, string $path, string $content): array => ['path' => $path, 'content' => $content]);
        $this->files->expects(self::once())->method('delete')->with($connector, '/in/a.csv')->willReturn(['deleted' => true]);
        $this->files->expects(self::once())->method('rename')->with($connector, '/in/a.csv', 'b-7.csv')->willReturn(['renamed' => true]);
        $base = ['destination' => 'f', 'connector' => 5, 'path' => ' /in/a.csv '];

        self::assertSame('csv', $this->runStep('file_read', $base)['f']);
        self::assertSame('csv', $this->runStep('file_list', ['path' => "'/in/' ++ payload.name", 'path_dwl' => true] + $base, ['name' => 'a.csv'])['f']);
        self::assertSame(['path' => '/in/a.csv', 'content' => '{"k":"v\\/w"}'], $this->runStep('file_write', $base + ['content' => 'payload'], ['k' => 'v/w'])['f']);
        self::assertSame(['path' => '/in/a.csv', 'content' => 'text'], $this->runStep('file_write', $base + ['content' => "'text'", 'content_dwl' => true])['f']);
        self::assertSame(['deleted' => true], $this->runStep('file_delete', $base)['f']);
        self::assertSame(['renamed' => true], $this->runStep('file_rename', $base + ['new_name' => "'b-' ++ payload.n ++ '.csv'", 'new_name_dwl' => true], ['n' => 7])['f']);
    }

    /**
     * @dataProvider brokenFileSteps
     */
    public function testFileOperationFailures(string $type, array $config, string $message): void
    {
        $this->connector(5, 'bucket');
        $this->connector(6, 'rest_api');
        $this->files->method('read')->willThrowException(new \RuntimeException('no such file'));
        $this->expectExceptionMessage($message);

        $this->runStep($type, $config + ['destination' => 'f'], ['n' => 1]);
    }

    public static function brokenFileSteps(): array
    {
        return [
            'missing connector' => ['file_read', ['connector' => 99, 'path' => 'x'], 'Step "Step": the configured connector no longer exists.'],
            'not file-based' => ['file_read', ['connector' => 6, 'path' => 'x'], 'Step "Step": a "rest_api" connector is not file-based.'],
            'blank dwl path' => ['file_read', ['connector' => 5, 'path' => "' '", 'path_dwl' => true], 'Step "Step": the path expression must produce a non-empty text, got string.'],
            'structured dwl path' => ['file_read', ['connector' => 5, 'path' => 'payload', 'path_dwl' => true], 'Step "Step": the path expression must produce a non-empty text, got array.'],
            'transfer failure' => ['file_read', ['connector' => 5, 'path' => 'x'], 'Step "Step": no such file.'],
            'failing new name' => ['file_rename', ['connector' => 5, 'path' => 'x', 'new_name' => 'nope', 'new_name_dwl' => true], "Undefined variable: 'nope'"],
        ];
    }

    // --- HTTP request ---------------------------------------------------------------------------

    public function testHttpRequestAgainstARestConnector(): void
    {
        $this->connector(7, 'rest_api', [
            'server' => 'api.example.com/',
            'port' => 8443,
            'headers' => ['X-Tenant' => 't1', '' => 'dropped', 'Bad' => ['x']],
            'auth' => 'headers',
            'auth_headers' => ['X-Key' => 123],
        ]);
        $this->responses[] = new MockResponse('{"ok":true}', ['http_code' => 201]);

        $context = $this->runStep('invoke', [
            'destination' => 'resp', 'connector' => 7, 'path' => '/orders',
            'operation' => 'post', 'body' => 'json', 'body_content' => '{id: payload.id}', 'body_dwl' => true,
        ], ['id' => 3]);

        self::assertSame(['ok' => true], $context['resp']);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.example.com:8443/orders', $request['url']);
        self::assertSame('{"id":3}', $request['options']['body']);
        self::assertContains('X-Tenant: t1', $request['options']['headers']);
        self::assertContains('X-Key: 123', $request['options']['headers']);
        self::assertContains('Content-Type: application/json', $request['options']['headers']);
    }

    public function testHttpRequestPathFromADwlExpression(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api']);
        $this->responses[] = new MockResponse('{}');

        $this->runStep('invoke', [
            'destination' => 'resp', 'connector' => 7, 'path' => '"orders/" ++ payload.id ++ "?full=1"', 'path_dwl' => true,
        ], ['id' => 42]);

        self::assertSame('https://api/orders/42?full=1', $this->requests[0]['url']);
    }

    public function testAHttpRequestPathExpressionMustYieldText(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Step "Step": the path expression must produce a non-empty text, got array.');

        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'payload', 'path_dwl' => true], ['id' => 1]);
    }

    /**
     * @dataProvider requestBodies
     */
    public function testHttpRequestBodies(array $step, ?string $contentType, ?string $body): void
    {
        $this->connector(7, 'rest_api', ['server' => 'http://h:81']);
        $this->responses[] = new MockResponse('plain text');

        $context = $this->runStep('invoke', $step + ['destination' => 'resp', 'connector' => 7, 'path' => 'x'], ['v' => 'é']);

        self::assertSame('plain text', $context['resp']);
        self::assertSame('http://h:81/x', $this->requests[0]['url']);
        $sentType = array_values(array_filter($this->requests[0]['options']['headers'], static fn (string $h): bool => str_starts_with($h, 'Content-Type')));
        self::assertSame($contentType === null ? [] : ['Content-Type: ' . $contentType], $sentType);
        self::assertSame($body ?? '', $this->requests[0]['options']['body'] ?? '');
    }

    public static function requestBodies(): array
    {
        return [
            'no body' => [[], null, null],
            'empty body type' => [['body' => 'empty', 'body_content' => 'x'], null, null],
            'blank content' => [['body' => 'json', 'body_content' => ''], null, null],
            'xml' => [['body' => 'xml', 'body_content' => '<a/>'], 'application/xml', '<a/>'],
            'text' => [['body' => 'text', 'body_content' => 'hi'], 'text/plain', 'hi'],
            'dwl text result' => [['body' => 'text', 'body_content' => 'payload.v', 'body_dwl' => true], 'text/plain', 'é'],
        ];
    }

    public function testHttpRequestWithOAuth(): void
    {
        $this->connector(7, 'rest_api', [
            'server' => 'https://api.example.com',
            'auth' => 'oauth',
            'oauth' => ['path' => '/token', 'headers' => ['X-A' => 'b'], 'body' => ['grant_type' => 'client_credentials']],
        ]);
        $this->responses[] = new MockResponse('{"access_token":"abc","token_type":"MAC"}');
        $this->responses[] = new MockResponse('[1,2]');

        $context = $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'data', 'operation' => '']);

        self::assertSame([1, 2], $context['resp']);
        self::assertSame(['POST', 'https://api.example.com/token'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame('grant_type=client_credentials', $this->requests[0]['options']['body']);
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertContains('Authorization: MAC abc', $this->requests[1]['options']['headers']);
    }

    public function testOAuthTokenTypeDefaultsToBearer(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'h', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $this->responses[] = new MockResponse('{"access_token":"abc"}');
        $this->responses[] = new MockResponse('{}');

        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);

        self::assertContains('Authorization: Bearer abc', $this->requests[1]['options']['headers']);
    }

    /** Runs a trigger followed by two HTTP Request steps against connector 7. */
    private function runTwoOAuthCalls(): array
    {
        $call = static fn (string $path): array => ['destination' => $path, 'connector' => 7, 'path' => $path];

        return $this->executor->execute(
            [self::step('t', 'entity_change'), self::step('a', 'invoke', $call('one'), 'A'), self::step('b', 'invoke', $call('two'), 'B')],
            [self::link('t', 'a'), self::link('a', 'b')],
            ['payload' => []]
        );
    }

    /** @return list<string> "METHOD url" per request sent, in order */
    private function sentRequests(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['url'], $this->requests);
    }

    public function testOAuthTokenIsReusedAcrossCallsAndRuns(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $this->responses = [
            new MockResponse('{"access_token":"abc","expires_in":3600}'),
            new MockResponse('[1]'),
            new MockResponse('[2]'),
            new MockResponse('[3]'),
            new MockResponse('[4]'),
        ];

        $first = $this->runTwoOAuthCalls();
        $second = $this->runTwoOAuthCalls();

        self::assertSame([[1], [2], [3], [4]], [$first['one'], $first['two'], $second['one'], $second['two']]);
        self::assertSame(
            ['POST https://api/token', 'GET https://api/one', 'GET https://api/two', 'GET https://api/one', 'GET https://api/two'],
            $this->sentRequests()
        );
        foreach (\array_slice($this->requests, 1) as $request) {
            self::assertContains('Authorization: Bearer abc', $request['options']['headers']);
        }
    }

    public function testOAuthTokenWithoutLifetimeIsOnlyReusedWithinTheRun(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $this->responses = [new MockResponse('{"access_token":"abc"}'), new MockResponse('[1]'), new MockResponse('[2]')];

        $this->runTwoOAuthCalls();

        self::assertSame(['POST https://api/token', 'GET https://api/one', 'GET https://api/two'], $this->sentRequests());
        self::assertFalse($this->oauthCache->hasItem('aaxis_ontology_oauth_token.7'));
    }

    public function testRejectedReusedTokenIsRefreshedAndTheCallRetriedOnce(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $this->responses = [new MockResponse('{"access_token":"old","expires_in":3600}'), new MockResponse('[1]')];
        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);

        $this->requests = [];
        $this->responses = [
            new MockResponse('expired', ['http_code' => 401]),
            new MockResponse('{"access_token":"new","expires_in":3600}'),
            new MockResponse('[2]'),
        ];
        $context = $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);

        self::assertSame([2], $context['resp']);
        self::assertSame(['GET https://api/x', 'POST https://api/token', 'GET https://api/x'], $this->sentRequests());
        self::assertContains('Authorization: Bearer old', $this->requests[0]['options']['headers']);
        self::assertContains('Authorization: Bearer new', $this->requests[2]['options']['headers']);
    }

    public function testRejectedFreshTokenFailsWithoutRetrying(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $this->responses = [new MockResponse('{"access_token":"abc","expires_in":3600}'), new MockResponse('no', ['http_code' => 401])];

        try {
            $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);
            self::fail('Expected a failure.');
        } catch (FlowStepFailure $e) {
            self::assertSame('Step "Step": the connector responded HTTP 401.', $e->getMessage());
        }
        self::assertSame(['POST https://api/token', 'GET https://api/x'], $this->sentRequests());
    }

    public function testEditingTheConnectorRetiresItsToken(): void
    {
        $connector = $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token', 'body' => ['client_secret' => 'a']]]);
        $this->responses = [new MockResponse('{"access_token":"abc","expires_in":3600}'), new MockResponse('[1]')];
        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);

        $connector->setConfig(['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token', 'body' => ['client_secret' => 'b']]]);
        $this->responses = [new MockResponse('{"access_token":"def","expires_in":3600}'), new MockResponse('[2]')];
        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);

        self::assertSame(
            ['POST https://api/token', 'GET https://api/x', 'POST https://api/token', 'GET https://api/x'],
            $this->sentRequests()
        );
        self::assertContains('Authorization: Bearer def', $this->requests[3]['options']['headers']);
    }

    /**
     * @dataProvider brokenHttpRequests
     *
     * @param array<int, MockResponse> $responses
     */
    public function testHttpRequestFailures(?array $connectorConfig, array $responses, string $message, string $type = 'rest_api', array $step = []): void
    {
        $this->connector(7, $type, $connectorConfig);
        $this->responses = $responses;
        $this->files->method('read')->willThrowException(new \RuntimeException('unreachable'));
        $this->expectExceptionMessage($message);

        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x'] + $step);
    }

    public static function brokenHttpRequests(): array
    {
        $oauth = static fn (array $oauth): array => ['server' => 'h', 'auth' => 'oauth', 'oauth' => $oauth];

        return [
            'no server' => [null, [], 'Step "Step": the connector has no server configured.'],
            'no token path' => [$oauth([]), [], 'Step "Step": the connector has no OAuth token path configured.'],
            'token request fails' => [$oauth(['path' => 't']), [new MockResponse('', ['error' => 'refused'])], 'Step "Step": OAuth token request failed — refused'],
            'no access token' => [$oauth(['path' => 't']), [new MockResponse('{"token_type":"x"}')], 'Step "Step": the OAuth response carries no access_token.'],
            'non-json token' => [$oauth(['path' => 't']), [new MockResponse('nope')], 'Step "Step": the OAuth response carries no access_token.'],
            'body dwl fails' => [['server' => 'h'], [], "Step \"Step\": Undefined variable: 'nope'", 'rest_api', ['body' => 'json', 'body_content' => 'nope', 'body_dwl' => true]],
            'file connector failure' => [null, [], 'Step "Step": unreachable.', 'file_system'],
        ];
    }

    public function testHttpRequestStatusAndTransportFailures(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'h']);
        $this->responses[] = new MockResponse('down', ['http_code' => 503]);

        try {
            $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);
            self::fail('Expected a failure.');
        } catch (FlowStepFailure $e) {
            self::assertSame('Step "Step": the connector responded HTTP 503.', $e->getMessage());
        }

        $this->responses[] = new MockResponse('', ['error' => 'timeout']);
        $this->expectExceptionMessage('Step "Step": timeout');

        $this->runStep('invoke', ['destination' => 'resp', 'connector' => 7, 'path' => 'x']);
    }

    public function testHttpRequestOnOtherConnectors(): void
    {
        $this->connector(7, 'file_system');
        $this->connector(8, 'database');
        $this->files->method('read')->willReturn(['a.txt']);

        self::assertSame(['a.txt'], $this->runStep('invoke', ['destination' => 'r', 'connector' => 7, 'path' => '/'])['r']);
        self::assertSame(
            ['_debug' => '"database" connectors are not executed in debug yet.'],
            $this->runStep('invoke', ['destination' => 'r', 'connector' => 8])['r']
        );
    }

    public function testHttpRequestNeedsAnExistingConnector(): void
    {
        $this->expectExceptionMessage('Step "Step": the configured connector no longer exists.');

        $this->runStep('invoke', ['destination' => 'r', 'connector' => 1]);
    }

    // --- async HTTP request (fire and forget) ---------------------------------------------------

    public function testAsyncHttpRequestIsQueuedAndKeepsNothing(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $flow = $this->savedFlow(4, 'Batch');

        $context = $this->runStep('invoke', [
            'async' => true, 'destination' => 'leftover', 'connector' => 7, 'path' => '/orders',
            'operation' => 'post', 'body' => 'json', 'body_content' => '{id: payload.id}', 'body_dwl' => true,
        ], ['id' => 3], $flow);

        // Nothing is sent inline (not even the OAuth token request) and nothing is stored — a
        // destination left in the config is ignored.
        self::assertSame([], $this->requests);
        self::assertSame(['flowUuid', 'payload'], array_keys($context));
        self::assertSame([[
            'aaxis_ontology_http_request',
            [
                'connector_id' => 7, 'method' => 'POST', 'path' => '/orders', 'body' => '{"id":3}', 'body_type' => 'json',
                'step' => 'Step', 'flow_id' => 4, 'flow_name' => 'Batch', 'flow_uuid' => 'run-uuid',
            ],
        ]], $this->queued);
        self::assertSame(['flow-start', 'step', 'step', 'flow-finish'], $this->eventKinds());
    }

    public function testAsyncHttpRequestQueuesTheRenderedPath(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'h']);

        $this->runStep('invoke', ['async' => true, 'connector' => 7, 'path' => '"orders/" ++ payload.id', 'path_dwl' => true], ['id' => 9]);

        self::assertSame('orders/9', $this->queued[0][1]['path']);
    }

    public function testAsyncHttpRequestWithoutBodyQueuesNone(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'h']);

        $this->runStep('invoke', ['async' => true, 'connector' => 7, 'path' => 'x', 'body' => 'json', 'body_content' => '']);

        self::assertSame(
            ['connector_id' => 7, 'method' => 'GET', 'path' => 'x', 'body' => null, 'body_type' => 'json', 'step' => 'Step', 'flow_id' => null, 'flow_name' => null, 'flow_uuid' => 'run-uuid'],
            $this->queued[0][1]
        );
    }

    /**
     * A broken definition fails the run BEFORE anything is queued — not once per queued call.
     *
     * @dataProvider brokenAsyncRequests
     */
    public function testAsyncHttpRequestFailuresAreRaisedBeforeQueueing(string $type, ?array $connectorConfig, array $step, string $message): void
    {
        $this->connector(7, $type, $connectorConfig);

        try {
            $this->runStep('invoke', $step + ['async' => true, 'connector' => 7, 'path' => 'x']);
            self::fail('Expected a failure.');
        } catch (FlowStepFailure $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }
        self::assertSame([], $this->queued);
        self::assertSame([], $this->requests);
    }

    public static function brokenAsyncRequests(): array
    {
        return [
            'missing connector' => ['rest_api', ['server' => 'h'], ['connector' => 99], 'Step "Step": the configured connector no longer exists.'],
            'not rest_api' => ['file_system', null, [], 'Step "Step": async requests need a rest_api connector, not "file_system".'],
            'no server' => ['rest_api', null, [], 'Step "Step": the connector has no server configured.'],
            'body dwl fails' => ['rest_api', ['server' => 'h'], ['body' => 'json', 'body_content' => 'nope', 'body_dwl' => true], "Step \"Step\": Undefined variable: 'nope'"],
        ];
    }

    public function testAsyncHttpRequestThatCannotBeQueuedFailsTheStep(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'h']);
        $this->queueFailure = new \RuntimeException('broker down');
        $this->expectException(FlowStepFailure::class);
        $this->expectExceptionMessage('Step "Step": the request could not be queued — broker down');

        $this->runStep('invoke', ['async' => true, 'connector' => 7, 'path' => 'x']);
    }

    // --- subflows ------------------------------------------------------------------------------

    public function testCallSubflowRunsOverTheCallersContext(): void
    {
        $this->subflow(20, 'Enrich', [
            ['id' => 'st', 'type' => 'subflow', 'name' => 'Enrich', 'x' => 1],
            ['id' => 'sd', 'type' => 'dwl_transform', 'name' => 'Add', 'config' => ['code' => 'payload.n + 1', 'destination' => 'enriched']],
        ], [self::link('st', 'sd')]);
        $caller = $this->savedFlow(1, 'Main');

        $context = $this->executor->execute(
            [self::step('t', 'entity_change'), self::step('call', 'sub_flow', ['subflow' => '20'], 'Call'), self::step('after', 'dwl_transform', ['code' => 'enriched * 2', 'destination' => 'final'])],
            [self::link('t', 'call'), self::link('call', 'after')],
            ['payload' => ['n' => 1]],
            $caller
        );

        self::assertSame(['flowUuid' => 'run-uuid', 'payload' => ['n' => 1], 'enriched' => 2, 'final' => 4], $context);
        self::assertSame(['t', 'call', 'after'], $this->executor->lastExecutedIds());
        self::assertSame([['flowId' => 20, 'flowName' => 'Enrich', 'executedIds' => ['st', 'sd']]], $this->executor->subflowTrails());
        self::assertSame(
            ['flow-start', 'step', 'subflow-start', 'step', 'step', 'subflow-finish', 'step', 'step', 'flow-finish'],
            $this->eventKinds()
        );
        self::assertSame(['subflow-start', 'Enrich', ['trigger' => 'subflow']], $this->events[2]);
    }

    public function testAStepWithLogEventOffLeavesNoStepRow(): void
    {
        $this->executor->execute(
            [
                self::step('t', 'cron', null, 'Main'),
                self::step('a', 'dwl_transform', ['code' => '1', 'destination' => 'x', 'log_event' => false], 'Quiet'),
                self::step('b', 'dwl_transform', ['code' => '2', 'destination' => 'y', 'log_event' => true], 'Loud'),
            ],
            [self::link('t', 'a'), self::link('a', 'b')],
            []
        );

        self::assertSame(['flow-start', 'step', 'step', 'flow-finish'], $this->eventKinds());
        self::assertSame(['Main', 'Loud'], array_map(static fn (array $e): string => $e[2]['name'], \array_slice($this->events, 1, 2)));
        self::assertSame(['t', 'a', 'b'], $this->executor->lastExecutedIds());
    }

    public function testAQuietCallSubflowDropsItsBoundariesButNotTheSubflowsOwnSteps(): void
    {
        $this->subflow(20, 'Enrich', [
            ['id' => 'st', 'type' => 'subflow', 'name' => 'Enrich'],
            ['id' => 'sd', 'type' => 'dwl_transform', 'name' => 'Add', 'config' => ['code' => 'payload.n + 1', 'destination' => 'enriched']],
        ], [self::link('st', 'sd')]);

        $context = $this->executor->execute(
            [self::step('t', 'entity_change', null, 'Main'), self::step('call', 'sub_flow', ['subflow' => 20, 'log_event' => false], 'Call')],
            [self::link('t', 'call')],
            ['payload' => ['n' => 1]],
            $this->savedFlow(1, 'Main')
        );

        self::assertSame(2, $context['enriched']);
        self::assertSame(['flow-start', 'step', 'step', 'flow-finish'], $this->eventKinds());
        self::assertSame(['step', 'Enrich', ['name' => 'Add', 'type' => 'dwl_transform']], $this->events[2]);
    }

    public function testAQuietForeachStillRecordsAFailure(): void
    {
        $this->subflow(21, 'Per item', [
            ['id' => 'st', 'type' => 'subflow', 'name' => 'Entry'],
            ['id' => 'bad', 'type' => 'dwl_transform', 'name' => 'Bad', 'config' => ['code' => 'nope(', 'destination' => 'x', 'log_event' => false]],
        ], [self::link('st', 'bad')]);

        try {
            $this->executor->execute(
                [self::step('t', 'cron'), self::step('loop', 'foreach', ['subflow' => 21, 'array' => 'items', 'item' => 'item', 'log_event' => false])],
                [self::link('t', 'loop')],
                [],
                null,
                null,
                ['flowUuid' => 'u', 'items' => [1]]
            );
            self::fail('The failing iteration must abort the run.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['flow-start', 'step', 'flow-exception', 'flow-exception'], $this->eventKinds());
    }

    public function testForeachRunsTheSubflowPerElementWithLoopScopedVariables(): void
    {
        $this->subflow(21, 'Per item', [
            ['id' => 'st', 'type' => 'subflow', 'name' => 'Entry'],
            ['id' => 'acc', 'type' => 'dwl_transform', 'name' => 'Acc', 'config' => ['code' => 'total + item * (index + 1)', 'destination' => 'total']],
        ], [self::link('st', 'acc')]);

        $context = $this->executor->execute(
            [self::step('t', 'cron'), self::step('init', 'dwl_transform', ['code' => '0', 'destination' => 'total']), self::step('loop', 'foreach', ['subflow' => 21, 'array' => ' items ', 'item' => 'item'])],
            [self::link('t', 'init'), self::link('init', 'loop')],
            [],
            null,
            null,
            ['flowUuid' => 'u', 'items' => [5, 7], 'index' => 'outer']
        );

        self::assertSame(['flowUuid' => 'u', 'items' => [5, 7], 'index' => 'outer', 'total' => 19], $context);
        self::assertSame([['flowId' => 21, 'flowName' => 'Per item', 'executedIds' => ['st', 'acc']]], $this->executor->subflowTrails());
    }

    public function testForeachRestoresAShadowedItemAndSkipsEmptyLists(): void
    {
        $this->subflow(21, 'Per item', [['id' => 'st', 'type' => 'subflow', 'name' => 'Entry']]);

        $context = $this->executor->execute(
            [self::step('t', 'cron'), self::step('loop', 'foreach', ['subflow' => 21, 'array' => 'items', 'item' => 'item'])],
            [self::link('t', 'loop')],
            [],
            null,
            null,
            ['flowUuid' => 'u', 'items' => [], 'item' => 'kept']
        );

        self::assertSame(['flowUuid' => 'u', 'items' => [], 'item' => 'kept'], $context);
        self::assertSame([], $this->executor->subflowTrails());
    }

    /**
     * @dataProvider brokenSubflowCalls
     */
    public function testSubflowFailures(string $type, array $config, string $message, array $seed = []): void
    {
        $this->savedFlow(30, 'Regular', OntologyFlow::TYPE_FLOW);
        $this->subflow(31, 'Disabled', [['id' => 'x', 'type' => 'subflow']])->setEnabled(false);
        $this->savedFlow(32, 'Empty', OntologyFlow::TYPE_SUBFLOW);
        $this->subflow(33, 'Failing', [['id' => 'st', 'type' => 'subflow', 'name' => 'Entry'], ['id' => 'bad', 'type' => 'dwl_transform', 'name' => 'Bad', 'config' => ['code' => 'nope', 'destination' => 'x']]], [self::link('st', 'bad')]);
        $this->expectException(FlowStepFailure::class);
        $this->expectExceptionMessage($message);

        $this->executor->execute(
            [self::step('t', 'cron'), self::step('s', $type, $config, 'Call')],
            [self::link('t', 's')],
            [],
            null,
            null,
            ['flowUuid' => 'u', 'rows' => [1, 2], 'map' => ['a' => 1]] + $seed
        );
    }

    public static function brokenSubflowCalls(): array
    {
        return [
            'no target' => ['sub_flow', ['subflow' => ''], 'Step "Call": the configured subflow no longer exists.'],
            'missing target' => ['sub_flow', ['subflow' => 99], 'Step "Call": the configured subflow no longer exists.'],
            'regular flow' => ['sub_flow', ['subflow' => 30], 'Step "Call": "Regular" is not a subflow (its trigger makes it a regular flow).'],
            'disabled' => ['sub_flow', ['subflow' => 31], 'Step "Call": the subflow "Disabled" is disabled.'],
            'no steps' => ['sub_flow', ['subflow' => 32], 'Step "Call": the subflow "Empty" has no steps.'],
            'failing subflow' => ['sub_flow', ['subflow' => 33], "Step \"Call\": Step \"Bad\": Undefined variable: 'nope' at line 1"],
            'foreach unconfigured' => ['foreach', ['subflow' => 33, 'array' => 'rows'], 'Step "Call" is not configured.'],
            'foreach undefined array' => ['foreach', ['subflow' => 33, 'array' => 'ghost', 'item' => 'i'], 'Step "Call": the variable "ghost" is not defined.'],
            'foreach over an object' => ['foreach', ['subflow' => 33, 'array' => 'map', 'item' => 'i'], 'Step "Call": the variable "map" must hold an array, got array.'],
            'foreach over a scalar' => ['foreach', ['subflow' => 33, 'array' => 'flowUuid', 'item' => 'i'], 'Step "Call": the variable "flowUuid" must hold an array, got string.'],
            'foreach iteration fails' => ['foreach', ['subflow' => 33, 'array' => 'rows', 'item' => 'i'], "Step \"Call\" (iteration 0): Step \"Bad\": Undefined variable: 'nope' at line 1"],
        ];
    }

    public function testACircularSubflowCallIsRefused(): void
    {
        $this->subflow(40, 'Loop', [
            ['id' => 'st', 'type' => 'subflow', 'name' => 'Entry'],
            ['id' => 'again', 'type' => 'sub_flow', 'name' => 'Again', 'config' => ['subflow' => 40]],
        ], [self::link('st', 'again')]);
        $this->expectExceptionMessage('Step "Call": Step "Again": circular subflow call — "Loop" is already running in this execution.');

        $this->executor->execute([self::step('t', 'cron'), self::step('s', 'sub_flow', ['subflow' => 40], 'Call')], [self::link('t', 's')], []);
    }

    public function testSubflowNestingIsCappedAtTenLevels(): void
    {
        for ($id = 50; $id <= 61; $id++) {
            $this->subflow($id, 'Level ' . $id, [
                ['id' => 'st', 'type' => 'subflow', 'name' => 'Entry'],
                ['id' => 'down', 'type' => 'sub_flow', 'name' => 'Down', 'config' => ['subflow' => $id + 1]],
            ], [self::link('st', 'down')]);
        }

        try {
            $this->executor->execute([self::step('t', 'cron'), self::step('s', 'sub_flow', ['subflow' => 50], 'Call')], [self::link('t', 's')], []);
            self::fail('Expected the depth guard.');
        } catch (FlowStepFailure $e) {
            self::assertStringEndsWith('Step "Down": subflow calls nested deeper than 10 levels.', $e->getMessage());
        }
    }
}
