<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Restore;

use Core\Maintenance\ConfigOnlyRestore;

/**
 * What a restore must not take back (#847, part 3): the list of this
 * server's backups and the files they point at, its journal, and its
 * off-site settings.
 *
 * **A restore brings back the unit's data, not this server's history.**
 * Replaced with the rest of the database, these used to return to the
 * restored date: backups deleted since reappeared without files, the
 * safety copy taken just before vanished from the list — with every copy
 * taken after the restored date, their files left on the disk for ever —,
 * the journal lost the very incident that motivated the restore, and the
 * off-site destination went back while its passphrase did not.
 *
 * **The model of `StorageBindingSnapshot` (#832).** Captured just before
 * the database is replaced — after the safety copy, so its row is part of
 * it —, held under `storage/temp` (mode 0600, the tree a rollback does not
 * touch and no archive contains), its path carried by every resume pass,
 * and put back on the pass that finishes the migration, after the storage
 * bindings. One JSON document per line, written and read as a stream:
 * the journal can be long.
 *
 * **Nothing is decrypted.** Rows are kept exactly as they are; none of
 * them holds anything encrypted with the site's key — the backups'
 * passwords live in `secrets.enc`, which an ordinary restore never
 * replaces and a portable one keeps aside on its own.
 */
final class RestoreStateSnapshot
{
    private const FORMAT = 1;

    /** @var array<string, list<string>> today's columns, per connection and table */
    private static array $columns = [];

    /**
     * Writes today's state under `storage/temp` and returns its path.
     *
     * @throws \RuntimeException when it cannot be written: a restore that
     *         could not keep today's list must not go ahead and lose it
     */
    public static function holdAside(\PDO $pdo, string $storagePath): string
    {
        $directory = $storagePath . '/temp';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Le dossier temporaire du site n\'a pas pu être créé.');
        }
        $path = $directory . '/restore_state_' . bin2hex(random_bytes(8)) . '.jsonl';
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('L\'état du site n\'a pas pu être mis de côté avant la restauration.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
        }

        try {
            self::write($handle, ['format' => self::FORMAT]);
            // The files BEFORE the backups that point at them: putBack()
            // reads this once, in order, and a backup line resolves its
            // file ids from the file lines already read.
            foreach (self::rows($pdo, 'SELECT * FROM files WHERE id IN (SELECT file_id FROM backups) '
                . 'OR id IN (SELECT db_dump_file_id FROM backups) ORDER BY id') as $row) {
                self::write($handle, ['kind' => 'file', 'row' => $row]);
            }
            foreach (self::rows($pdo, 'SELECT * FROM backups ORDER BY id') as $row) {
                self::write($handle, ['kind' => 'backup', 'row' => $row]);
            }
            // Filtered here by the one rule that says which keys are kept,
            // rather than turned into SQL: the table is a few hundred rows.
            foreach (self::rows($pdo, 'SELECT * FROM settings') as $row) {
                if (ConfigOnlyRestore::isKeptSetting((string) ($row['setting_key'] ?? ''))) {
                    self::write($handle, ['kind' => 'setting', 'row' => $row]);
                }
            }
            // Unbuffered where the driver can: the journal is the one table
            // here whose size nobody chose, and nothing else is asked of the
            // connection while it streams to the file.
            $unbuffered = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
            if ($unbuffered) {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
            try {
                foreach (self::rows($pdo, 'SELECT * FROM event_log ORDER BY id') as $row) {
                    self::write($handle, ['kind' => 'event', 'row' => $row]);
                }
            } finally {
                if ($unbuffered) {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw new \RuntimeException(
                'L\'état du site n\'a pas pu être mis de côté avant la restauration.',
                0,
                $e
            );
        }
        fclose($handle);

        return $path;
    }

    /**
     * The highest journal id the restored database carries — read in the
     * pass that replaced it, before that pass writes anything: every line
     * above it is the restore's own, appended after today's at put-back.
     */
    public static function restoredJournalMaxId(\PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM event_log')->fetchColumn();
    }

    /**
     * Puts today's backups, their files, the journal and — unless told
     * otherwise — the off-site settings back over the restored ones.
     *
     * - `backups`: the restored rows go, today's come back **with their
     *   ids** — what keeps each kept password attached to its backup. The
     *   `files` rows of the restored backups go with them; today's come
     *   back with their id when it is free in the restored database, a new
     *   one otherwise, and the backups follow. A `files` row of the unit's
     *   is never touched. `requested_by` becomes NULL for an account the
     *   restored database does not have.
     * - `event_log`: today's lines with their ids, then the restore's own
     *   (those above $restoredJournalMaxId) with new ids after them, so
     *   nothing is lost and the order stays chronological. An account id
     *   kept for a line becomes NULL when the restored database has no
     *   such account. A line written recently can thus name an account
     *   whose id changed meaning between the two dates: an account follows
     *   an e-mail address and stays the same person in practice, and that
     *   residual risk is accepted.
     * - the off-site settings (`ConfigOnlyRestore::KEPT_SETTING_PREFIXES`)
     *   when $keepRemoteSettings, then a destination whose storage location
     *   no longer exists is cleared.
     *
     * One transaction; it throws on any failure, and the caller rolls the
     * whole restore back — a list from the restored date would have lost
     * the safety copy, the one way to undo this restore.
     *
     * @return array{backups: int, events: int}
     */
    public static function putBack(\PDO $pdo, string $path, int $restoredJournalMaxId, bool $keepRemoteSettings): array
    {
        // Read whole, now: they are the few lines the restore wrote, and the
        // table is emptied below.
        $restoreLines = iterator_to_array(self::rows(
            $pdo,
            'SELECT * FROM event_log WHERE id > ? ORDER BY id',
            [$restoredJournalMaxId]
        ), false);

        $pdo->beginTransaction();
        try {
            $accountExists = self::accountChecker($pdo);

            $restoredFileIds = array_values(array_filter(array_map(
                'intval',
                $pdo->query('SELECT file_id FROM backups UNION SELECT db_dump_file_id FROM backups')
                    ->fetchAll(\PDO::FETCH_COLUMN) ?: []
            )));
            $pdo->exec('DELETE FROM backups');
            $deleteFile = $pdo->prepare('DELETE FROM files WHERE id = ?');
            foreach ($restoredFileIds as $fileId) {
                $deleteFile->execute([$fileId]);
            }

            $fileIds = [];
            $backups = 0;
            $events = 0;
            $pdo->exec('DELETE FROM event_log');
            foreach (self::read($path) as $entry) {
                $row = $entry['row'];
                switch ($entry['kind']) {
                    case 'file':
                        // The safety copy's own rows name the account running
                        // the restore, which a backup older than it lacks.
                        if (($row['created_by'] ?? null) !== null && !$accountExists((int) $row['created_by'])) {
                            $row['created_by'] = null;
                        }
                        $fileIds[(int) $row['id']] = self::insertFile($pdo, $row);
                        break;
                    case 'backup':
                        foreach (['file_id', 'db_dump_file_id'] as $column) {
                            if ($row[$column] !== null) {
                                $row[$column] = $fileIds[(int) $row[$column]] ?? null;
                            }
                        }
                        if ($row['requested_by'] !== null && !$accountExists((int) $row['requested_by'])) {
                            $row['requested_by'] = null;
                        }
                        self::insert($pdo, 'backups', $row);
                        $backups++;
                        break;
                    case 'setting':
                        if ($keepRemoteSettings) {
                            self::putSetting($pdo, $row);
                        }
                        break;
                    case 'event':
                        if ($row['user_account_id'] !== null && !$accountExists((int) $row['user_account_id'])) {
                            $row['user_account_id'] = null;
                        }
                        self::insert($pdo, 'event_log', $row);
                        $events++;
                        break;
                }
            }
            foreach ($restoreLines as $line) {
                unset($line['id']);
                if ($line['user_account_id'] !== null && !$accountExists((int) $line['user_account_id'])) {
                    $line['user_account_id'] = null;
                }
                self::insert($pdo, 'event_log', $line);
            }
            if ($keepRemoteSettings) {
                self::clearAVanishedDestination($pdo);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['backups' => $backups, 'events' => $events];
    }

    /**
     * Puts the safety copy's own rows back after a rollback.
     *
     * Its dump is taken BEFORE its `backups` row exists, so the database a
     * rollback restores does not know it: its files would stay on the disk
     * with no row, never purged, and the list would not offer it. Taken
     * from the held state when there is one (it contains the copy), or
     * rebuilt from the two paths the resume payload already carries.
     */
    public static function reregisterSafetyCopy(
        \PDO $pdo,
        ?string $heldPath,
        int $safetyBackupId,
        ?string $dumpPath,
        ?string $zipPath,
        string $storagePath,
        string $type
    ): void {
        if ($safetyBackupId <= 0) {
            return;
        }
        $exists = $pdo->prepare('SELECT 1 FROM backups WHERE id = ?');
        $exists->execute([$safetyBackupId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $files = [];
        $backup = null;
        if ($heldPath !== null && is_file($heldPath)) {
            foreach (self::read($heldPath) as $entry) {
                if ($entry['kind'] === 'file') {
                    $files[(int) $entry['row']['id']] = $entry['row'];
                } elseif ($entry['kind'] === 'backup' && (int) $entry['row']['id'] === $safetyBackupId) {
                    $backup = $entry['row'];
                }
            }
        }

        if ($backup !== null) {
            $accountExists = self::accountChecker($pdo);
            foreach (['file_id', 'db_dump_file_id'] as $column) {
                $held = $backup[$column] !== null ? ($files[(int) $backup[$column]] ?? null) : null;
                if ($held !== null && ($held['created_by'] ?? null) !== null
                    && !$accountExists((int) $held['created_by'])
                ) {
                    $held['created_by'] = null;
                }
                $backup[$column] = $held !== null ? self::insertFile($pdo, $held) : null;
            }
            $backup['requested_by'] = null;
            self::insert($pdo, 'backups', $backup);

            return;
        }

        if ($dumpPath === null || $zipPath === null || !is_file($dumpPath) || !is_file($zipPath)) {
            return;
        }
        $register = static fn(string $path, string $name, string $mime): int => self::insertFile($pdo, [
            'relative_path' => ltrim(substr($path, strlen(rtrim($storagePath, '/'))), '/'),
            'original_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => (int) filesize($path),
            'role_min' => \Core\Maintenance\Backup::FILE_ROLE,
        ]);
        self::insert($pdo, 'backups', [
            'id' => $safetyBackupId,
            'type' => $type,
            'file_id' => $register($zipPath, 'sauvegarde.zip', 'application/zip'),
            'db_dump_file_id' => $register($dumpPath, 'database.sql', 'application/sql'),
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Today's off-site settings, as held — what a portable restore decides
     * from (keep this server's set when it had a destination).
     *
     * @return array<string, ?string> setting key => value
     */
    public static function heldSettings(string $path): array
    {
        $settings = [];
        foreach (self::read($path) as $entry) {
            if ($entry['kind'] === 'setting') {
                $settings[(string) $entry['row']['setting_key']] = $entry['row']['setting_value'] ?? null;
            }
        }

        return $settings;
    }

    /**
     * @return iterable<array{kind: string, row: array<string, mixed>}>
     */
    private static function read(string $path): iterable
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('L\'état mis de côté avant la restauration est illisible.');
        }
        try {
            $header = json_decode((string) fgets($handle), true);
            if (!is_array($header) || ($header['format'] ?? null) !== self::FORMAT) {
                throw new \RuntimeException('L\'état mis de côté avant la restauration est illisible.');
            }
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || !is_string($entry['kind'] ?? null) || !is_array($entry['row'] ?? null)) {
                    throw new \RuntimeException('L\'état mis de côté avant la restauration est illisible.');
                }
                yield ['kind' => $entry['kind'], 'row' => $entry['row']];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $document
     */
    private static function write($handle, array $document): void
    {
        $line = json_encode($document, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new \RuntimeException('Écriture impossible.');
        }
    }

    /**
     * Rows one at a time, never the whole result at once.
     *
     * @param list<int|string> $params
     * @return iterable<array<string, mixed>>
     */
    private static function rows(\PDO $pdo, string $sql, array $params = []): iterable
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false || !$statement->execute($params)) {
            return;
        }
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
        $statement->closeCursor();
    }

    /**
     * A file row back with its id when the restored database left it free,
     * with a new one otherwise. Returns the id it now has.
     *
     * @param array<string, mixed> $row
     */
    private static function insertFile(\PDO $pdo, array $row): int
    {
        if (isset($row['id'])) {
            $taken = $pdo->prepare('SELECT 1 FROM files WHERE id = ?');
            $taken->execute([(int) $row['id']]);
            if ($taken->fetchColumn() !== false) {
                unset($row['id']);
            }
        }
        self::insert($pdo, 'files', $row);

        return isset($row['id']) ? (int) $row['id'] : (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function putSetting(\PDO $pdo, array $row): void
    {
        $exists = $pdo->prepare('SELECT id FROM settings WHERE module_id <=> ? AND setting_key = ?');
        $exists->execute([$row['module_id'] ?? null, $row['setting_key']]);
        $id = $exists->fetchColumn();
        if ($id !== false) {
            $pdo->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')
                ->execute([$row['setting_value'] ?? null, (int) $id]);

            return;
        }
        unset($row['id']);
        self::insert($pdo, 'settings', $row);
    }

    /**
     * A destination that names a storage location the restored database
     * no longer has is cleared; the existing off-site alert says so.
     */
    private static function clearAVanishedDestination(\PDO $pdo): void
    {
        $key = \Core\Maintenance\Remote\RemoteBackupDestination::LOCATION_SETTING;
        $current = $pdo->prepare('SELECT setting_value FROM settings WHERE module_id IS NULL AND setting_key = ?');
        $current->execute([$key]);
        $locationId = (int) ($current->fetchColumn() ?: 0);
        if ($locationId <= 0) {
            return;
        }
        $exists = $pdo->prepare('SELECT 1 FROM storage_locations WHERE id = ?');
        $exists->execute([$locationId]);
        if ($exists->fetchColumn() === false) {
            $pdo->prepare("UPDATE settings SET setting_value = '' WHERE module_id IS NULL AND setting_key = ?")
                ->execute([$key]);
        }
    }

    /** @return \Closure(int): bool */
    private static function accountChecker(\PDO $pdo): \Closure
    {
        $statement = $pdo->prepare('SELECT 1 FROM user_accounts WHERE id = ?');
        $known = [];

        return static function (int $id) use ($statement, &$known): bool {
            if (!array_key_exists($id, $known)) {
                $statement->execute([$id]);
                $known[$id] = $statement->fetchColumn() !== false;
            }

            return $known[$id];
        };
    }

    /**
     * Inserts the columns the row and today's table share; a column the
     * held row lacks takes its default.
     *
     * @param 'backups'|'files'|'event_log'|'settings' $table
     * @param array<string, mixed> $row
     */
    private static function insert(\PDO $pdo, string $table, array $row): void
    {
        $key = spl_object_id($pdo) . ':' . $table;
        self::$columns[$key] ??= self::columnsOf($pdo, $table);
        $kept = array_values(array_intersect(array_keys($row), self::$columns[$key]));
        $columnList = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $kept));
        $placeholders = implode(', ', array_fill(0, count($kept), '?'));
        $pdo->prepare('INSERT INTO `' . $table . '` (' . $columnList . ') VALUES (' . $placeholders . ')')
            ->execute(array_map(static fn(string $column): mixed => $row[$column], $kept));
    }

    /**
     * @param 'backups'|'files'|'event_log'|'settings' $table
     * @return list<string>
     */
    private static function columnsOf(\PDO $pdo, string $table): array
    {
        $statement = match ($table) {
            'backups' => $pdo->query('SELECT * FROM backups LIMIT 0'),
            'files' => $pdo->query('SELECT * FROM files LIMIT 0'),
            'event_log' => $pdo->query('SELECT * FROM event_log LIMIT 0'),
            'settings' => $pdo->query('SELECT * FROM settings LIMIT 0'),
        };
        $columns = [];
        for ($i = 0; $statement !== false && $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            if ($meta !== false) {
                $columns[] = $meta['name'];
            }
        }

        return $columns;
    }
}
