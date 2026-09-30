<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Aaxis\Bundle\OntologyBundle\Manager\SftpFileStorage;
use phpseclib3\Net\SFTP;

/**
 * The sftp storage of an {@see OfflineFileTransfer}: the SSH library is the scripted fake client.
 */
class OfflineSftpStorage extends SftpFileStorage
{
    public function __construct(private readonly OfflineFileTransfer $world)
    {
    }

    protected function phpseclibAvailable(): bool
    {
        return $this->world->phpseclib;
    }

    protected function createSftp(string $host, int $port, int $timeout): SFTP
    {
        $this->world->connections[] = [$host, $port, $timeout];

        return $this->world->sftp;
    }
}
