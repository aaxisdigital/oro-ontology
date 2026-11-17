<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyHttpRequestTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorRequestFailure;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\RestConnectorClient;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the requests of ASYNC "HTTP Request" flow steps (fire and forget): the flow queued the
 * message and moved on, so the response is DISCARDED — nothing is stored anywhere.
 *
 * A failure (connector gone or no longer rest_api, no server, OAuth, transport, HTTP >= 400)
 * cannot fail the run that queued it any more: it is logged as an error and recorded as a
 * `log-message` event of that run, so it shows in the run's Events dialog. The message is then
 * REJECTED, never requeued — retrying a non-idempotent POST could duplicate its effect.
 */
class OntologyHttpRequestProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly RestConnectorClient $rest,
        private readonly OntologyFlowEventEmitter $flowEvents,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [OntologyHttpRequestTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        $body = $message->getBody();
        if (!\is_array($body) || !\is_int($body['connector_id'] ?? null)) {
            $this->logger->error('aaxis_ontology_http_request: malformed message.');

            return self::REJECT;
        }

        try {
            $this->rest->send(
                $this->connector($body['connector_id']),
                (string) ($body['method'] ?? 'GET'),
                (string) ($body['path'] ?? ''),
                \is_string($body['body'] ?? null) ? $body['body'] : null,
                (string) ($body['body_type'] ?? 'text'),
                \is_string($body['flow_uuid'] ?? null) ? $body['flow_uuid'] : null
            );
        } catch (\Throwable $e) {
            $this->reportFailure($body, $e);

            return self::REJECT;
        }

        return self::ACK;
    }

    private function connector(int $id): OntologyConnector
    {
        $connector = $this->doctrine->getRepository(OntologyConnector::class)->find($id);
        if (!$connector instanceof OntologyConnector) {
            throw new ConnectorRequestFailure('the configured connector no longer exists.');
        }
        if ($connector->getType() !== OntologyConnector::TYPE_REST_API) {
            throw new ConnectorRequestFailure(sprintf('a "%s" connector cannot send HTTP requests.', (string) $connector->getType()));
        }

        return $connector;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function reportFailure(array $body, \Throwable $e): void
    {
        $message = sprintf('Step "%s" (async HTTP request): %s', (string) ($body['step'] ?? ''), $e->getMessage());
        $this->logger->error('aaxis_ontology_http_request: ' . $message, ['exception' => $e]);
        $this->flowEvents->emitRaw(
            \is_int($body['flow_id'] ?? null) ? $body['flow_id'] : null,
            \is_string($body['flow_name'] ?? null) ? $body['flow_name'] : null,
            \is_string($body['flow_uuid'] ?? null) ? $body['flow_uuid'] : null,
            'log-message',
            ['message' => $message]
        );
    }
}
