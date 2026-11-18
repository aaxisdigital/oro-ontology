<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowRunsRebuildTopic;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a queued "Rebuild run index" ({@see FlowRunMaintenance::rebuild()}): the outcome lands in
 * the Settings page's read-only run index status. A failure is logged and rejected — the button
 * can simply be pressed again.
 */
class OntologyFlowRunsRebuildProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly FlowRunMaintenance $maintenance,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [OntologyFlowRunsRebuildTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        try {
            $this->maintenance->rebuild();
        } catch (\Throwable $e) {
            $this->logger->error('aaxis_ontology_flow_runs_rebuild: the run index could not be rebuilt.', ['exception' => $e]);

            return self::REJECT;
        }

        return self::ACK;
    }
}
