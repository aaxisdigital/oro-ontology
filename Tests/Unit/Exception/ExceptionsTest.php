<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Exception;

use Aaxis\Bundle\OntologyBundle\Exception\BucketRequestFailure;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;
use Aaxis\Bundle\OntologyBundle\Exception\DatabaseQueryFailure;
use Aaxis\Bundle\OntologyBundle\Exception\EndpointResponseFailure;
use Aaxis\Bundle\OntologyBundle\Exception\FlowExecutionFailure;
use Aaxis\Bundle\OntologyBundle\Exception\FlowImportException;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Exception\PhpInvocationFailure;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    /**
     * The dedicated exceptions replace bare \RuntimeExceptions, so every existing
     * `catch (\RuntimeException)` must keep catching them.
     *
     * @dataProvider runtimeExceptionProvider
     *
     * @param class-string<\RuntimeException> $class
     */
    public function testDedicatedExceptionsStillExtendRuntimeException(string $class): void
    {
        $exception = new $class('the message');

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('the message', $exception->getMessage());
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function runtimeExceptionProvider(): array
    {
        return [
            'bucket request' => [BucketRequestFailure::class],
            'connector configuration' => [ConnectorConfigurationException::class],
            'database query' => [DatabaseQueryFailure::class],
            'connector transfer' => [ConnectorTransferFailure::class],
            'endpoint response' => [EndpointResponseFailure::class],
            'flow execution' => [FlowExecutionFailure::class],
            'php invocation' => [PhpInvocationFailure::class],
        ];
    }

    public function testConfigurationFailuresAreNotTransferFailures(): void
    {
        // FileConnectorTransfer turns transfer failures into payloads and lets configuration ones abort the step.
        self::assertNotInstanceOf(ConnectorTransferFailure::class, new ConnectorConfigurationException('x'));
        self::assertNotInstanceOf(ConnectorConfigurationException::class, new ConnectorTransferFailure('x'));
    }

    public function testTheTransferFailureTakesTheCauseAsItsSecondArgument(): void
    {
        $cause = new \LogicException('root');

        $failure = new ConnectorTransferFailure('outer', $cause);

        self::assertSame($cause, $failure->getPrevious());
        self::assertSame(0, $failure->getCode());
        self::assertNull((new ConnectorTransferFailure('alone'))->getPrevious());
    }

    public function testTheFlowImportExceptionCarriesEveryError(): void
    {
        $exception = new FlowImportException(['Duplicate name.', 'Unknown connector "X".']);

        self::assertSame(['Duplicate name.', 'Unknown connector "X".'], $exception->getErrors());
        self::assertSame('Duplicate name. Unknown connector "X".', $exception->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testTheFlowStepFailureAddsAddressingToTheWrappedError(): void
    {
        $cause = new \RuntimeException('boom');

        $failure = new FlowStepFailure('Step "x": boom', 's3', ['s1', 's2'], $cause);

        self::assertSame('Step "x": boom', $failure->getMessage());
        self::assertSame('s3', $failure->stepId);
        self::assertSame(['s1', 's2'], $failure->executedIds);
        self::assertSame($cause, $failure->getPrevious());
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertNull((new FlowStepFailure('m', null, []))->stepId);
    }

    /**
     * @dataProvider apiErrorProvider
     */
    public function testTheApiExceptionFactoriesCarryStatusAndCode(\Closure $make, string $message, int $status, string $code): void
    {
        $exception = $make();

        self::assertInstanceOf(OntologyApiException::class, $exception);
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame($message, $exception->getMessage());
        self::assertSame($status, $exception->getStatusCode());
        self::assertSame($code, $exception->getErrorCode());
    }

    /**
     * @return array<string, array{\Closure, string, int, string}>
     */
    public static function apiErrorProvider(): array
    {
        return [
            'unknown system' => [fn () => OntologyApiException::unknownSystem('ERP'), 'Unknown system "ERP".', 404, 'unknown_system'],
            'unknown entity' => [fn () => OntologyApiException::unknownEntity('ERP', 'Order'), 'Unknown entity "Order" for system "ERP".', 404, 'unknown_entity'],
            'disabled system' => [fn () => OntologyApiException::disabledSystem('ERP'), 'System "ERP" is disabled.', 409, 'disabled_system'],
            'disabled entity' => [fn () => OntologyApiException::disabledEntity('ERP', 'Order'), 'Entity "Order" of system "ERP" is disabled.', 409, 'disabled_entity'],
            'record not found' => [fn () => OntologyApiException::recordNotFound('42'), 'No record found for unique id "42".', 404, 'record_not_found'],
            'internal unreadable' => [
                fn () => OntologyApiException::internalEntityUnreadable('Order', 'no such column'),
                'Entity "Order" cannot be read from OroCommerce: no such column.',
                422,
                'internal_entity_unreadable',
            ],
            'internal unwritable' => [
                fn () => OntologyApiException::internalEntityUnwritable('Order', 'read-only'),
                'Entity "Order" cannot be written to OroCommerce: read-only.',
                422,
                'internal_entity_unwritable',
            ],
            'invalid payload' => [fn () => OntologyApiException::invalidPayload('bad body'), 'bad body', 400, 'invalid_payload'],
            'invalid query' => [fn () => OntologyApiException::invalidQuery('bad filter'), 'bad filter', 400, 'invalid_query'],
            'flow disabled' => [fn () => OntologyApiException::flowDisabled('Manual'), 'The "Manual" flow is disabled.', 409, 'flow_disabled'],
            'flow misconfigured' => [fn () => OntologyApiException::flowMisconfigured('Manual'), 'The "Manual" flow is not configured.', 500, 'flow_misconfigured'],
        ];
    }

    public function testTheApiExceptionCanBeBuiltDirectly(): void
    {
        $exception = new OntologyApiException('custom', 418, 'teapot');

        self::assertSame([418, 'teapot', 'custom'], [$exception->getStatusCode(), $exception->getErrorCode(), $exception->getMessage()]);
    }
}
