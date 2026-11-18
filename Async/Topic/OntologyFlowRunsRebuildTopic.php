<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Rebuild run index" (System Configuration → Ontology → Settings): the rebuild runs in the
 * background ({@see \Aaxis\Bundle\OntologyBundle\Async\OntologyFlowRunsRebuildProcessor}) — on a
 * bucket it lists days of keys, longer than a page request may wait. The message carries nothing.
 */
class OntologyFlowRunsRebuildTopic extends AbstractTopic
{
    public const string NAME = 'aaxis_ontology_flow_runs_rebuild';

    #[\Override]
    public static function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Rebuilds the Ontology run index (Events page) from the stored flow events.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        // No options: the rebuild always targets the active event backend.
    }
}
