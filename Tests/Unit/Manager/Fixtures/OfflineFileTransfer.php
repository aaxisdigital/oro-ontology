<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer;
use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * FileConnectorTransfer wired to a scripted SFTP client instead of an SSH connection.
 */
class OfflineFileTransfer extends FileConnectorTransfer
{
    public FakeSftp $sftp;
    public bool $phpseclib = true;

    /** @var array<int, array{string, int, int}> */
    public array $connections = [];

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->sftp = new FakeSftp();
        parent::__construct(new S3RequestSigner(), $httpClient, new OfflineSftpStorage($this));
    }
}
