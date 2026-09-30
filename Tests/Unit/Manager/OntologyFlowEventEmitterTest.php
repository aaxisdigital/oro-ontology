<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OntologyFlowEventEmitterTest extends TestCase
{
    private MessageProducerInterface&MockObject $producer;
    private LoggerInterface&MockObject $logger;
    private OntologyFlowEventEmitter $emitter;

    protected function setUp(): void
    {
        $this->producer = $this->createMock(MessageProducerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->emitter = new OntologyFlowEventEmitter($this->producer, $this->logger);
    }

    public function testQueuesOneMessagePerEventStampedAtEmitTime(): void
    {
        $flow = (new OntologyFlow())->setName('Orders');
        (new \ReflectionProperty(OntologyFlow::class, 'id'))->setValue($flow, 12);
        $sent = null;
        $this->producer->expects(self::once())->method('send')
            ->with('aaxis_ontology_flow_event', self::anything())
            ->willReturnCallback(static function (string $topic, array $message) use (&$sent): void {
                $sent = $message;
            });
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->emitter->emit($flow, 'run-1', 'flow-start', ['trigger' => 'debug']);

        self::assertSame(12, $sent['flow_id']);
        self::assertSame('run-1', $sent['flow_uuid']);
        self::assertSame('Orders', $sent['flow_name']);
        self::assertSame('flow-start', $sent['event']);
        self::assertSame(['trigger' => 'debug'], $sent['payload']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $sent['datetime']);
        $stamp = new \DateTimeImmutable($sent['datetime'], new \DateTimeZone('UTC'));
        self::assertGreaterThanOrEqual($before->getTimestamp(), $stamp->getTimestamp());
    }

    public function testAnUnsavedFlowEmitsWithoutIdentity(): void
    {
        $this->producer->expects(self::once())->method('send')->with(
            'aaxis_ontology_flow_event',
            self::callback(static fn (array $m): bool => $m['flow_id'] === null && $m['flow_name'] === null
                && $m['flow_uuid'] === null && $m['event'] === 'step' && $m['payload'] === [])
        );

        $this->emitter->emit(null, null, 'step');
    }

    public function testProducerFailuresAreLoggedAndSwallowed(): void
    {
        $failure = new \RuntimeException('broker down');
        $this->producer->method('send')->willThrowException($failure);
        $this->logger->expects(self::once())->method('error')
            ->with('aaxis_ontology_flow_event: could not queue the event.', ['exception' => $failure]);

        $this->emitter->emitRaw(1, 'x', 'u', 'flow-finish');
    }
}
