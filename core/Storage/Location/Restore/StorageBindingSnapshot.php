<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Restore;

/**
 * The storage locations as they stood just before a restore replaced the
 * database (#832).
 *
 * **A backup does not restore what a location points at.** The bytes on a
 * Drive, a WebDAV share, a bucket or another disk have their own life and
 * stay as they are today; only the `storage_locations` rows come back from
 * the backup. Left alone, a restore puts back the binding of the day the
 * backup was taken — an old Drive folder id, a path since changed, a
 * bucket since moved — while the files are where today's binding says.
 * The data is still there and the site no longer reaches it. So the
 * bindings are treated like the bytes: as state outside the backup, read
 * here before the restore and put back by {@see StorageBindingReconciler}
 * once the restored schema is current.
 *
 * **Held on disk, because the reconciliation happens in another process**
 * — the scheduler pass that finishes the migration, a minute or several
 * passes later. Under `storage/temp`, the tree a rollback does not touch
 * and no archive contains, mode 0600, its path carried in the scheduler's
 * payload like the portable restore's keys. The secret stays the
 * ciphertext it is in the database: nothing here is ever decrypted, and
 * nothing readable lands on the disk.
 */
final class StorageBindingSnapshot
{
    private const FORMAT = 1;

    /**
     * @param list<array{id: int, type: string, label: string, config: string, secret: ?string}> $rows
     */
    private function __construct(public readonly array $rows)
    {
    }

    /**
     * The rows as they stand. A database with no such table yet — an
     * installation older than storage locations — has nothing to keep.
     */
    public static function capture(StorageBindingRepository $repository): self
    {
        try {
            return new self($repository->rows());
        } catch (\PDOException) {
            return new self([]);
        }
    }

    /**
     * @param list<array{id: int, type: string, label: string, config: string, secret: ?string}> $rows
     */
    public static function of(array $rows): self
    {
        return new self($rows);
    }

    /**
     * Writes the snapshot under `storage/temp` and returns its path.
     *
     * @throws \RuntimeException when it cannot be written: a restore that
     *         could not keep today's bindings must not go ahead and lose them
     */
    public function holdAside(string $storagePath): string
    {
        $directory = $storagePath . '/temp';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Le dossier temporaire du site n\'a pas pu être créé.');
        }

        $encoded = [];
        foreach ($this->rows as $row) {
            $encoded[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'label' => $row['label'],
                'config' => $row['config'],
                // Base64 because the ciphertext is bytes, not text.
                'secret' => $row['secret'] === null ? null : base64_encode($row['secret']),
            ];
        }

        $path = $directory . '/storage_bindings_' . bin2hex(random_bytes(8)) . '.json';
        $json = json_encode(['format' => self::FORMAT, 'rows' => $encoded]);
        if ($json === false || @file_put_contents($path, $json) === false) {
            throw new \RuntimeException(
                'Les emplacements de stockage actuels n\'ont pas pu être mis de côté avant la restauration.'
            );
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
        }

        return $path;
    }

    /**
     * A snapshot held aside by {@see holdAside()}, or null when the file is
     * gone or unreadable — a restore queued before #832 has none.
     */
    public static function readHeldAside(string $path): ?self
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || ($decoded['format'] ?? null) !== self::FORMAT
            || !is_array($decoded['rows'] ?? null)
        ) {
            return null;
        }

        $rows = [];
        foreach ($decoded['rows'] as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null) || !is_string($row['type'] ?? null)) {
                continue;
            }
            $secret = is_string($row['secret'] ?? null) ? base64_decode($row['secret'], true) : false;
            $rows[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'label' => (string) ($row['label'] ?? ''),
                'config' => (string) ($row['config'] ?? '{}'),
                'secret' => is_string($secret) && $secret !== '' ? $secret : null,
            ];
        }

        return new self($rows);
    }
}
