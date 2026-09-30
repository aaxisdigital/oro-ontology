<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\OfflineFileTransfer;
use phpseclib3\Crypt\Common\PrivateKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The sftp arm of FileConnectorTransfer, against an in-memory SFTP client.
 */
class FileConnectorTransferSftpTest extends TestCase
{
    private const string TEST_KEY = "-----BEGIN PRIVATE KEY-----\n"
        . "MC4CAQAwBQYDK2VwBCIEIGZm+9dOCRiXYBKA5GHtSu4Fqbn9Vo//UPzDDhq5OT/K\n"
        . "-----END PRIVATE KEY-----\n";

    private OfflineFileTransfer $transfer;

    protected function setUp(): void
    {
        $this->transfer = new OfflineFileTransfer(new MockHttpClient());
        $this->transfer->sftp->dirs = ['.', 'inbox', 'inbox/old'];
        $this->transfer->sftp->files = ['inbox/a.csv' => 'a,b', 'report.txt' => 'R'];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function connector(array $override = []): OntologyConnector
    {
        return (new OntologyConnector())->setType(OntologyConnector::TYPE_SFTP)->setConfig($override + [
            'server' => ' sftp.example.com ',
            'user' => ' deploy ',
            'auth' => 'password',
            'password' => 'pw',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertFailure(array $payload, string $message, ?string $rootCause = null): void
    {
        self::assertTrue($payload['isError']);
        self::assertSame($message, $payload['message']);
        self::assertSame($rootCause ?? ConnectorTransferFailure::class, $payload['exception']['class']);
    }

    // --- session ------------------------------------------------------------------------------

    public function testItLogsInWithThePasswordOnTheConfiguredPort(): void
    {
        $this->transfer->read($this->connector(['port' => '2222']), 'report.txt');

        self::assertSame([['sftp.example.com', 2222, 30]], $this->transfer->connections);
        self::assertSame(['deploy', 'pw'], $this->transfer->sftp->loginArguments);
    }

    /**
     * @dataProvider defaultPortProvider
     */
    public function testAnUnsetOrInvalidPortDefaultsTo22(mixed $port): void
    {
        $this->transfer->read($this->connector(['port' => $port]), 'report.txt');

        self::assertSame(22, $this->transfer->connections[0][1]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function defaultPortProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-4],
            'not numeric' => ['abc'],
            'null' => [null],
        ];
    }

    public function testItLogsInWithAPrivateKey(): void
    {
        $this->transfer->read($this->connector(['auth' => 'key', 'key' => self::TEST_KEY]), 'report.txt');

        self::assertInstanceOf(PrivateKey::class, $this->transfer->sftp->loginArguments[1]);
    }

    public function testAnUnreadableKeyBecomesAConnectionFailurePayloadCarryingTheRootCause(): void
    {
        $result = $this->transfer->read($this->connector(['auth' => 'key', 'key' => 'garbage']), 'report.txt');

        self::assertTrue($result['isError']);
        self::assertStringStartsWith('The SFTP connection failed: ', $result['message']);
        // The payload reports the ROOT cause when the library threw one.
        self::assertNotSame(ConnectorTransferFailure::class, $result['exception']['class']);
    }

    public function testALoginErrorBecomesAConnectionFailurePayload(): void
    {
        $this->transfer->sftp->failure = new \RuntimeException('Connection closed by server');

        $result = $this->transfer->read($this->connector(), 'report.txt');

        self::assertFailure($result, 'The SFTP connection failed: Connection closed by server', \RuntimeException::class);
        self::assertSame('Connection closed by server', $result['exception']['message']);
    }

    public function testRejectedCredentialsAreAResult(): void
    {
        $this->transfer->sftp->accept = false;

        self::assertFailure(
            $this->transfer->read($this->connector(), 'report.txt'),
            'The server rejected the credentials for user "deploy".'
        );
    }

    /**
     * @dataProvider brokenDefinitionProvider
     *
     * @param array<string, mixed> $config
     */
    public function testABrokenDefinitionAbortsInsteadOfReturningAPayload(array $config, string $message, bool $phpseclib = true): void
    {
        $this->transfer->phpseclib = $phpseclib;
        $connector = (new OntologyConnector())->setType(OntologyConnector::TYPE_SFTP)->setConfig($config);

        $this->expectException(ConnectorConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->transfer->read($connector, 'x');
    }

    /**
     * @return array<string, array{array<string, mixed>, string, 2?: bool}>
     */
    public static function brokenDefinitionProvider(): array
    {
        $ok = ['server' => 's', 'user' => 'u', 'auth' => 'password', 'password' => 'p'];

        return [
            'library missing' => [$ok, 'the phpseclib/phpseclib package is not installed on the server, so SFTP transfers cannot run', false],
            'no server' => [['server' => ' '] + $ok, 'the connector has no server configured'],
            'auth none' => [['auth' => 'none'] + $ok, 'the connector has no SFTP credentials configured'],
            'no user' => [['user' => ''] + $ok, 'the connector has no SFTP credentials configured'],
        ];
    }

    // --- read ---------------------------------------------------------------------------------

    public function testReadingAFolderListsItsItemsSortedAndTyped(): void
    {
        $this->transfer->sftp->listing = [
            '.' => ['type' => 2, 'size' => 0],
            '..' => ['type' => 2, 'size' => 0],
            'zz.txt' => ['type' => 1, 'size' => '120', 'mtime' => 1700000000],
            'old' => ['type' => 2, 'size' => 4096, 'mtime' => 1700000100],
            'plain.bin' => ['type' => 1],
            'bogus' => 'not an attribute array',
            123 => ['type' => 1, 'size' => 1],
        ];

        $items = $this->transfer->read($this->connector(), 'inbox');

        self::assertSame(['inbox'], $this->transfer->sftp->listed);
        self::assertSame(['123', 'old', 'plain.bin', 'zz.txt'], array_column($items, 'name'));
        self::assertSame(
            ['name' => 'old', 'path' => 'inbox/old/', 'type' => 'folder', 'size' => null, 'modified' => '2023-11-14T22:15:00+00:00'],
            $items[1]
        );
        self::assertSame(
            ['name' => 'plain.bin', 'path' => 'inbox/plain.bin', 'type' => 'file', 'size' => 0, 'modified' => null],
            $items[2]
        );
        self::assertSame(['name' => 'zz.txt', 'path' => 'inbox/zz.txt', 'type' => 'file', 'size' => 120, 'modified' => '2023-11-14T22:13:20+00:00'], $items[3]);
    }

    public function testAnEmptyStepPathListsTheLoginDirectory(): void
    {
        $this->transfer->sftp->listing = ['x.txt' => ['type' => 1, 'size' => 1]];

        $items = $this->transfer->read($this->connector(), '/');

        self::assertSame(['.'], $this->transfer->sftp->listed);
        self::assertSame('x.txt', $items[0]['path']);
    }

    public function testAFolderThatCannotBeListedIsAResult(): void
    {
        $this->transfer->sftp->listing = false;

        self::assertFailure($this->transfer->read($this->connector(), 'inbox'), 'Folder "inbox" could not be listed.');
    }

    public function testReadingAFileReturnsItsContent(): void
    {
        self::assertSame('a,b', $this->transfer->read($this->connector(), '/inbox/a.csv'));
    }

    public function testReadingAMissingPathIsAResult(): void
    {
        self::assertFailure($this->transfer->read($this->connector(), 'ghost'), 'Path "ghost" does not exist on the server.');
    }

    public function testAFileTheServerCannotDeliverIsAResult(): void
    {
        $this->transfer->sftp->getOverride = false;

        self::assertFailure($this->transfer->read($this->connector(), 'report.txt'), 'Path "report.txt" could not be read.');
    }

    // --- write --------------------------------------------------------------------------------

    public function testWritingReturnsAReceiptWithoutAnAbsolutePath(): void
    {
        $result = $this->transfer->write($this->connector(), 'inbox/new.txt', 'héllo');

        self::assertSame([
            'isError' => false,
            'message' => 'Wrote 6 byte(s) to "inbox/new.txt".',
            'path' => 'inbox/new.txt',
            'bytes' => 6,
        ], $result);
        self::assertSame('héllo', $this->transfer->sftp->files['inbox/new.txt']);
    }

    public function testWritingOverAFolderIsAResult(): void
    {
        self::assertFailure($this->transfer->write($this->connector(), 'inbox', 'x'), 'Path "inbox" is an existing folder.');
    }

    public function testALibraryErrorWhileWritingIsWrappedWithItsCause(): void
    {
        $this->transfer->sftp->putFailure = new \RuntimeException('Connection lost');

        $result = $this->transfer->write($this->connector(), 'inbox/x.txt', 'x');

        self::assertFailure($result, 'Path "inbox/x.txt" could not be written.', \RuntimeException::class);
        self::assertSame('Connection lost', $result['exception']['message']);
    }

    public function testAServerRefusalCarriesTheServersReason(): void
    {
        $this->transfer->sftp->putSucceeds = false;
        $this->transfer->sftp->errors = ['SSH_FX_NO_SUCH_FILE', 'SSH_FX_FAILURE'];

        self::assertFailure(
            $this->transfer->write($this->connector(), 'nowhere/x.txt', 'x'),
            'Path "nowhere/x.txt" could not be written: SSH_FX_NO_SUCH_FILE; SSH_FX_FAILURE'
        );
    }

    public function testAServerRefusalWithoutAReasonEndsWithAPeriod(): void
    {
        $this->transfer->sftp->putSucceeds = false;

        self::assertFailure($this->transfer->write($this->connector(), 'x.txt', 'x'), 'Path "x.txt" could not be written.');
    }

    // --- delete + rename ----------------------------------------------------------------------

    public function testDeletingAFile(): void
    {
        $result = $this->transfer->delete($this->connector(), 'report.txt');

        self::assertSame(['isError' => false, 'message' => 'Deleted "report.txt".', 'path' => 'report.txt'], $result);
        self::assertArrayNotHasKey('report.txt', $this->transfer->sftp->files);
    }

    public function testDeleteFailures(): void
    {
        self::assertFailure($this->transfer->delete($this->connector(), 'inbox'), 'Path "inbox" is a folder — delete needs a file path.');
        self::assertFailure($this->transfer->delete($this->connector(), 'ghost.txt'), 'Path "ghost.txt" does not exist.');

        $this->transfer->sftp->deleteSucceeds = false;
        $this->transfer->sftp->lastError = 'SSH_FX_PERMISSION_DENIED';
        self::assertFailure(
            $this->transfer->delete($this->connector(), 'report.txt'),
            'Path "report.txt" could not be deleted: SSH_FX_PERMISSION_DENIED'
        );

        $this->transfer->sftp->lastError = false;
        self::assertFailure(
            $this->transfer->delete($this->connector(), 'report.txt'),
            'Path "report.txt" could not be deleted: unknown error'
        );
    }

    public function testRenamingAFile(): void
    {
        $result = $this->transfer->rename($this->connector(), 'inbox/a.csv', 'b.csv');

        self::assertSame(
            ['isError' => false, 'message' => 'Renamed "inbox/a.csv" to "inbox/b.csv".', 'path' => 'inbox/b.csv', 'from' => 'inbox/a.csv'],
            $result
        );
        self::assertSame(['report.txt', 'inbox/b.csv'], array_keys($this->transfer->sftp->files));
    }

    public function testRenameFailures(): void
    {
        self::assertFailure($this->transfer->rename($this->connector(), 'inbox', 'x'), 'Path "inbox" is a folder — rename needs a file path.');
        self::assertFailure($this->transfer->rename($this->connector(), 'ghost.txt', 'x'), 'Path "ghost.txt" does not exist.');
        self::assertFailure($this->transfer->rename($this->connector(), 'report.txt', 'inbox/a.csv'), 'Path "inbox/a.csv" already exists.');

        $this->transfer->sftp->renameSucceeds = false;
        $this->transfer->sftp->lastError = 'SSH_FX_FAILURE';
        self::assertFailure(
            $this->transfer->rename($this->connector(), 'report.txt', 'other.txt'),
            'Path "report.txt" could not be renamed: SSH_FX_FAILURE'
        );

        $this->transfer->sftp->lastError = '';
        self::assertFailure(
            $this->transfer->rename($this->connector(), 'report.txt', 'other.txt'),
            'Path "report.txt" could not be renamed: unknown error'
        );
    }
}
