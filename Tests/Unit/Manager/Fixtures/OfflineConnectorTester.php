<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A ConnectorTester with the network cut off: its protected I/O boundaries — and those of the
 * sftp and database probes it delegates to ({@see OfflineSftpProbe}, {@see OfflineDatabaseProbe},
 * which read their script from this object) — are scripted by the test through the public
 * properties below.
 */
class OfflineConnectorTester extends ConnectorTester
{
    /** @var array<int, string> every address the tester tried to reach */
    public array $addresses = [];

    /** @var array<int, string|resource|false> next results of the TCP connect (string = error text) */
    public array $sockets = [];

    public bool $httpAvailable = true;

    /** @var array<int, MockResponse|\Throwable> */
    public array $httpResponses = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: mixed}> */
    public array $httpRequests = [];

    /** @var array<int, string> */
    public array $drivers = ['pgsql'];

    public ?\PDO $pdo = null;
    public ?\Throwable $connectFailure = null;

    /** @var array<int, array{string, string, string, array<int, mixed>}> */
    public array $pdoAttempts = [];

    public bool $phpseclib = true;
    public ?FakeSftp $sftp = null;
    public bool $sftpAccepts = true;
    public ?\Throwable $sftpFailure = null;
    public bool $ssh2 = false;
    public bool|null|\Throwable $ssh2Result = null;

    public function __construct(?S3RequestSigner $signer = null)
    {
        parent::__construct($signer ?? new S3RequestSigner(), new OfflineSftpProbe($this), new OfflineDatabaseProbe($this));
    }

    /**
     * The scripted TCP connect shared by the tester and its probes.
     *
     * @return resource|false
     */
    public function dial(string $address, int &$errorCode, string &$errorMessage)
    {
        $this->addresses[] = $address;
        $next = array_shift($this->sockets);
        if ($next === null || \is_string($next)) {
            $errorCode = 111;
            $errorMessage = $next ?? 'Connection refused';

            return false;
        }

        return $next;
    }

    public function connectPdo(string $dsn, string $user, string $password, array $options): \PDO
    {
        $this->pdoAttempts[] = [$dsn, $user, $password, $options];
        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        return $this->pdo ?? throw new \LogicException('no PDO scripted');
    }

    protected function connectTcp(string $address, int &$errorCode, string &$errorMessage)
    {
        return $this->dial($address, $errorCode, $errorMessage);
    }

    protected function createHttpClient(): ?HttpClientInterface
    {
        if (!$this->httpAvailable) {
            return null;
        }

        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->httpRequests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $options['body'] ?? null];
            $next = array_shift($this->httpResponses) ?? new MockResponse('', ['http_code' => 200]);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
    }
}
