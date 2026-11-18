<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Compact tables" (System Configuration → Ontology → Settings): VACUUM FULL of the tables the
 * history cleanup deletes from, in the background
 * ({@see \Aaxis\Bundle\OntologyBundle\Async\OntologyFlowHistoryCompactProcessor}) — a rewrite takes
 * longer than a page request should wait. The message carries nothing: the tables are fixed.
 */
class OntologyFlowHistoryCompactTopic extends AbstractTopic
{
    public const string NAME = 'aaxis_ontology_flow_history_compact';

    #[\Override]
    public static function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Compacts (VACUUM FULL) the Ontology flow-execution history tables.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        // No options: always the tables the history cleanup deletes from.
    }
}
