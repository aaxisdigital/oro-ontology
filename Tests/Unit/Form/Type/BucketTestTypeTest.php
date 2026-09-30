<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Form\Type;

use Aaxis\Bundle\OntologyBundle\Form\Type\BucketTestType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class BucketTestTypeTest extends TestCase
{
    public function testItIsAnArgumentFreeTextFieldWithItsOwnBlockPrefix(): void
    {
        // No constructor arguments: Symfony's FormRegistry instantiates such types itself.
        $type = new BucketTestType();

        self::assertSame(TextType::class, $type->getParent());
        self::assertSame('aaxis_ontology_bucket_test', $type->getBlockPrefix());
    }
}
