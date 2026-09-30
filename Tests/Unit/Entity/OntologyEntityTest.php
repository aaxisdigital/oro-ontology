<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use PHPUnit\Framework\TestCase;

class OntologyEntityTest extends TestCase
{
    public function testADefaultEntityIsEnabledWithoutForcedDbStorageOrAttributes(): void
    {
        $entity = new OntologyEntity();

        self::assertNull($entity->getId());
        self::assertNull($entity->getName());
        self::assertNull($entity->getSystem());
        self::assertNull($entity->getUniqueAttribute());
        self::assertTrue($entity->isEnabled());
        self::assertFalse($entity->isForceDbStorage());
        self::assertCount(0, $entity->getAttributes());
        self::assertSame('', (string) $entity);
    }

    public function testSettersAreFluent(): void
    {
        $system = (new OntologySystem())->setName('ERP');
        $entity = new OntologyEntity();

        $result = $entity->setName('Order')->setSystem($system)->setUniqueAttribute('id')->setEnabled(false)->setForceDbStorage(true);

        self::assertSame($entity, $result);
        self::assertSame('Order', $entity->getName());
        self::assertSame($system, $entity->getSystem());
        self::assertSame('id', $entity->getUniqueAttribute());
        self::assertFalse($entity->isEnabled());
        self::assertTrue($entity->isForceDbStorage());
        self::assertSame('Order', (string) $entity);
    }

    public function testAddingAnAttributeLinksBothSidesOnlyOnce(): void
    {
        $entity = new OntologyEntity();
        $attribute = (new OntologyEntityAttribute())->setName('sku');

        $entity->addAttribute($attribute)->addAttribute($attribute);

        self::assertCount(1, $entity->getAttributes());
        self::assertSame($entity, $attribute->getEntity());
    }

    public function testRemovingAnAttributeDetachesIt(): void
    {
        $entity = new OntologyEntity();
        $attribute = (new OntologyEntityAttribute())->setName('sku');
        $entity->addAttribute($attribute);

        $entity->removeAttribute($attribute);

        self::assertCount(0, $entity->getAttributes());
        self::assertNull($attribute->getEntity());
    }

    public function testRemovingAnAttributeThatWasNeverAddedChangesNothing(): void
    {
        $entity = new OntologyEntity();
        $other = new OntologyEntity();
        $foreign = (new OntologyEntityAttribute())->setName('x');
        $other->addAttribute($foreign);

        $entity->removeAttribute($foreign);

        self::assertSame($other, $foreign->getEntity(), 'an attribute of another entity must stay linked to it');
    }

    public function testAnAttributeReassignedElsewhereKeepsItsNewOwnerWhenRemoved(): void
    {
        $entity = new OntologyEntity();
        $other = new OntologyEntity();
        $attribute = (new OntologyEntityAttribute())->setName('x');
        $entity->addAttribute($attribute);
        $attribute->setEntity($other);

        $entity->removeAttribute($attribute);

        self::assertCount(0, $entity->getAttributes());
        self::assertSame($other, $attribute->getEntity());
    }
}
