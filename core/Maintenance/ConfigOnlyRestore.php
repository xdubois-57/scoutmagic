<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Database\DatabaseRestorer;

/**
 * Restoring a « Configuration seule » backup brings back the configuration
 * and nothing else (#847, part 2): the values of `settings`, and which
 * modules are enabled.
 *
 * **The dump is not replayed.** It holds the structure of EVERY table and
 * the rows of two (`BackupService::createConfigOnlyDump()`); replayed as is,
 * its `DROP TABLE` and `CREATE TABLE` empty the whole site — members,
 * finances, news. So its two tables are loaded into temporary ones, where
 * MySQL parses its rows for us under the structure they were written with,
 * and their rows are applied to today's tables in one transaction:
 *
 * - `settings`: each value the backup knows replaces today's, except the
 *   keys that describe this server rather than the unit (KEPT_SETTING_
 *   PREFIXES — the off-site destination, part 3 keeps it the same way); a
 *   key the backup does not know keeps its value; one only the backup knows
 *   comes back with it.
 * - `module_registry`: only the enabled state (`enabled`, `enabled_at`,
 *   `enabled_by`, the last NULL when that account no longer exists), for
 *   the modules on both sides. **`installed_version` never**: it says
 *   which schema is really in the database, and an older one would replay
 *   migrations onto tables already migrated.
 *
 * A dump carrying the rows of any other table is refused: this class does
 * not guess what a « configuration » backup that is not one contains.
 */
final class ConfigOnlyRestore
{
    public const TABLES = ['settings', 'module_registry'];

    /**
     * Settings that describe this server, kept as they are today by every
     * restore: the off-site destination, its passphrase generation, its
     * retention and its state (`backup_remote_*`, `remote_backup_*`).
     */
    public const KEPT_SETTING_PREFIXES = ['backup_remote_', 'remote_backup_'];

    private const TEMPORARY_PREFIX = 'restore_config_';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array{settings: int, modules: int, disabled: list<string>}
     *         how many settings and modules were set, and the modules the
     *         restore switched off
     * @throws BackupException when the dump is not a configuration dump
     */
    public function apply(string $dumpPath): array
    {
        $sql = @file_get_contents($dumpPath);
        if ($sql === false) {
            throw new BackupException('Le fichier de la configuration sauvegardée est illisible.');
        }

        [$creates, $inserts] = self::configStatements(DatabaseRestorer::splitStatements($sql));

        try {
            foreach ($creates as $table => $create) {
                $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . self::TEMPORARY_PREFIX . $table . '`');
                $this->pdo->exec(self::asTemporary($table, $create));
            }
            foreach ($inserts as [$table, $insert]) {
                $this->pdo->exec(self::intoTemporary($table, $insert));
            }

            $this->pdo->beginTransaction();
            try {
                $settings = isset($creates['settings']) ? $this->applySettings() : 0;
                [$modules, $disabled] = isset($creates['module_registry']) ? $this->applyModules() : [0, []];
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } finally {
            foreach (array_keys($creates) as $table) {
                $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . self::TEMPORARY_PREFIX . $table . '`');
            }
        }

        return ['settings' => $settings, 'modules' => $modules, 'disabled' => $disabled];
    }

    public static function isKeptSetting(string $key): bool
    {
        foreach (self::KEPT_SETTING_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `CREATE TABLE` of the two configuration tables, and every
     * `INSERT` — refused when one targets another table.
     *
     * @param list<string> $statements
     * @return array{0: array<string, string>, 1: list<array{0: string, 1: string}>}
     * @throws BackupException
     */
    private static function configStatements(array $statements): array
    {
        $creates = [];
        $inserts = [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (preg_match('/^CREATE TABLE `([^`]+)`/i', $statement, $match) === 1) {
                if (in_array($match[1], self::TABLES, true)) {
                    $creates[$match[1]] = $statement;
                }
                continue;
            }
            if (preg_match('/^INSERT INTO `([^`]+)`/i', $statement, $match) === 1) {
                if (!in_array($match[1], self::TABLES, true)) {
                    throw new BackupException(
                        'Cette sauvegarde « Configuration seule » contient d\'autres données que la configuration : '
                        . 'elle n\'est pas restaurée. Rien n\'a été modifié.'
                    );
                }
                $inserts[] = [$match[1], $statement];
            }
        }

        return [$creates, $inserts];
    }

    /**
     * The table as the dump wrote it, temporary and without its foreign
     * keys — a temporary table takes none, and these rows are only read.
     */
    private static function asTemporary(string $table, string $create): string
    {
        $lines = preg_split('/\R/', $create) ?: [];
        $kept = array_values(array_filter(
            $lines,
            static fn(string $line): bool => preg_match('/^\s*(CONSTRAINT|FOREIGN KEY)\b/i', $line) !== 1
        ));
        $body = implode("\n", $kept);
        // The column or key line before a removed constraint lost its
        // follower: no trailing comma before the closing parenthesis.
        $body = (string) preg_replace('/,\s*\n(\s*\))/', "\n$1", $body);

        return (string) preg_replace(
            '/^CREATE TABLE `' . preg_quote($table, '/') . '`/i',
            'CREATE TEMPORARY TABLE `' . self::TEMPORARY_PREFIX . $table . '`',
            $body
        );
    }

    private static function intoTemporary(string $table, string $insert): string
    {
        return (string) preg_replace(
            '/^INSERT INTO `' . preg_quote($table, '/') . '`/i',
            'INSERT INTO `' . self::TEMPORARY_PREFIX . $table . '`',
            $insert
        );
    }

    private function applySettings(): int
    {
        $current = $this->settingsColumns();
        $saved = $this->pdo->query('SELECT * FROM `' . self::TEMPORARY_PREFIX . 'settings`');
        $exists = $this->pdo->prepare('SELECT id FROM settings WHERE module_id <=> ? AND setting_key = ?');
        $update = $this->pdo->prepare('UPDATE settings SET setting_value = ? WHERE id = ?');

        $count = 0;
        foreach ($saved !== false ? $saved->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if ($key === '' || self::isKeptSetting($key)) {
                continue;
            }
            $exists->execute([$row['module_id'] ?? null, $key]);
            $id = $exists->fetchColumn();
            if ($id !== false) {
                $update->execute([$row['setting_value'] ?? null, (int) $id]);
            } else {
                $columns = array_values(array_filter(
                    array_keys($row),
                    static fn(string $column): bool => $column !== 'id' && in_array($column, $current, true)
                ));
                $columnList = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columns));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $this->pdo->prepare('INSERT INTO settings (' . $columnList . ') VALUES (' . $placeholders . ')')
                    ->execute(array_map(static fn(string $column): mixed => $row[$column], $columns));
            }
            $count++;
        }

        return $count;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function applyModules(): array
    {
        $saved = $this->pdo->query(
            'SELECT module_id, enabled, enabled_at, enabled_by FROM `' . self::TEMPORARY_PREFIX . 'module_registry`'
        );
        $today = $this->pdo->prepare('SELECT enabled FROM module_registry WHERE module_id = ?');
        $account = $this->pdo->prepare('SELECT 1 FROM user_accounts WHERE id = ?');
        $update = $this->pdo->prepare(
            'UPDATE module_registry SET enabled = ?, enabled_at = ?, enabled_by = ? WHERE module_id = ?'
        );

        $count = 0;
        $disabled = [];
        foreach ($saved !== false ? $saved->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $moduleId = (string) ($row['module_id'] ?? '');
            $today->execute([$moduleId]);
            $wasEnabled = $today->fetchColumn();
            if ($wasEnabled === false) {
                continue;
            }
            $enabledBy = $row['enabled_by'] !== null ? (int) $row['enabled_by'] : null;
            if ($enabledBy !== null) {
                $account->execute([$enabledBy]);
                $enabledBy = $account->fetchColumn() !== false ? $enabledBy : null;
            }
            $enabled = (int) $row['enabled'] === 1;
            $update->execute([$enabled ? 1 : 0, $row['enabled_at'] ?? null, $enabledBy, $moduleId]);
            if ((int) $wasEnabled === 1 && !$enabled) {
                $disabled[] = $moduleId;
            }
            $count++;
        }

        return [$count, $disabled];
    }

    /**
     * Today's columns of `settings`, read from the table itself: a row only
     * the backup knows is inserted with the columns both sides have.
     *
     * @return list<string>
     */
    private function settingsColumns(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM settings LIMIT 0');
        $columns = [];
        for ($i = 0; $stmt !== false && $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            if ($meta !== false) {
                $columns[] = $meta['name'];
            }
        }

        return $columns;
    }
}
