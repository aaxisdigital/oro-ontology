<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Topic carrying ONE async "HTTP Request" flow step: the request is sent by
 * {@see \Aaxis\Bundle\OntologyBundle\Async\OntologyHttpRequestProcessor} and its response is
 * discarded — the flow that queued it has already moved on.
 *
 * The message names the connector by ID (its server, headers and credentials are read at send
 * time, so no secret ever travels through the queue) and carries the body ALREADY RENDERED (a
 * DWL body is evaluated when the step runs — the consumer has no flow context).
 */
class OntologyHttpRequestTopic extends AbstractTopic
{
    public const string NAME = 'aaxis_ontology_http_request';

    #[\Override]
    public static function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sends one async Ontology HTTP Request step (fire and forget).';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['connector_id', 'method', 'path', 'step'])
            ->setDefined(['body', 'body_type', 'flow_id', 'flow_name', 'flow_uuid'])
            ->setDefaults([
                'body' => null,
                'body_type' => 'text',
                'flow_id' => null,
                'flow_name' => null,
                'flow_uuid' => null,
            ])
            ->setAllowedTypes('connector_id', 'int')
            ->setAllowedTypes('method', 'string')
            ->setAllowedTypes('path', 'string')
            ->setAllowedTypes('step', 'string')
            ->setAllowedTypes('body', ['null', 'string'])
            ->setAllowedTypes('body_type', 'string')
            ->setAllowedTypes('flow_id', ['null', 'int'])
            ->setAllowedTypes('flow_name', ['null', 'string'])
            ->setAllowedTypes('flow_uuid', ['null', 'string']);
    }
}
