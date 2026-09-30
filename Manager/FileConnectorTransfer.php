<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads and writes files through the three FILE-BASED connector types — file_system, sftp and
 * bucket — for the flow reader/writer steps ({@see FlowDebugExecutor}).
 *
 * ## Result contract (what lands in the step's destination variable)
 *
 * - **Reading a FOLDER** yields a LIST of items, each `{name, path, type: file|folder, size,
 *   modified}`. `path` is ready to hand to a following step, so "list a folder, then read each
 *   file" needs no path arithmetic in DWL. Items are sorted by name (folders and files mixed, as
 *   the storage reports them) so a flow's output is stable across runs and across connector types.
 * - **Reading a FILE** yields its content as a plain STRING — deliberately not JSON-decoded (unlike
 *   the rest_api reader): a file's type is not knowable from the transport, and a DWL step can
 *   parse when it wants to. Binary content passes through untouched.
 * - **Writing** yields `{isError: false, message, path, bytes}`.
 * - **Any I/O failure** yields `{isError: true, message, exception: {class, message}}` — the
 *   exception is the ROOT cause when the storage/library threw one, and the transfer failure itself
 *   for a condition detected up front, so the key is always there to read.
 *
 * ⚠️ **I/O failures are RETURNED, not thrown** — a missing path or a rejected permission is a
 * result the flow can branch on (`isError`), not a reason to abort the run. This is a deliberate
 * difference from the rest_api reader, which aborts the step on HTTP ≥ 400. What still throws is a
 * broken flow DEFINITION (no server/base path/bucket configured, no credentials, missing SFTP
 * library) — the caller turns that into the usual "Step X: ..." abort.
 *
 * ## Folder vs file
 *
 * file_system and sftp ask the storage (`is_dir`). Object storage has no folders, so a bucket path
 * is treated as a folder when it is EMPTY or ends with `/`, and as an object key otherwise — which
 * also means a bucket "folder" listing is a prefix listing (delimiter `/`), with `CommonPrefixes`
 * reported as `type: folder`.
 */
class FileConnectorTransfer
{
    public const string ITEM_FILE = FileStorage::ITEM_FILE;
    public const string ITEM_FOLDER = FileStorage::ITEM_FOLDER;

    private readonly LocalFileStorage $local;
    private readonly SftpFileStorage $sftp;
    private readonly BucketFileStorage $bucket;

    /**
     * The three storages are created here; an SFTP storage can be handed in instead.
     */
    public function __construct(
        S3RequestSigner $signer,
        HttpClientInterface $httpClient,
        ?SftpFileStorage $sftp = null,
    ) {
        $this->local = new LocalFileStorage();
        $this->sftp = $sftp ?? new SftpFileStorage();
        $this->bucket = new BucketFileStorage($signer, $httpClient);
    }

    /** The connector types this class handles — rest_api and database are not file-based. */
    public function supports(string $type): bool
    {
        return \in_array($type, [
            OntologyConnector::TYPE_FILE_SYSTEM,
            OntologyConnector::TYPE_SFTP,
            OntologyConnector::TYPE_BUCKET,
        ], true);
    }

    /**
     * @return list<array<string, mixed>>|string|array<string, mixed> folder listing, file content,
     *                                                               or an isError payload
     *
     * @throws \Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException (a \RuntimeException) when the connector itself is unusable (see the class docblock)
     */
    public function read(OntologyConnector $connector, string $path): mixed
    {
        $config = $connector->getConfig() ?? [];
        $path = $this->normalizeStepPath($path);

        try {
            return match ($connector->getType()) {
                OntologyConnector::TYPE_FILE_SYSTEM => $this->local->read($config, $path),
                OntologyConnector::TYPE_SFTP => $this->sftp->read($config, $path),
                default => $this->bucket->read($config, $path),
            };
        } catch (ConnectorTransferFailure $e) {
            return $this->failure($e);
        }
    }

    /**
     * @return array<string, mixed> a success receipt or an isError payload
     *
     * @throws \Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException (a \RuntimeException) when the connector itself is unusable (see the class docblock)
     */
    public function write(OntologyConnector $connector, string $path, string $content): array
    {
        $config = $connector->getConfig() ?? [];
        $path = $this->normalizeStepPath($path);

        try {
            if ($path === '' || str_ends_with($path, '/')) {
                throw new ConnectorTransferFailure(sprintf('"%s" is a folder path — a write needs a file path.', $path));
            }

            return match ($connector->getType()) {
                OntologyConnector::TYPE_FILE_SYSTEM => $this->local->write($config, $path, $content),
                OntologyConnector::TYPE_SFTP => $this->sftp->write($config, $path, $content),
                default => $this->bucket->write($config, $path, $content),
            };
        } catch (ConnectorTransferFailure $e) {
            return $this->failure($e);
        }
    }

    /**
     * Deletes ONE file (never a folder — refusing beats recursing). A missing path is a failure
     * payload on every storage — object storage would happily "delete" a key that never existed,
     * so the object's existence is checked first to keep the three types honest alike.
     *
     * @return array<string, mixed> `{isError: false, message, path}` or an isError payload
     *
     * @throws \Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException (a \RuntimeException) when the connector itself is unusable (see the class docblock)
     */
    public function delete(OntologyConnector $connector, string $path): array
    {
        $config = $connector->getConfig() ?? [];
        $path = $this->normalizeStepPath($path);

        try {
            if ($path === '' || str_ends_with($path, '/')) {
                throw new ConnectorTransferFailure(sprintf('"%s" is a folder path — delete needs a file path.', $path));
            }

            match ($connector->getType()) {
                OntologyConnector::TYPE_FILE_SYSTEM => $this->local->delete($config, $path),
                OntologyConnector::TYPE_SFTP => $this->sftp->delete($config, $path),
                default => $this->bucket->delete($config, $path),
            };

            return ['isError' => false, 'message' => sprintf('Deleted "%s".', $path), 'path' => $path];
        } catch (ConnectorTransferFailure $e) {
            return $this->failure($e);
        }
    }

    /**
     * Renames/moves ONE file. $newName without a "/" renames within the source's folder; with one
     * it is a full path against the connector's base, i.e. a move. An existing target is a failure
     * on every storage (a silent overwrite hides data loss). Object storage has no rename — the
     * object is copied (x-amz-copy-source) and the source deleted.
     *
     * @return array<string, mixed> `{isError: false, message, path: <new>, from: <old>}` or an
     *                              isError payload
     *
     * @throws \Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException (a \RuntimeException) when the connector itself is unusable (see the class docblock)
     */
    public function rename(OntologyConnector $connector, string $path, string $newName): array
    {
        $config = $connector->getConfig() ?? [];
        $path = $this->normalizeStepPath($path);
        $target = $this->renameTarget($path, $newName);

        try {
            if ($path === '' || str_ends_with($path, '/')) {
                throw new ConnectorTransferFailure(sprintf('"%s" is a folder path — rename needs a file path.', $path));
            }
            if ($target === '' || str_ends_with($target, '/')) {
                throw new ConnectorTransferFailure(sprintf('"%s" is not a valid new name.', $newName));
            }
            if ($target === $path) {
                throw new ConnectorTransferFailure(sprintf('"%s" already is the current name.', $newName));
            }

            match ($connector->getType()) {
                OntologyConnector::TYPE_FILE_SYSTEM => $this->local->rename($config, $path, $target),
                OntologyConnector::TYPE_SFTP => $this->sftp->rename($config, $path, $target),
                default => $this->bucket->rename($config, $path, $target),
            };

            return [
                'isError' => false,
                'message' => sprintf('Renamed "%s" to "%s".', $path, $target),
                'path' => $target,
                'from' => $path,
            ];
        } catch (ConnectorTransferFailure $e) {
            return $this->failure($e);
        }
    }

    /**
     * The rename target path: a bare name replaces the source's leaf, a name containing "/" is a
     * full path against the connector base (= a move).
     */
    private function renameTarget(string $path, string $newName): string
    {
        $newName = trim(str_replace('\\', '/', $newName));
        if (str_contains($newName, '/')) {
            return $this->normalizeStepPath($newName);
        }
        $slash = strrpos($path, '/');

        return $slash === false ? $newName : substr($path, 0, $slash + 1) . $newName;
    }

    /** Leading slashes are dropped so every backend sees the same relative path. */
    private function normalizeStepPath(string $path): string
    {
        return ltrim(trim($path), '/');
    }

    /**
     * The one place the isError payload is built. `exception` reports the ROOT cause when there is
     * one (the SFTP/HTTP/filesystem throwable), and the transfer failure itself otherwise — so a
     * consumer can always read `exception`, and a wrapped failure never hides the real reason.
     *
     * @return array<string, mixed>
     */
    private function failure(ConnectorTransferFailure $failure): array
    {
        $cause = $failure->getPrevious() ?? $failure;

        return [
            'isError' => true,
            'message' => $failure->getMessage(),
            'exception' => ['class' => $cause::class, 'message' => $cause->getMessage()],
        ];
    }
}
