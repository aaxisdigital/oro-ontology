<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;

/**
 * The sftp storage, over phpseclib3. The SSH library sits behind the two protected methods at the
 * bottom so a test can stand in for a server.
 */
class SftpFileStorage extends FileStorage
{
    /**
     * SSH_FXP_ATTRS file type for a directory. Defined by the SFTP protocol (draft-ietf-secsh-filexfer),
     * which is why the literal is safe: phpseclib exposes it only as the GLOBAL constant
     * NET_SFTP_TYPE_DIRECTORY, defined lazily once a connection initialises.
     */
    private const int SFTP_TYPE_DIRECTORY = 2;

    /**
     * @param array<string, mixed> $config
     */
    public function read(array $config, string $path): mixed
    {
        $sftp = $this->sftpSession($config);
        // '.' is the login directory — an empty step path means "the connector's own root".
        $target = $path === '' ? '.' : $path;

        if ($sftp->is_dir($target)) {
            return $this->listSftpFolder($sftp, $target, $path);
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
    public function write(array $config, string $path, string $content): array
    {
        $sftp = $this->sftpSession($config);
        if ($sftp->is_dir($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is an existing folder.', $path));
        }

        try {
            $written = $sftp->put($path, $content);
        } catch (\Throwable $e) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_WRITTEN, $path), $e);
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
    public function delete(array $config, string $path): void
    {
        $sftp = $this->sftpSession($config);
        if ($sftp->is_dir($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — delete needs a file path.', $path));
        }
        if (!$sftp->file_exists($path)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_FOUND, $path));
        }
        if (!$sftp->delete($path, false)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be deleted: %s', $path, $sftp->getLastSFTPError() ?: 'unknown error'));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function rename(array $config, string $path, string $newPath): void
    {
        $sftp = $this->sftpSession($config);
        if ($sftp->is_dir($path)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — rename needs a file path.', $path));
        }
        if (!$sftp->file_exists($path)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_FOUND, $path));
        }
        if ($sftp->file_exists($newPath)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_ALREADY_EXISTS, $newPath));
        }
        if (!$sftp->rename($path, $newPath)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be renamed: %s', $path, $sftp->getLastSFTPError() ?: 'unknown error'));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSftpFolder(\phpseclib3\Net\SFTP $sftp, string $target, string $path): array
    {
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

    /**
     * @param array<string, mixed> $config
     */
    private function sftpSession(array $config): \phpseclib3\Net\SFTP
    {
        if (!$this->phpseclibAvailable()) {
            throw new ConnectorConfigurationException(
                'the phpseclib/phpseclib package is not installed on the server, so SFTP transfers cannot run'
            );
        }
        $host = trim((string) ($config['server'] ?? ''));
        if ($host === '') {
            throw new ConnectorConfigurationException('the connector has no server configured');
        }
        $user = trim((string) ($config['user'] ?? ''));
        $auth = (string) ($config['auth'] ?? 'none');
        if ($auth === 'none' || $user === '') {
            throw new ConnectorConfigurationException('the connector has no SFTP credentials configured');
        }
        $port = (int) ($config['port'] ?? 22);

        try {
            $sftp = $this->createSftp($host, $port > 0 ? $port : 22, self::TIMEOUT);
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

    protected function phpseclibAvailable(): bool
    {
        return class_exists(\phpseclib3\Net\SFTP::class);
    }

    /** Creating the client does not connect; the connection is made by its login(). */
    protected function createSftp(string $host, int $port, int $timeout): \phpseclib3\Net\SFTP
    {
        return new \phpseclib3\Net\SFTP($host, $port, $timeout);
    }
}
