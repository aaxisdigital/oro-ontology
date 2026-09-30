<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Oro\Bundle\AttachmentBundle\Entity\File;
use PHPUnit\Framework\TestCase;

class OntologySystemTest extends TestCase
{
    public function testDefaultsAreEnabledAndExternalWithoutALogo(): void
    {
        $system = new OntologySystem();

        self::assertNull($system->getId());
        self::assertNull($system->getName());
        self::assertTrue($system->isEnabled());
        self::assertTrue($system->isExternal());
        self::assertNull($system->getLogo());
        self::assertSame('', (string) $system);
    }

    public function testSettersAreFluent(): void
    {
        $logo = new File();
        $system = new OntologySystem();

        $result = $system->setName('SAP')->setEnabled(false)->setExternal(false)->setLogo($logo);

        self::assertSame($system, $result);
        self::assertSame('SAP', $system->getName());
        self::assertFalse($system->isEnabled());
        self::assertFalse($system->isExternal());
        self::assertSame($logo, $system->getLogo());
        self::assertSame('SAP', (string) $system);
    }
}
