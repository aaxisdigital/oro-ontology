<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;
use Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer;
use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The file_system arm of FileConnectorTransfer, against a real temporary directory.
 */
class FileConnectorTransferLocalTest extends TestCase
{
    private string $root;
    private string $base;
    private FileConnectorTransfer $transfer;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/aaxis-ontology-fs-' . bin2hex(random_bytes(6));
        $this->base = $this->root . '/base';
        mkdir($this->base . '/sub/inner', 0777, true);
        mkdir($this->root . '/outside', 0777, true);
        file_put_contents($this->base . '/a.txt', 'hello');
        file_put_contents($this->base . '/zeta.txt', "bin\x00\xFFary");
        file_put_contents($this->base . '/sub/b.txt', 'bee');
        file_put_contents($this->root . '/outside/secret.txt', 'top secret');
        touch($this->base . '/a.txt', 1700000000);
        symlink($this->root . '/outside', $this->base . '/link');

        $this->transfer = new FileConnectorTransfer(new S3RequestSigner(), new MockHttpClient());
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function connector(?string $basePath = null): OntologyConnector
    {
        return (new OntologyConnector())
            ->setType(OntologyConnector::TYPE_FILE_SYSTEM)
            ->setConfig(['base_path' => $basePath ?? $this->base]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertFailure(array $payload, string $message): void
    {
        self::assertTrue($payload['isError']);
        self::assertSame($message, $payload['message']);
        self::assertSame(ConnectorTransferFailure::class, $payload['exception']['class']);
        self::assertSame($message, $payload['exception']['message']);
    }

    public function testSupportsOnlyTheFileBasedTypes(): void
    {
        self::assertTrue($this->transfer->supports(OntologyConnector::TYPE_FILE_SYSTEM));
        self::assertTrue($this->transfer->supports(OntologyConnector::TYPE_SFTP));
        self::assertTrue($this->transfer->supports(OntologyConnector::TYPE_BUCKET));
        self::assertFalse($this->transfer->supports(OntologyConnector::TYPE_REST_API));
        self::assertFalse($this->transfer->supports(OntologyConnector::TYPE_DATABASE));
    }

    // --- read ---------------------------------------------------------------------------------

    public function testReadingAFolderListsItsItemsSortedByName(): void
    {
        $items = $this->transfer->read($this->connector(), '');

        self::assertSame(['a.txt', 'link', 'sub', 'zeta.txt'], array_column($items, 'name'));
        self::assertSame([
            'name' => 'a.txt',
            'path' => 'a.txt',
            'type' => 'file',
            'size' => 5,
            'modified' => '2023-11-14T22:13:20+00:00',
        ], $items[0]);
        // A folder keeps its trailing slash in `path` and reports no size.
        self::assertSame('sub/', $items[2]['path']);
        self::assertSame('folder', $items[2]['type']);
        self::assertNull($items[2]['size']);
        self::assertSame(8, $items[3]['size']);
    }

    public function testListingASubFolderBuildsPathsReadyForAFollowingStep(): void
    {
        $items = $this->transfer->read($this->connector(), '/sub/');

        self::assertSame([['b.txt', 'sub/b.txt', 'file'], ['inner', 'sub/inner/', 'folder']], array_map(
            static fn (array $item): array => [$item['name'], $item['path'], $item['type']],
            $items
        ));
    }

    public function testReadingAFileReturnsItsRawContent(): void
    {
        self::assertSame('hello', $this->transfer->read($this->connector(), '  /a.txt '));
        self::assertSame("bin\x00\xFFary", $this->transfer->read($this->connector(), 'zeta.txt'));
    }

    public function testAMissingPathIsAResultNotAnException(): void
    {
        self::assertFailure($this->transfer->read($this->connector(), 'nope.txt'), 'Path "nope.txt" does not exist.');
    }

    public function testDotDotInsideTheBaseIsResolvedTextually(): void
    {
        self::assertSame('bee', $this->transfer->read($this->connector(), 'sub/inner/../b.txt'));
        self::assertSame('hello', $this->transfer->read($this->connector(), 'sub/./../a.txt'));
        // A write target that does not exist yet is resolved the same way (dirname of the lexical form).
        $receipt = $this->transfer->write($this->connector(), 'sub/inner/../new.txt', 'x');
        self::assertFalse($receipt['isError']);
        self::assertSame($this->base . '/sub/new.txt', $receipt['absolutePath']);
    }

    /**
     * @dataProvider escapingPathProvider
     */
    public function testPathsEscapingTheBaseAreRefused(string $path): void
    {
        self::assertFailure(
            $this->transfer->read($this->connector(), $path),
            sprintf('Path "%s" resolves outside the connector base path.', ltrim($path, '/'))
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapingPathProvider(): array
    {
        return [
            'parent' => ['../outside/secret.txt'],
            'deep parent' => ['sub/../../outside/secret.txt'],
            'via a symlink' => ['link/secret.txt'],
            'missing but outside' => ['../nonexistent-file.txt'],
        ];
    }

    public function testASiblingFolderSharingTheBaseNamePrefixIsOutside(): void
    {
        mkdir($this->root . '/base-evil');
        file_put_contents($this->root . '/base-evil/x.txt', 'x');

        $result = $this->transfer->read($this->connector(), '../base-evil/x.txt');

        self::assertTrue($result['isError']);
        self::assertStringContainsString('resolves outside the connector base path', $result['message']);
    }

    public function testABasePathWithTrailingSlashesWorks(): void
    {
        self::assertSame('hello', $this->transfer->read($this->connector($this->base . '//'), 'a.txt'));
    }

    public function testAConnectorWithoutABasePathIsABrokenDefinitionAndThrows(): void
    {
        $connector = (new OntologyConnector())->setType(OntologyConnector::TYPE_FILE_SYSTEM);

        $this->expectException(ConnectorConfigurationException::class);
        $this->expectExceptionMessage('the connector has no base path configured');
        $this->transfer->read($connector, 'a.txt');
    }

    public function testABasePathThatDoesNotExistThrows(): void
    {
        $this->expectException(ConnectorConfigurationException::class);
        $this->expectExceptionMessage(sprintf('the connector base path "%s/missing" does not exist on the server', $this->root));

        $this->transfer->read($this->connector($this->root . '/missing'), 'a.txt');
    }

    public function testABasePathThatIsAFileThrows(): void
    {
        $this->expectException(ConnectorConfigurationException::class);

        $this->transfer->read($this->connector($this->base . '/a.txt'), 'a.txt');
    }

    // --- write --------------------------------------------------------------------------------

    public function testWritingCreatesTheFileAndReturnsAReceiptWithTheAbsolutePath(): void
    {
        $receipt = $this->transfer->write($this->connector(), '/sub/out.txt', 'héllo');

        self::assertSame([
            'isError' => false,
            'message' => 'Wrote 6 byte(s) to "sub/out.txt".',
            'path' => 'sub/out.txt',
            'bytes' => 6,
            'absolutePath' => $this->base . '/sub/out.txt',
        ], $receipt);
        self::assertSame('héllo', file_get_contents($this->base . '/sub/out.txt'));
    }

    public function testWritingOverwritesAnExistingFile(): void
    {
        $this->transfer->write($this->connector(), 'a.txt', 'new');

        self::assertSame('new', file_get_contents($this->base . '/a.txt'));
    }

    /**
     * @dataProvider unwritablePathProvider
     */
    public function testWriteFailuresAreResults(string $path, string $message): void
    {
        self::assertFailure($this->transfer->write($this->connector(), $path, 'x'), $message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unwritablePathProvider(): array
    {
        return [
            'empty path' => ['', '"" is a folder path — a write needs a file path.'],
            'trailing slash' => ['sub/', '"sub/" is a folder path — a write needs a file path.'],
            'existing folder' => ['sub', 'Path "sub" is an existing folder.'],
            'missing parent is not created' => ['nope/deeper/x.txt', 'The folder for "nope/deeper/x.txt" does not exist.'],
        ];
    }

    public function testAWriteTheOperatingSystemRefusesIsAResult(): void
    {
        $tooLong = str_repeat('x', 300) . '.txt';

        $result = $this->transfer->write($this->connector(), $tooLong, 'x');

        self::assertFailure($result, sprintf('Path "%s" could not be written.', $tooLong));
    }

    public function testWritingOutsideTheBaseIsRefused(): void
    {
        $result = $this->transfer->write($this->connector(), '../escape.txt', 'x');

        self::assertFailure($result, 'Path "../escape.txt" resolves outside the connector base path.');
        self::assertFileDoesNotExist($this->root . '/escape.txt');
    }

    // --- delete -------------------------------------------------------------------------------

    public function testDeletingAFile(): void
    {
        $result = $this->transfer->delete($this->connector(), '/sub/b.txt');

        self::assertSame(['isError' => false, 'message' => 'Deleted "sub/b.txt".', 'path' => 'sub/b.txt'], $result);
        self::assertFileDoesNotExist($this->base . '/sub/b.txt');
    }

    /**
     * @dataProvider undeletablePathProvider
     */
    public function testDeleteFailuresAreResults(string $path, string $message): void
    {
        self::assertFailure($this->transfer->delete($this->connector(), $path), $message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function undeletablePathProvider(): array
    {
        return [
            'empty path' => ['', '"" is a folder path — delete needs a file path.'],
            'trailing slash' => ['sub/', '"sub/" is a folder path — delete needs a file path.'],
            'a folder is never removed' => ['sub', 'Path "sub" is a folder — delete needs a file path.'],
            'missing' => ['ghost.txt', 'Path "ghost.txt" does not exist.'],
        ];
    }

    // --- rename -------------------------------------------------------------------------------

    public function testARenameWithABareNameStaysInTheSourceFolder(): void
    {
        $result = $this->transfer->rename($this->connector(), 'sub/b.txt', 'c.txt');

        self::assertSame(
            ['isError' => false, 'message' => 'Renamed "sub/b.txt" to "sub/c.txt".', 'path' => 'sub/c.txt', 'from' => 'sub/b.txt'],
            $result
        );
        self::assertFileExists($this->base . '/sub/c.txt');
        self::assertFileDoesNotExist($this->base . '/sub/b.txt');
    }

    public function testARenameOfARootFileWithABareName(): void
    {
        $result = $this->transfer->rename($this->connector(), 'zeta.txt', 'omega.txt');

        self::assertSame('omega.txt', $result['path']);
        self::assertFileExists($this->base . '/omega.txt');
    }

    public function testANameWithASlashIsAMoveAgainstTheBase(): void
    {
        $result = $this->transfer->rename($this->connector(), 'sub/b.txt', '\\sub\\inner\\moved.txt');

        self::assertSame('sub/inner/moved.txt', $result['path']);
        self::assertFileExists($this->base . '/sub/inner/moved.txt');
    }

    /**
     * @dataProvider unrenamableProvider
     */
    public function testRenameFailuresAreResults(string $path, string $newName, string $message): void
    {
        self::assertFailure($this->transfer->rename($this->connector(), $path, $newName), $message);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unrenamableProvider(): array
    {
        return [
            'source is a folder path' => ['sub/', 'x', '"sub/" is a folder path — rename needs a file path.'],
            'empty new name' => ['a.txt', '   ', '"   " is not a valid new name.'],
            'new name is a folder' => ['a.txt', 'sub/', '"sub/" is not a valid new name.'],
            'same name' => ['a.txt', 'a.txt', '"a.txt" already is the current name.'],
            'same path' => ['sub/b.txt', '/sub/b.txt', '"/sub/b.txt" already is the current name.'],
            'source is a folder' => ['sub', 'other', 'Path "sub" is a folder — rename needs a file path.'],
            'source missing' => ['ghost.txt', 'x.txt', 'Path "ghost.txt" does not exist.'],
            'target exists' => ['a.txt', 'zeta.txt', 'Path "zeta.txt" already exists.'],
            'target folder missing' => ['a.txt', 'nope/a.txt', 'The folder for "nope/a.txt" does not exist.'],
            'target outside the base' => ['a.txt', '../a.txt', 'Path "../a.txt" resolves outside the connector base path.'],
        ];
    }

    // --- permissions --------------------------------------------------------------------------

    public function testPermissionRefusalsAreResults(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores file permissions, so nothing can be refused');
        }
        file_put_contents($this->base . '/locked.txt', 'x');
        chmod($this->base . '/locked.txt', 0000);
        mkdir($this->base . '/readonly');
        file_put_contents($this->base . '/readonly/kept.txt', 'x');
        chmod($this->base . '/readonly', 0555);

        try {
            self::assertFailure($this->transfer->read($this->connector(), 'locked.txt'), 'Path "locked.txt" is not readable by the application.');
            self::assertFailure($this->transfer->write($this->connector(), 'readonly/new.txt', 'x'), 'Path "readonly/new.txt" is not writable by the application.');
            self::assertFailure($this->transfer->delete($this->connector(), 'readonly/kept.txt'), 'Path "readonly/kept.txt" could not be deleted.');
            self::assertFailure($this->transfer->rename($this->connector(), 'readonly/kept.txt', 'moved.txt'), 'Path "readonly/kept.txt" could not be renamed.');
        } finally {
            chmod($this->base . '/readonly', 0755);
            chmod($this->base . '/locked.txt', 0644);
        }
    }
}
