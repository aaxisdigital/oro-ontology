<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Topic carrying ONE flow-execution event to be written into aaxis_ontology_flow_events.
 *
 * Events are emitted by {@see \Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter}
 * during flow runs and consumed by
 * {@see \Aaxis\Bundle\OntologyBundle\Async\OntologyFlowEventProcessor} — logging is fully
 * asynchronous so the executor never waits on an insert.
 */
class OntologyFlowEventTopic extends AbstractTopic
{
    public const string NAME = 'aaxis_ontology_flow_event';

    #[\Override]
    public static function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Records one Ontology flow-execution event.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefined(['flow_id', 'flow_uuid', 'flow_name', 'event', 'datetime', 'payload'])
            ->setDefaults([
                'flow_id' => null,
                'flow_uuid' => null,
                'flow_name' => null,
                'event' => null,
                'datetime' => null,
                'payload' => [],
            ]);
    }
}
