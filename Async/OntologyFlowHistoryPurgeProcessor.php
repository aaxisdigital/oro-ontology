<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowHistoryPurgeTopic;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a queued "Clean up now" ({@see FlowRunMaintenance::purge()}): the outcome lands in the
 * Settings page's "last cleanup" line. A store that fails is logged by the purge and reported
 * there; the message is ACKed either way — the next press, or the daily job, retries. Only a
 * crash outside the purge's own per-store handling rejects.
 */
class OntologyFlowHistoryPurgeProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly FlowRunMaintenance $maintenance,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [OntologyFlowHistoryPurgeTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        try {
            $this->maintenance->purge();
        } catch (\Throwable $e) {
            $this->logger->error('aaxis_ontology_flow_history_purge: the flow execution history could not be cleaned up.', ['exception' => $e]);

            return self::REJECT;
        }

        return self::ACK;
    }
}
