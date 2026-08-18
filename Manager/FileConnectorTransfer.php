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
    public const string ITEM_FILE = 'file';
    public const string ITEM_FOLDER = 'folder';

    private const int TIMEOUT = 30;

    /**
     * SSH_FXP_ATTRS file type for a directory. Defined by the SFTP protocol (draft-ietf-secsh-filexfer),
     * which is why the literal is safe: phpseclib exposes it only as the GLOBAL constant
     * NET_SFTP_TYPE_DIRECTORY, defined lazily once a connection initialises.
     */
    private const int SFTP_TYPE_DIRECTORY = 2;

    public function __construct(
        private readonly S3RequestSigner $signer,
        private readonly HttpClientInterface $httpClient,
    ) {
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
     * @throws \RuntimeException when the connector itself is unusable (see the class docblock)
     */
    public function read(OntologyConnector $connector, string $path): mixed
    {
        $config = $connector->getConfig() ?? [];
        $path = $this->normalizeStepPath($path);

        try {
            return match ($connector->getType()) {
                OntologyConnector::TYPE_FILE_SYSTEM => $this->readLocal($config, $path),
                OntologyConnector::TYPE_SFTP => $this->readSftp($config, $path),
                default => $this->readBucket($config, $path),
            };
        } catch (ConnectorTransferFailure $e) {
            return $this->failure($e);
        }
    }

    /**
     * @return array<string, mixed> a success receipt or an isError payload
     *
     * @throws \RuntimeException when the connector itself is unusable (see the class docblock)
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
                OntologyConnector::TYPE_FILE_SYSTEM => $this->writeLocal($config, $path, $content),
                OntologyConnector::TYPE_SFTP => $this->writeSftp($config, $path, $content),
                default => $this->writeBucket($config, $path, $content),
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
     * @throws \RuntimeException when the connector itself is unusable (see the class docblock)
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
                OntologyConnector::TYPE_FILE_SYSTEM => $this->deleteLocal($config, $path),
                OntologyConnector::TYPE_SFTP => $this->deleteSftp($config, $path),
                default => $this->deleteBucket($config, $path),
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
     * @throws \RuntimeException when the connector itself is unusable (see the class docblock)
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
                OntologyConnector::TYPE_FILE_SYSTEM => $this->renameLocal($config, $path, $target),
                OntologyConnector::TYPE_SFTP => $this->renameSftp($config, $path, $target),
                default => $this->renameBucket($config, $path, $target),
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

    // --- file_system ------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function readLocal(array $config, string $path): mixed
    {
        $target = $this->localTarget($config, $path);

        if (is_dir($target)) {
            $entries = @scandir($target);
            if ($entries === false) {
                throw new ConnectorTransferFailure(sprintf('Folder "%s" could not be listed.', $path));
            }
            $items = [];
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $target . '/' . $name;
                $modified = @filemtime($full);
                $items[] = $this->item(
                    $name,
                    $path,
                    is_dir($full) ? self::ITEM_FOLDER : self::ITEM_FILE,
                    is_file($full) ? (int) @filesize($full) : null,
                    $modified === false ? null : $modified
                );
            }

            return $this->sortItems($items);
        }

        if (!is_file($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" does not exist.', $path));
        }
        if (!is_readable($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is not readable by the application.', $path));
        }
        $content = @file_get_contents($target);
        if ($content === false) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be read.', $path));
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function writeLocal(array $config, string $path, string $content): array
    {
        $target = $this->localTarget($config, $path);
        if (is_dir($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is an existing folder.', $path));
        }
        $parent = \dirname($target);
        if (!is_dir($parent)) {
            // Deliberately NOT auto-created: a typo in a flow path would otherwise silently
            // scatter folders across the file system instead of reporting the mistake.
            throw new ConnectorTransferFailure(sprintf('The folder for "%s" does not exist.', $path));
        }
        if (!is_writable(is_file($target) ? $target : $parent)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is not writable by the application.', $path));
        }

        try {
            $bytes = @file_put_contents($target, $content);
        } catch (\Throwable $e) {
            // The app may promote warnings to exceptions (dev); either way the failure is a result.
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be written.', $path), $e);
        }
        if ($bytes === false) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be written.', $path));
        }

        // file_system writes land on the APPLICATION SERVER's filesystem (in a container, the
        // container's) — the receipt spells out the resolved absolute target so nobody hunts for
        // the file on the wrong machine.
        return $this->receipt($path, (int) $bytes) + ['absolutePath' => $target];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function deleteLocal(array $config, string $path): void
    {
        $target = $this->localTarget($config, $path);
        if (is_dir($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — delete needs a file path.', $path));
        }
        if (!is_file($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" does not exist.', $path));
        }
        if (!@unlink($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be deleted.', $path));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renameLocal(array $config, string $path, string $newPath): void
    {
        $source = $this->localTarget($config, $path);
        $target = $this->localTarget($config, $newPath);
        if (is_dir($source)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — rename needs a file path.', $path));
        }
        if (!is_file($source)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" does not exist.', $path));
        }
        if (file_exists($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" already exists.', $newPath));
        }
        if (!is_dir(\dirname($target))) {
            throw new ConnectorTransferFailure(sprintf('The folder for "%s" does not exist.', $newPath));
        }
        if (!@rename($source, $target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be renamed.', $path));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function deleteSftp(array $config, string $path): void
    {
        $sftp = $this->sftpSession($config);
        if ($sftp->is_dir($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — delete needs a file path.', $path));
        }
        if (!$sftp->file_exists($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" does not exist.', $path));
        }
        if (!$sftp->delete($path, false)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be deleted: %s', $path, $sftp->getLastSFTPError() ?: 'unknown error'));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renameSftp(array $config, string $path, string $newPath): void
    {
        $sftp = $this->sftpSession($config);
        if ($sftp->is_dir($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — rename needs a file path.', $path));
        }
        if (!$sftp->file_exists($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" does not exist.', $path));
        }
        if ($sftp->file_exists($newPath)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" already exists.', $newPath));
        }
        if (!$sftp->rename($path, $newPath)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be renamed: %s', $path, $sftp->getLastSFTPError() ?: 'unknown error'));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function deleteBucket(array $config, string $path): void
    {
        $endpoint = $this->bucketEndpoint($config);
        // Object storage "deletes" a key that never existed without complaint — check first so a
        // typo reports like it does on the other storages.
        $this->assertBucketObjectExists($endpoint, $path);

        [$status, $body] = $this->bucketRequest($endpoint, 'DELETE', $path, [], null);
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, $body));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renameBucket(array $config, string $path, string $newPath): void
    {
        $endpoint = $this->bucketEndpoint($config);
        $this->assertBucketObjectExists($endpoint, $path);
        [$status] = $this->bucketRequest($endpoint, 'HEAD', $newPath, [], null);
        if ($status < 400) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" already exists.', $newPath));
        }

        // No rename in object storage: server-side copy, then delete the source.
        $copySource = '/' . $endpoint['bucket'] . '/' . $path;
        [$status, $body] = $this->bucketRequest($endpoint, 'PUT', $newPath, [], null, [
            'x-amz-copy-source' => $this->signer->encodePath($copySource),
        ]);
        // A copy can FAIL with a 200 carrying an <Error> body (S3 quirk) — status alone is not enough.
        if ($status >= 400 || (string) ($this->parseXml($body)?->getName() ?? '') === 'Error') {
            throw new ConnectorTransferFailure($this->bucketHttpMessage(max($status, 400), $path, $body));
        }

        [$status, $body] = $this->bucketRequest($endpoint, 'DELETE', $path, [], null);
        if ($status >= 400) {
            throw new ConnectorTransferFailure(sprintf(
                'Copied to "%s" but the source "%s" could not be deleted: %s',
                $newPath,
                $path,
                $this->bucketHttpMessage($status, $path, $body)
            ));
        }
    }

    /**
     * @param array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string} $endpoint
     */
    private function assertBucketObjectExists(array $endpoint, string $path): void
    {
        [$status] = $this->bucketRequest($endpoint, 'HEAD', $path, [], null);
        if ($status === 404) {
            throw new ConnectorTransferFailure(sprintf('Object "%s" does not exist in bucket "%s".', $path, $endpoint['bucket']));
        }
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, ''));
        }
    }

    /**
     * Resolves a step path against the connector's base path, REFUSING anything that escapes it.
     * A flow author can type any path, so without this a step could read /etc/passwd through a
     * connector scoped to an import folder. The check is textual for paths that do not exist yet
     * (realpath() returns false for those, which is normal for a write target).
     *
     * @param array<string, mixed> $config
     */
    private function localTarget(array $config, string $path): string
    {
        $base = rtrim(trim((string) ($config['base_path'] ?? '')), '/');
        if ($base === '') {
            throw new \RuntimeException('the connector has no base path configured');
        }
        $realBase = realpath($base);
        if ($realBase === false || !is_dir($realBase)) {
            throw new \RuntimeException(sprintf('the connector base path "%s" does not exist on the server', $base));
        }

        $candidate = $realBase . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $real = realpath($candidate);
        // realpath() fails on any missing segment, so a write target resolves lexically. The
        // LEXICAL form is what gets returned in that case, never the raw candidate: dirname() on
        // an unresolved "a/../b.txt" would report the non-existent "a" as the target's folder.
        $resolved = $real === false ? $this->lexicalPath($candidate) : $real;
        if ($resolved !== $realBase && !str_starts_with($resolved, $realBase . '/')) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" resolves outside the connector base path.', $path));
        }

        return $resolved;
    }

    /** Resolves '.' and '..' textually, for paths that do not exist on disk yet. */
    private function lexicalPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $segments);
    }

    // --- sftp -------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function readSftp(array $config, string $path): mixed
    {
        $sftp = $this->sftpSession($config);
        // '.' is the login directory — an empty step path means "the connector's own root".
        $target = $path === '' ? '.' : $path;

        if ($sftp->is_dir($target)) {
            $list = $sftp->rawlist($target);
            if (!\is_array($list)) {
                throw new ConnectorTransferFailure(sprintf('Folder "%s" could not be listed.', $path));
            }
            $items = [];
            foreach ($list as $name => $attributes) {
                $name = (string) $name;
                if ($name === '.' || $name === '..' || !\is_array($attributes)) {
                    continue;
                }
                $isFolder = (int) ($attributes['type'] ?? 0) === self::SFTP_TYPE_DIRECTORY;
                $items[] = $this->item(
                    $name,
                    $path,
                    $isFolder ? self::ITEM_FOLDER : self::ITEM_FILE,
                    $isFolder ? null : (int) ($attributes['size'] ?? 0),
                    isset($attributes['mtime']) ? (int) $attributes['mtime'] : null
                );
            }

            return $this->sortItems($items);
        }

        if (!$sftp->is_file($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" does not exist on the server.', $path));
        }
        $content = $sftp->get($target);
        if (!\is_string($content)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be read.', $path));
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function writeSftp(array $config, string $path, string $content): array
    {
        $sftp = $this->sftpSession($config);
        if ($sftp->is_dir($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is an existing folder.', $path));
        }

        try {
            $written = $sftp->put($path, $content);
        } catch (\Throwable $e) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be written.', $path), $e);
        }
        if ($written === false) {
            // phpseclib reports the server's reason (no such directory, permission denied, ...).
            $reason = trim(implode('; ', $sftp->getSFTPErrors()));

            throw new ConnectorTransferFailure(sprintf(
                'Path "%s" could not be written%s',
                $path,
                $reason === '' ? '.' : ': ' . $reason
            ));
        }

        return $this->receipt($path, \strlen($content));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function sftpSession(array $config): \phpseclib3\Net\SFTP
    {
        if (!class_exists(\phpseclib3\Net\SFTP::class)) {
            throw new \RuntimeException(
                'the phpseclib/phpseclib package is not installed on the server, so SFTP transfers cannot run'
            );
        }
        $host = trim((string) ($config['server'] ?? ''));
        if ($host === '') {
            throw new \RuntimeException('the connector has no server configured');
        }
        $user = trim((string) ($config['user'] ?? ''));
        $auth = (string) ($config['auth'] ?? 'none');
        if ($auth === 'none' || $user === '') {
            throw new \RuntimeException('the connector has no SFTP credentials configured');
        }
        $port = (int) ($config['port'] ?? 22);

        try {
            $sftp = new \phpseclib3\Net\SFTP($host, $port > 0 ? $port : 22, self::TIMEOUT);
            $credential = $auth === 'key'
                ? \phpseclib3\Crypt\PublicKeyLoader::load((string) ($config['key'] ?? ''))
                : (string) ($config['password'] ?? '');
            $loggedIn = $sftp->login($user, $credential);
        } catch (\Throwable $e) {
            throw new ConnectorTransferFailure('The SFTP connection failed: ' . $e->getMessage(), $e);
        }
        if (!$loggedIn) {
            throw new ConnectorTransferFailure(sprintf('The server rejected the credentials for user "%s".', $user));
        }

        return $sftp;
    }

    // --- bucket -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function readBucket(array $config, string $path): mixed
    {
        $endpoint = $this->bucketEndpoint($config);

        // No folders in object storage: an empty path or a trailing slash means "list this prefix".
        if ($path === '' || str_ends_with($path, '/')) {
            return $this->listBucket($endpoint, $path);
        }

        [$status, $body] = $this->bucketRequest($endpoint, 'GET', $path, [], null);
        if ($status === 404) {
            // A missing BUCKET and a missing OBJECT are both 404 — only the error code tells them
            // apart, and blaming the object for a wrong bucket name sends the reader hunting in the
            // wrong place. (OCI says BucketNotFound, AWS NoSuchBucket.)
            $code = (string) ($this->parseXml($body)?->Code ?? '');

            throw new ConnectorTransferFailure(stripos($code, 'bucket') !== false
                ? sprintf('Bucket "%s" does not exist on this endpoint.', $endpoint['bucket'])
                : sprintf('Object "%s" does not exist in bucket "%s".', $path, $endpoint['bucket']));
        }
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, $body));
        }

        return $body;
    }

    /**
     * @param array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string} $endpoint
     *
     * @return list<array<string, mixed>>
     */
    private function listBucket(array $endpoint, string $prefix): array
    {
        $query = ['list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000'];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }
        [$status, $body] = $this->bucketRequest($endpoint, 'GET', '', $query, null);
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $prefix, $body));
        }

        $root = $this->parseXml($body);
        if ($root === null) {
            throw new ConnectorTransferFailure(sprintf('Bucket "%s" returned an unreadable listing.', $endpoint['bucket']));
        }

        $items = [];
        foreach ($root->CommonPrefixes as $common) {
            $name = $this->keyLeaf((string) $common->Prefix, $prefix);
            if ($name !== '') {
                $items[] = $this->item($name, $prefix, self::ITEM_FOLDER, null, null);
            }
        }
        foreach ($root->Contents as $object) {
            $key = (string) $object->Key;
            // The prefix itself can come back as a zero-byte "folder marker" — not an item.
            $name = $this->keyLeaf($key, $prefix);
            if ($name === '') {
                continue;
            }
            $modified = strtotime((string) $object->LastModified);
            $items[] = $this->item(
                $name,
                $prefix,
                self::ITEM_FILE,
                (int) $object->Size,
                $modified === false ? null : $modified
            );
        }

        return $this->sortItems($items);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function writeBucket(array $config, string $path, string $content): array
    {
        $endpoint = $this->bucketEndpoint($config);
        [$status, $body] = $this->bucketRequest($endpoint, 'PUT', $path, [], $content);
        if ($status >= 400) {
            throw new ConnectorTransferFailure($this->bucketHttpMessage($status, $path, $body));
        }

        return $this->receipt($path, \strlen($content));
    }

    /**
     * Signs and performs one request against the bucket. Path-style addressing keeps the signed
     * host independent of the bucket name; the URL is built with the signer's own encoders so it
     * matches the signature byte for byte.
     *
     * @param array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string} $endpoint
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders extra SIGNED headers (e.g. x-amz-copy-source)
     *
     * @return array{0: int, 1: string}
     */
    private function bucketRequest(array $endpoint, string $method, string $key, array $query, ?string $body, array $extraHeaders = []): array
    {
        $path = '/' . $endpoint['bucket'] . ($key === '' ? '' : '/' . $key);
        $headers = $this->signer->headers(
            $method,
            $endpoint['host'],
            $path,
            $query,
            $endpoint['accessKey'],
            $endpoint['secretKey'],
            $endpoint['region'],
            $body === null ? null : hash('sha256', $body),
            null,
            $extraHeaders
        );

        $encodedQuery = $this->signer->encodeQuery($query);
        $url = $endpoint['scheme'] . '://' . $endpoint['host'] . $this->signer->encodePath($path)
            . ($encodedQuery === '' ? '' : '?' . $encodedQuery);

        $options = ['headers' => $headers, 'timeout' => self::TIMEOUT];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (\Throwable $e) {
            throw new ConnectorTransferFailure('The bucket request failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{scheme: string, host: string, bucket: string, region: string, accessKey: string, secretKey: string}
     */
    private function bucketEndpoint(array $config): array
    {
        $server = trim((string) ($config['server'] ?? ''));
        $bucket = trim((string) ($config['bucket_name'] ?? ''));
        if ($server === '') {
            throw new \RuntimeException('the connector has no server configured');
        }
        if ($bucket === '') {
            throw new \RuntimeException('the connector has no bucket name configured');
        }

        $scheme = 'https';
        $urlPort = null;
        if (preg_match('~^(https?)://~i', $server, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            $parts = parse_url($server);
            $host = (string) ($parts['host'] ?? '');
            $urlPort = isset($parts['port']) ? (int) $parts['port'] : null;
        } else {
            $host = explode('/', $server)[0];
            if (str_contains($host, ':')) {
                [$host, $rawPort] = explode(':', $host, 2);
                $urlPort = (int) $rawPort;
            }
        }
        $configPort = (int) ($config['port'] ?? 0);
        $port = $configPort > 0 ? $configPort : ($urlPort ?? ($scheme === 'http' ? 80 : 443));

        return [
            'scheme' => $scheme,
            'host' => $this->signer->hostHeader($scheme, $host, $port),
            'bucket' => $bucket,
            'region' => $this->signer->regionFromHost($host),
            'accessKey' => trim((string) ($config['access_key'] ?? '')),
            'secretKey' => (string) ($config['secret_key'] ?? ''),
        ];
    }

    /**
     * Parses an S3 XML body for plain property access.
     *
     * S3 bodies declare a DEFAULT namespace, and SimpleXML will not return namespaced children
     * through `$xml->Contents`; `children($uri)` fixes the first level but not the nested
     * `->Key`/`->Size` reads. Dropping the declaration up front makes every level accessible with
     * no namespace juggling — the documents carry exactly one namespace, so nothing is lost.
     */
    private function parseXml(string $body): ?\SimpleXMLElement
    {
        $stripped = preg_replace('~\sxmlns="[^"]*"~', '', $body, 1) ?? $body;
        $xml = @simplexml_load_string($stripped);

        return $xml === false ? null : $xml;
    }

    /** Turns an S3 error body into one line, without dumping raw XML into the flow output. */
    private function bucketHttpMessage(int $status, string $path, string $body): string
    {
        $detail = '';
        $xml = $this->parseXml($body);
        if ($xml !== null && (string) $xml->Message !== '') {
            $detail = ' — ' . (string) $xml->Message;
        }

        return sprintf(
            'The bucket rejected "%s" with HTTP %d%s',
            $path,
            $status,
            $detail === '' ? '.' : $detail
        );
    }

    /** The last segment of an object key relative to the prefix being listed. */
    private function keyLeaf(string $key, string $prefix): string
    {
        $relative = $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, \strlen($prefix)) : $key;

        return trim($relative, '/');
    }

    // --- shared shapes ----------------------------------------------------------

    /** Leading slashes are dropped so every backend sees the same relative path. */
    private function normalizeStepPath(string $path): string
    {
        return ltrim(trim($path), '/');
    }

    /**
     * One listing entry. `path` is the item's full step path, so it can be fed straight back into
     * a following reader/writer step — and a FOLDER keeps its trailing slash, because that is what
     * marks a bucket path as a prefix rather than an object key (file_system/sftp tolerate it).
     *
     * @return array<string, mixed>
     */
    private function item(string $name, string $parentPath, string $type, ?int $size, ?int $modified): array
    {
        $parent = trim($parentPath, '/');
        $path = ($parent === '' ? '' : $parent . '/') . $name;

        return [
            'name' => $name,
            'path' => $type === self::ITEM_FOLDER ? $path . '/' : $path,
            'type' => $type,
            'size' => $size,
            'modified' => $modified === null ? null : gmdate(\DATE_ATOM, $modified),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(string $path, int $bytes): array
    {
        return [
            'isError' => false,
            'message' => sprintf('Wrote %d byte(s) to "%s".', $bytes, $path),
            'path' => $path,
            'bytes' => $bytes,
        ];
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
