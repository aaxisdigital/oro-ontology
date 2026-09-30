<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

use phpseclib3\Net\SFTP;

/**
 * An SFTP client that never opens a socket. login() records what it was given and answers as
 * scripted; the file operations work on a tiny in-memory file tree ($files, $dirs) with the
 * failure knobs the tests need.
 */
class FakeSftp extends SFTP
{
    public bool $accept = true;
    public ?\Throwable $failure = null;

    /** @var array<int, mixed> */
    public array $loginArguments = [];

    public string $connectedHost = '';
    public int $connectedPort = 0;
    public int $connectedTimeout = 0;

    /** @var array<string, string> remote path => content */
    public array $files = [];

    /** @var array<int, string> */
    public array $dirs = [];

    /** @var array<string, mixed>|false what rawlist() answers */
    public array|false $listing = false;

    /** @var array<int, string> the directories rawlist() was asked for */
    public array $listed = [];

    public mixed $getOverride = null;
    public ?\Throwable $putFailure = null;
    public bool $putSucceeds = true;
    public bool $deleteSucceeds = true;
    public bool $renameSucceeds = true;
    public string|false $lastError = false;

    /** @var array<int, string> */
    public array $errors = [];

    public function __construct(string $host = '', int $port = 0, int $timeout = 0)
    {
        $this->connectedHost = $host;
        $this->connectedPort = $port;
        $this->connectedTimeout = $timeout;
    }

    public function __destruct()
    {
        // The real destructor would try to say goodbye on a connection that never existed.
    }

    public function login($username, ...$args): bool
    {
        $this->loginArguments = [$username, ...$args];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->accept;
    }

    public function is_dir($path): bool
    {
        return \in_array($path, $this->dirs, true);
    }

    public function is_file($path): bool
    {
        return \array_key_exists($path, $this->files);
    }

    public function file_exists($path): bool
    {
        return $this->is_dir($path) || $this->is_file($path);
    }

    public function rawlist($path = '.', $recursive = false)
    {
        $this->listed[] = $path;

        return $this->listing;
    }

    public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
    {
        return $this->getOverride ?? ($this->files[$remote_file] ?? false);
    }

    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        if ($this->putFailure !== null) {
            throw $this->putFailure;
        }
        if (!$this->putSucceeds) {
            return false;
        }
        $this->files[$remote_file] = (string) $data;

        return true;
    }

    public function delete($path, $recursive = true)
    {
        if (!$this->deleteSucceeds) {
            return false;
        }
        unset($this->files[$path]);

        return true;
    }

    public function rename($oldname, $newname)
    {
        if (!$this->renameSucceeds) {
            return false;
        }
        $this->files[$newname] = $this->files[$oldname] ?? '';
        unset($this->files[$oldname]);

        return true;
    }

    public function getLastSFTPError()
    {
        return $this->lastError;
    }

    public function getSFTPErrors()
    {
        return $this->errors;
    }
}
