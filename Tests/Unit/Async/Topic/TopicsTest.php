<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async\Topic;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowEventTopic;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowRunsRebuildTopic;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyHttpRequestTopic;
use Oro\Component\MessageQueue\Topic\AbstractTopic;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\Exception\UndefinedOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TopicsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function resolve(AbstractTopic $topic, array $body): array
    {
        $resolver = new OptionsResolver();
        $topic->configureMessageBody($resolver);

        return $resolver->resolve($body);
    }

    public function testTheDataUpsertTopic(): void
    {
        self::assertSame('aaxis_ontology_data_upsert', OntologyDataUpsertTopic::getName());
        self::assertSame('Upserts Ontology records from an inbound data flow.', OntologyDataUpsertTopic::getDescription());

        // Validation is deliberately lenient (the DB function owns it): every field is optional.
        self::assertSame(
            ['flow_id' => null, 'uuid' => null, 'entity_id' => null, 'unique_id' => [], 'updated_at' => null, 'payload' => []],
            self::resolve(new OntologyDataUpsertTopic(), [])
        );
        self::assertSame(
            ['flow_id' => 3, 'uuid' => 'u', 'entity_id' => 9, 'unique_id' => ['a'], 'updated_at' => 't', 'payload' => [['x' => 1]]],
            self::resolve(new OntologyDataUpsertTopic(), ['flow_id' => 3, 'uuid' => 'u', 'entity_id' => 9, 'unique_id' => ['a'], 'updated_at' => 't', 'payload' => [['x' => 1]]])
        );
    }

    public function testTheFlowEventTopic(): void
    {
        self::assertSame('aaxis_ontology_flow_event', OntologyFlowEventTopic::getName());
        self::assertSame('Records one Ontology flow-execution event.', OntologyFlowEventTopic::getDescription());

        self::assertSame(
            ['flow_id' => null, 'flow_uuid' => null, 'flow_name' => null, 'event' => null, 'datetime' => null, 'payload' => []],
            self::resolve(new OntologyFlowEventTopic(), [])
        );
        self::assertSame('flow-start', self::resolve(new OntologyFlowEventTopic(), ['event' => 'flow-start'])['event']);
    }

    public function testTheHttpRequestTopic(): void
    {
        self::assertSame('aaxis_ontology_http_request', OntologyHttpRequestTopic::getName());
        self::assertSame('Sends one async Ontology HTTP Request step (fire and forget).', OntologyHttpRequestTopic::getDescription());

        self::assertEquals(
            [
                'connector_id' => 7, 'method' => 'GET', 'path' => 'x', 'step' => 'S',
                'body' => null, 'body_type' => 'text', 'flow_id' => null, 'flow_name' => null, 'flow_uuid' => null,
            ],
            self::resolve(new OntologyHttpRequestTopic(), ['connector_id' => 7, 'method' => 'GET', 'path' => 'x', 'step' => 'S'])
        );
    }

    public function testTheRunIndexRebuildTopicCarriesNothing(): void
    {
        self::assertSame('aaxis_ontology_flow_runs_rebuild', OntologyFlowRunsRebuildTopic::getName());
        self::assertStringContainsString('run index', OntologyFlowRunsRebuildTopic::getDescription());
        self::assertSame([], self::resolve(new OntologyFlowRunsRebuildTopic(), []));
    }

    public function testTheHttpRequestTopicNeedsTheRequest(): void
    {
        $this->expectException(MissingOptionsException::class);

        self::resolve(new OntologyHttpRequestTopic(), ['connector_id' => 7, 'method' => 'GET', 'step' => 'S']);
    }

    public function testTheHttpRequestTopicIsTyped(): void
    {
        $this->expectException(InvalidOptionsException::class);

        self::resolve(new OntologyHttpRequestTopic(), ['connector_id' => '7', 'method' => 'GET', 'path' => 'x', 'step' => 'S']);
    }

    public function testUnknownBodyKeysAreRefused(): void
    {
        $this->expectException(UndefinedOptionsException::class);

        self::resolve(new OntologyFlowEventTopic(), ['surprise' => 1]);
    }
}
