<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDataHistory;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use PHPUnit\Framework\TestCase;

/**
 * OntologyData and OntologyDataHistory share their fields through OntologyDataFieldsTrait.
 */
class OntologyDataTest extends TestCase
{
    /**
     * @dataProvider recordProvider
     */
    public function testTheSharedFieldsBehaveTheSameOnBothRecords(OntologyData|OntologyDataHistory $record): void
    {
        $system = (new OntologySystem())->setName('ERP');
        $entity = (new OntologyEntity())->setName('Order')->setSystem($system);
        $stamp = new \DateTimeImmutable('2026-03-04 05:06:07');

        self::assertNull($record->getId());
        self::assertNull($record->getEntity());
        self::assertNull($record->getSystem(), 'no entity, no system');
        self::assertNull($record->getUniqueId());
        self::assertNull($record->getUuid());
        self::assertNull($record->getVersion());
        self::assertNull($record->getPayload());
        self::assertNull($record->getUpdatedAt());

        $result = $record->setEntity($entity)
            ->setUniqueId('SO-1')
            ->setUuid('11111111-2222-3333-4444-555555555555')
            ->setVersion(3)
            ->setPayload(['id' => 'SO-1'])
            ->setUpdatedAt($stamp);

        self::assertSame($record, $result);
        self::assertSame($entity, $record->getEntity());
        self::assertSame($system, $record->getSystem());
        self::assertSame('SO-1', $record->getUniqueId());
        self::assertSame('11111111-2222-3333-4444-555555555555', $record->getUuid());
        self::assertSame(3, $record->getVersion());
        self::assertSame(['id' => 'SO-1'], $record->getPayload());
        self::assertSame($stamp, $record->getUpdatedAt());
    }

    /**
     * @return array<string, array{OntologyData|OntologyDataHistory}>
     */
    public static function recordProvider(): array
    {
        return ['latest record' => [new OntologyData()], 'history record' => [new OntologyDataHistory()]];
    }

    public function testStringRepresentations(): void
    {
        self::assertSame('SO-1', (string) (new OntologyData())->setUniqueId('SO-1')->setVersion(4));
        self::assertSame('SO-1 v4', (string) (new OntologyDataHistory())->setUniqueId('SO-1')->setVersion(4));
        self::assertSame('', (string) new OntologyData());
        self::assertSame(' v', (string) new OntologyDataHistory());
    }
}
