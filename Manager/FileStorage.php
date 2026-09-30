<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * One storage behind {@see FileConnectorTransfer}: the operations on a connector's configured
 * location, addressed by a step path that is already normalized (relative, no leading slash).
 *
 * A condition detected while performing an operation (a missing path, a refused permission, a
 * rejected request) throws {@see \Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure},
 * which the facade turns into an `isError` payload; a broken DEFINITION (no base path / server /
 * bucket / credentials, missing SSH library) throws
 * {@see \Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException}, which escapes so
 * the caller aborts the step.
 */
abstract class FileStorage
{
    public const string ITEM_FILE = 'file';
    public const string ITEM_FOLDER = 'folder';

    protected const string MSG_NOT_FOUND = 'Path "%s" does not exist.';
    protected const string MSG_NOT_WRITTEN = 'Path "%s" could not be written.';
    protected const string MSG_ALREADY_EXISTS = 'Path "%s" already exists.';

    protected const int TIMEOUT = 30;

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>|string a folder listing or a file's content
     */
    abstract public function read(array $config, string $path): mixed;

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed> the write receipt
     */
    abstract public function write(array $config, string $path, string $content): array;

    /**
     * @param array<string, mixed> $config
     */
    abstract public function delete(array $config, string $path): void;

    /**
     * @param array<string, mixed> $config
     */
    abstract public function rename(array $config, string $path, string $newPath): void;

    /**
     * One listing entry. `path` is the item's full step path, so it can be fed straight back into
     * a following reader/writer step — and a FOLDER keeps its trailing slash, because that is what
     * marks a bucket path as a prefix rather than an object key (file_system/sftp tolerate it).
     *
     * @return array<string, mixed>
     */
    protected function item(string $name, string $parentPath, string $type, ?int $size, ?int $modified): array
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
    protected function sortItems(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function receipt(string $path, int $bytes): array
    {
        return [
            'isError' => false,
            'message' => sprintf('Wrote %d byte(s) to "%s".', $bytes, $path),
            'path' => $path,
            'bytes' => $bytes,
        ];
    }
}
