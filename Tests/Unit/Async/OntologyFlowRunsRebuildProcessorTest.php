<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async;

use Aaxis\Bundle\OntologyBundle\Async\OntologyFlowRunsRebuildProcessor;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowRunsRebuildTopic;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OntologyFlowRunsRebuildProcessorTest extends TestCase
{
    public function testItSubscribesToTheRebuildTopic(): void
    {
        self::assertSame([OntologyFlowRunsRebuildTopic::getName()], OntologyFlowRunsRebuildProcessor::getSubscribedTopics());
    }

    public function testItRebuildsTheIndex(): void
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->expects(self::once())->method('rebuild')->with()->willReturn(['storage' => 'db', 'runs' => 1, 'days' => null]);
        $processor = new OntologyFlowRunsRebuildProcessor($maintenance, $this->createMock(LoggerInterface::class));

        self::assertSame(MessageProcessorInterface::ACK, $processor->process($this->createMock(MessageInterface::class), $this->createMock(SessionInterface::class)));
    }

    public function testAFailedRebuildIsLoggedAndRejected(): void
    {
        $failure = new \RuntimeException('bucket down');
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->method('rebuild')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('aaxis_ontology_flow_runs_rebuild: the run index could not be rebuilt.', ['exception' => $failure]);
        $processor = new OntologyFlowRunsRebuildProcessor($maintenance, $logger);

        self::assertSame(MessageProcessorInterface::REJECT, $processor->process($this->createMock(MessageInterface::class), $this->createMock(SessionInterface::class)));
    }
}
