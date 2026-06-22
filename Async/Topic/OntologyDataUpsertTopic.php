<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Topic for upserting Ontology records coming from an external flow.
 *
 * The message body is intentionally validated leniently here: full business validation
 * (flow id, uuid, entity, payload shape, etc.) is delegated to the aaxis_ontology_data_upsert
 * database function so that invalid messages are reported back as a structured error
 * payload instead of being rejected before processing.
 */
class OntologyDataUpsertTopic extends AbstractTopic
{
    public const NAME = 'aaxis_ontology_data_upsert';

    #[\Override]
    public static function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Upserts Ontology records from an inbound data flow.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefined(['flow_id', 'uuid', 'entity_id', 'unique_id', 'updated_at', 'payload'])
            ->setDefaults([
                'flow_id' => null,
                'uuid' => null,
                'entity_id' => null,
                'unique_id' => [],
                'updated_at' => null,
                'payload' => [],
            ]);
    }
}
