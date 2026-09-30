<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Aaxis\Bundle\OntologyBundle\Manager\SftpConnectorProbe;
use phpseclib3\Net\SFTP;

/**
 * The sftp probe of an {@see OfflineConnectorTester}: sockets and SSH libraries come from its script.
 */
class OfflineSftpProbe extends SftpConnectorProbe
{
    public function __construct(private readonly OfflineConnectorTester $world)
    {
    }

    protected function connectTcp(string $address, int &$errorCode, string &$errorMessage)
    {
        return $this->world->dial($address, $errorCode, $errorMessage);
    }

    protected function phpseclibAvailable(): bool
    {
        return $this->world->phpseclib;
    }

    protected function createSftp(string $host, int $port, int $timeout): SFTP
    {
        $sftp = new FakeSftp($host, $port, $timeout);
        $sftp->accept = $this->world->sftpAccepts;
        $sftp->failure = $this->world->sftpFailure;

        return $this->world->sftp = $sftp;
    }

    protected function ssh2Available(): bool
    {
        return $this->world->ssh2;
    }

    protected function ssh2Authenticate(string $host, int $port, string $user, string $password): ?bool
    {
        if ($this->world->ssh2Result instanceof \Throwable) {
            throw $this->world->ssh2Result;
        }

        return $this->world->ssh2Result;
    }
}
