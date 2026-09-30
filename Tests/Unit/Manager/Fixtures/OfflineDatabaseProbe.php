<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Aaxis\Bundle\OntologyBundle\Manager\DatabaseConnectorProbe;

/**
 * The database probe of an {@see OfflineConnectorTester}: sockets and the PDO driver come from its script.
 */
class OfflineDatabaseProbe extends DatabaseConnectorProbe
{
    public function __construct(private readonly OfflineConnectorTester $world)
    {
    }

    protected function connectTcp(string $address, int &$errorCode, string &$errorMessage)
    {
        return $this->world->dial($address, $errorCode, $errorMessage);
    }

    protected function availableDrivers(): array
    {
        return $this->world->drivers;
    }

    protected function openPdo(string $dsn, string $user, string $password, array $options): \PDO
    {
        return $this->world->connectPdo($dsn, $user, $password, $options);
    }
}
