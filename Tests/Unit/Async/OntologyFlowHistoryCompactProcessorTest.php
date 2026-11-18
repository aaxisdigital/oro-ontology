<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async;

use Aaxis\Bundle\OntologyBundle\Async\OntologyFlowHistoryCompactProcessor;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowHistoryCompactTopic;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OntologyFlowHistoryCompactProcessorTest extends TestCase
{
    public function testItSubscribesToTheCompactionTopic(): void
    {
        self::assertSame(['aaxis_ontology_flow_history_compact'], OntologyFlowHistoryCompactProcessor::getSubscribedTopics());
        self::assertNotSame('', OntologyFlowHistoryCompactTopic::getDescription());
        $resolver = new OptionsResolver();
        (new OntologyFlowHistoryCompactTopic())->configureMessageBody($resolver);
        self::assertSame([], $resolver->resolve([]));
    }

    public function testItCompactsAndAcknowledgesEvenWhenATableWasGivenUp(): void
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->expects(self::once())->method('compact')
            ->willReturn(['at' => 'a', 'tables' => [['table' => 't', 'before' => 1, 'after' => 1, 'error' => 'lock timeout']]]);
        $processor = new OntologyFlowHistoryCompactProcessor($maintenance, $this->createMock(LoggerInterface::class));

        self::assertSame(MessageProcessorInterface::ACK, $processor->process($this->createMock(MessageInterface::class), $this->createMock(SessionInterface::class)));
    }

    public function testACrashIsLoggedAndRejected(): void
    {
        $failure = new \RuntimeException('connection lost');
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->method('compact')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('aaxis_ontology_flow_history_compact: the flow execution history tables could not be compacted.', ['exception' => $failure]);
        $processor = new OntologyFlowHistoryCompactProcessor($maintenance, $logger);

        self::assertSame(MessageProcessorInterface::REJECT, $processor->process($this->createMock(MessageInterface::class), $this->createMock(SessionInterface::class)));
    }
}
