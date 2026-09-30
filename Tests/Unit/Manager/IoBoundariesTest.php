<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Aaxis\Bundle\OntologyBundle\Manager\DatabaseConnectorProbe;
use Aaxis\Bundle\OntologyBundle\Manager\DatabaseQueryRunner;
use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use Aaxis\Bundle\OntologyBundle\Manager\SftpConnectorProbe;
use Aaxis\Bundle\OntologyBundle\Manager\SftpFileStorage;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The tests of the connector classes replace their protected I/O boundaries with fakes; this one
 * pins what the REAL boundaries do, using nothing beyond the local machine: a listening loopback
 * socket, an in-memory SQLite database, and client objects that are only constructed (an HTTP or
 * SFTP client does not touch the network until it is used).
 */
class IoBoundariesTest extends TestCase
{
    /**
     * @return mixed
     */
    private static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }

    // --- ConnectorTester: sockets -------------------------------------------------------------

    public function testTheRealSocketBoundaryReachesAListeningPortAndReportsARefusedOne(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server, (string) $errorMessage);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $tester = new ConnectorTester(new S3RequestSigner());
        $config = ['server' => 'http://127.0.0.1:' . $port, 'auth' => 'none'];

        $open = $tester->test('rest_api', $config);
        fclose($server);
        $closed = $tester->test('rest_api', $config);

        self::assertTrue($open['success'], $open['message']);
        self::assertSame('Connected to 127.0.0.1:' . $port . '.', $open['steps'][0]['message']);
        self::assertFalse($closed['success']);
        self::assertStringStartsWith('Could not connect to 127.0.0.1:' . $port . ' — ', $closed['message']);
    }

    // --- ConnectorTester + probes: clients and drivers ----------------------------------------

    public function testTheTestersRealHttpClientBoundary(): void
    {
        self::assertInstanceOf(HttpClientInterface::class, self::call(new ConnectorTester(new S3RequestSigner()), 'createHttpClient'));
    }

    public function testTheDatabaseProbesRealDriverBoundaries(): void
    {
        $probe = new DatabaseConnectorProbe();

        self::assertSame(\PDO::getAvailableDrivers(), self::call($probe, 'availableDrivers'));
        $pdo = self::call($probe, 'openPdo', 'sqlite::memory:', '', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertInstanceOf(\PDO::class, $pdo);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    public function testTheSftpProbesRealLibraryBoundaries(): void
    {
        $probe = new SftpConnectorProbe();

        self::assertSame(class_exists(SFTP::class), self::call($probe, 'phpseclibAvailable'));
        self::assertSame(\function_exists('ssh2_connect'), self::call($probe, 'ssh2Available'));
        self::assertInstanceOf(SFTP::class, self::call($probe, 'createSftp', 'localhost', 22, 1));
    }

    // --- DatabaseQueryRunner ------------------------------------------------------------------

    public function testTheRunnersRealDriverBoundaries(): void
    {
        $runner = new DatabaseQueryRunner();

        self::assertSame(\PDO::getAvailableDrivers(), self::call($runner, 'availableDrivers'));
        $pdo = self::call($runner, 'openPdo', 'sqlite::memory:', '', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertInstanceOf(\PDO::class, $pdo);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    // --- SftpFileStorage ----------------------------------------------------------------------

    public function testTheSftpStorageRealLibraryBoundaries(): void
    {
        $storage = new SftpFileStorage();

        self::assertSame(class_exists(SFTP::class), self::call($storage, 'phpseclibAvailable'));
        self::assertInstanceOf(SFTP::class, self::call($storage, 'createSftp', 'localhost', 22, 1));
    }
}
