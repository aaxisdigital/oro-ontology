<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async;

use Aaxis\Bundle\OntologyBundle\Async\OntologyFlowHistoryPurgeProcessor;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowHistoryPurgeTopic;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OntologyFlowHistoryPurgeProcessorTest extends TestCase
{
    public function testItSubscribesToTheCleanupTopic(): void
    {
        self::assertSame(['aaxis_ontology_flow_history_purge'], OntologyFlowHistoryPurgeProcessor::getSubscribedTopics());
        self::assertNotSame('', OntologyFlowHistoryPurgeTopic::getDescription());
        $resolver = new OptionsResolver();
        (new OntologyFlowHistoryPurgeTopic())->configureMessageBody($resolver);
        self::assertSame([], $resolver->resolve([]));
    }

    public function testItPurgesAndAcknowledgesEvenWhenAStoreFailed(): void
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->expects(self::once())->method('purge')->with()
            ->willReturn(['cutoff' => 'c', 'events' => null, 'runs' => 1, 'bucketConfigured' => false, 'bucketObjects' => null]);
        $processor = new OntologyFlowHistoryPurgeProcessor($maintenance, $this->createMock(LoggerInterface::class));

        self::assertSame(MessageProcessorInterface::ACK, $processor->process($this->createMock(MessageInterface::class), $this->createMock(SessionInterface::class)));
    }

    public function testACrashIsLoggedAndRejected(): void
    {
        $failure = new \RuntimeException('config down');
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->method('purge')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('aaxis_ontology_flow_history_purge: the flow execution history could not be cleaned up.', ['exception' => $failure]);
        $processor = new OntologyFlowHistoryPurgeProcessor($maintenance, $logger);

        self::assertSame(MessageProcessorInterface::REJECT, $processor->process($this->createMock(MessageInterface::class), $this->createMock(SessionInterface::class)));
    }
}
