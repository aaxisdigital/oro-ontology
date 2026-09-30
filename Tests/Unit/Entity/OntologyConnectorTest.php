<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use PHPUnit\Framework\TestCase;

class OntologyConnectorTest extends TestCase
{
    public function testADefaultConnectorIsAnUnconfiguredSftpOne(): void
    {
        $connector = new OntologyConnector();

        self::assertNull($connector->getId());
        self::assertNull($connector->getName());
        self::assertNull($connector->getSystem());
        self::assertSame(OntologyConnector::TYPE_SFTP, $connector->getType());
        self::assertNull($connector->getConfig());
        self::assertSame('', (string) $connector);
    }

    public function testSettersAreFluent(): void
    {
        $system = new OntologySystem();
        $connector = new OntologyConnector();

        $result = $connector->setName('Warehouse')
            ->setSystem($system)
            ->setType(OntologyConnector::TYPE_DATABASE)
            ->setConfig(['server' => 'db']);

        self::assertSame($connector, $result);
        self::assertSame('Warehouse', $connector->getName());
        self::assertSame($system, $connector->getSystem());
        self::assertSame('database', $connector->getType());
        self::assertSame(['server' => 'db'], $connector->getConfig());
        self::assertSame('Warehouse', (string) $connector);
    }

    public function testTheSupportedTypes(): void
    {
        self::assertSame(['sftp', 'rest_api', 'file_system', 'database', 'bucket'], OntologyConnector::TYPES);
    }
}
