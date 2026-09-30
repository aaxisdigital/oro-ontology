<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use PHPUnit\Framework\TestCase;

class OntologyEntityAttributeTest extends TestCase
{
    public function testDefaultsAreUndefinedAndNotRequired(): void
    {
        $attribute = new OntologyEntityAttribute();

        self::assertNull($attribute->getId());
        self::assertNull($attribute->getName());
        self::assertNull($attribute->getEntity());
        self::assertSame(OntologyEntityAttribute::TYPE_UNDEFINED, $attribute->getDatatype());
        self::assertFalse($attribute->isRequired());
        self::assertSame('', (string) $attribute);
    }

    public function testSettersAreFluent(): void
    {
        $entity = new OntologyEntity();
        $attribute = new OntologyEntityAttribute();

        $result = $attribute->setName('price')->setDatatype(OntologyEntityAttribute::TYPE_NUMBER)->setRequired(true)->setEntity($entity);

        self::assertSame($attribute, $result);
        self::assertSame('price', $attribute->getName());
        self::assertSame('number', $attribute->getDatatype());
        self::assertTrue($attribute->isRequired());
        self::assertSame($entity, $attribute->getEntity());
        self::assertSame('price', (string) $attribute);
    }

    public function testTheTypeListHoldsEveryDatatypeOnce(): void
    {
        self::assertSame(
            ['boolean', 'text', 'number', 'date', 'time', 'datetime', 'object', 'undefined'],
            OntologyEntityAttribute::TYPES
        );
    }
}
