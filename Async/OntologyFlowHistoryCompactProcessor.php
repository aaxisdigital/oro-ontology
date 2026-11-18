<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowHistoryCompactTopic;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a queued "Compact tables" ({@see FlowRunMaintenance::compact()}): the outcome — each
 * table's size before/after, or why it was given up — lands on the Settings page. A table that
 * could not be compacted (typically its lock was busy) is already logged and recorded there, so
 * the message is ACKed either way; only a crash outside that handling rejects. No retry: pressing
 * the button again is the retry.
 */
class OntologyFlowHistoryCompactProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly FlowRunMaintenance $maintenance,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [OntologyFlowHistoryCompactTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        try {
            $this->maintenance->compact();
        } catch (\Throwable $e) {
            $this->logger->error('aaxis_ontology_flow_history_compact: the flow execution history tables could not be compacted.', ['exception' => $e]);

            return self::REJECT;
        }

        return self::ACK;
    }
}
