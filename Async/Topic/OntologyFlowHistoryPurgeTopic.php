<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Clean up now" (System Configuration → Ontology → Settings): the same retention purge the daily
 * job runs, in the background ({@see \Aaxis\Bundle\OntologyBundle\Async\OntologyFlowHistoryPurgeProcessor})
 * — batched deletes plus, with a bucket, a walk over its day folders take longer than a page
 * request may wait. The message carries nothing: the window is always the history days setting.
 */
class OntologyFlowHistoryPurgeTopic extends AbstractTopic
{
    public const string NAME = 'aaxis_ontology_flow_history_purge';

    #[\Override]
    public static function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Removes the Ontology flow-execution history older than the configured history days.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        // No options: the cutoff always follows "Flow execution history days".
    }
}
