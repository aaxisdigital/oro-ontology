<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use PHPUnit\Framework\TestCase;

/**
 * Flow events are written by plain DBAL inserts and only ever READ through the entity, so the
 * values are injected the way the ORM hydrates them.
 */
class OntologyFlowEventTest extends TestCase
{
    public function testAFreshEventHasNothing(): void
    {
        $event = new OntologyFlowEvent();

        self::assertNull($event->getId());
        self::assertNull($event->getFlowId());
        self::assertNull($event->getFlowUuid());
        self::assertNull($event->getFlowName());
        self::assertNull($event->getEvent());
        self::assertNull($event->getDatetime());
        self::assertNull($event->getPayload());
    }

    public function testHydratedValuesAreExposed(): void
    {
        $event = new OntologyFlowEvent();
        $values = [
            'id' => 9,
            'flowId' => 4,
            'flowUuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'flowName' => 'Nightly',
            'event' => 'flow-start',
            'datetime' => '2026-03-04 05:06:07.123456',
            'payload' => ['trigger' => 'cron'],
        ];
        foreach ($values as $property => $value) {
            (new \ReflectionProperty(OntologyFlowEvent::class, $property))->setValue($event, $value);
        }

        self::assertSame(9, $event->getId());
        self::assertSame(4, $event->getFlowId());
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $event->getFlowUuid());
        self::assertSame('Nightly', $event->getFlowName());
        self::assertSame('flow-start', $event->getEvent());
        self::assertSame('2026-03-04 05:06:07.123456', $event->getDatetime());
        self::assertSame(['trigger' => 'cron'], $event->getPayload());
    }

    public function testTheEventCatalogue(): void
    {
        self::assertSame(
            [
                'flow-start', 'flow-finish', 'subflow-start', 'subflow-finish', 'inner-flow-start', 'inner-flow-finish',
                'flow-exception', 'data-upsert', 'log-message', 'step',
            ],
            OntologyFlowEvent::EVENTS
        );
    }
}
