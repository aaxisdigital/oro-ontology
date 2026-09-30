<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;

/**
 * The file_system storage: paths are resolved against the connector's base path and REFUSED when
 * they escape it (see {@see localTarget()}). Writes land on the APPLICATION SERVER's filesystem.
 */
class LocalFileStorage extends FileStorage
{
    /**
     * @param array<string, mixed> $config
     */
    public function read(array $config, string $path): mixed
    {
        $target = $this->localTarget($config, $path);

        if (is_dir($target)) {
            return $this->listLocalFolder($target, $path);
        }

        if (!is_file($target)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_FOUND, $path));
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
    public function write(array $config, string $path, string $content): array
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
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_WRITTEN, $path), $e);
        }
        if ($bytes === false) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_WRITTEN, $path));
        }

        // file_system writes land on the APPLICATION SERVER's filesystem (in a container, the
        // container's) — the receipt spells out the resolved absolute target so nobody hunts for
        // the file on the wrong machine.
        return $this->receipt($path, (int) $bytes) + ['absolutePath' => $target];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function delete(array $config, string $path): void
    {
        $target = $this->localTarget($config, $path);
        if (is_dir($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — delete needs a file path.', $path));
        }
        if (!is_file($target)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_FOUND, $path));
        }
        if (!@unlink($target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be deleted.', $path));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function rename(array $config, string $path, string $newPath): void
    {
        $source = $this->localTarget($config, $path);
        $target = $this->localTarget($config, $newPath);
        if (is_dir($source)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" is a folder — rename needs a file path.', $path));
        }
        if (!is_file($source)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_NOT_FOUND, $path));
        }
        if (file_exists($target)) {
            throw new ConnectorTransferFailure(sprintf(self::MSG_ALREADY_EXISTS, $newPath));
        }
        if (!is_dir(\dirname($target))) {
            throw new ConnectorTransferFailure(sprintf('The folder for "%s" does not exist.', $newPath));
        }
        if (!@rename($source, $target)) {
            throw new ConnectorTransferFailure(sprintf('Path "%s" could not be renamed.', $path));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listLocalFolder(string $target, string $path): array
    {
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
            throw new ConnectorConfigurationException('the connector has no base path configured');
        }
        $realBase = realpath($base);
        if ($realBase === false || !is_dir($realBase)) {
            throw new ConnectorConfigurationException(sprintf('the connector base path "%s" does not exist on the server', $base));
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
}
