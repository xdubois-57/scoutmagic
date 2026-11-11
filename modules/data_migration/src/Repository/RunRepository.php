<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Repository;

use Core\Security\EncryptionService;

/**
 * `data_migration_runs`. Timestamps are written from PHP (one clock,
 * docs/module-development.md § Timestamps); the encrypted source
 * parameters are decrypted here and nowhere else.
 */
class RunRepository
{
    private const ENCRYPTION_CONTEXT = 'data_migration_source_parameters';

    /**
     * Columns {@see update()} may write; everything else is fixed at
     * creation. Each is written by the one fixed statement below, so no
     * SQL is ever assembled from a caller's keys.
     */
    private const UPDATABLE = [
        'state', 'file_path', 'progress', 'analysis', 'chosen_years', 'report',
        'rejection_reason', 'confirmed_at', 'finished_at', 'file_purged_at',
        'format', 'format_version', 'installation_id', 'export_id', 'exported_at',
    ];

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array<string, mixed> $fields any UPDATABLE column, plus
     *        file_name, file_size and source_parameters (an array, stored
     *        encrypted); arrays are stored as JSON
     */
    public function create(string $importerKey, array $fields, ?int $createdBy, string $now): int
    {
        $parameters = $fields['source_parameters'] ?? null;
        $this->pdo->prepare(
            'INSERT INTO data_migration_runs
                (importer_key, state, file_path, file_name, file_size, source_parameters, format, format_version,
                 installation_id, export_id, exported_at, progress, analysis, chosen_years, report,
                 rejection_reason, created_by, created_at, updated_at, confirmed_at, finished_at, file_purged_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $importerKey,
            $fields['state'] ?? Run::UPLOADED,
            $fields['file_path'] ?? null,
            $fields['file_name'] ?? null,
            $fields['file_size'] ?? null,
            is_array($parameters) && $parameters !== []
                ? $this->encryption->encrypt((string) json_encode($parameters), self::ENCRYPTION_CONTEXT)
                : null,
            $fields['format'] ?? null,
            $fields['format_version'] ?? null,
            $fields['installation_id'] ?? null,
            $fields['export_id'] ?? null,
            $fields['exported_at'] ?? null,
            self::encode($fields['progress'] ?? null),
            self::encode($fields['analysis'] ?? null),
            self::encode($fields['chosen_years'] ?? null),
            self::encode($fields['report'] ?? null),
            $fields['rejection_reason'] ?? null,
            $createdBy,
            $now,
            $now,
            $fields['confirmed_at'] ?? null,
            $fields['finished_at'] ?? null,
            $fields['file_purged_at'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Writes `$changes` over the run as it is and stamps the last movement.
     *
     * @param array<string, mixed> $changes UPDATABLE column => value
     */
    public function update(Run $run, array $changes, string $now): Run
    {
        foreach (array_keys($changes) as $column) {
            if (!in_array($column, self::UPDATABLE, true)) {
                throw new \InvalidArgumentException('Column ' . $column . ' is not updatable.');
            }
        }

        $current = [
            'state' => $run->state,
            'file_path' => $run->filePath,
            'progress' => $run->progress,
            'analysis' => $run->analysis,
            'chosen_years' => $run->chosenYears,
            'report' => $run->report,
            'rejection_reason' => $run->rejectionReason,
            'confirmed_at' => $run->confirmedAt,
            'finished_at' => $run->finishedAt,
            'file_purged_at' => null,
            'format' => $run->format,
            'format_version' => $run->formatVersion,
            'installation_id' => $run->installationId,
            'export_id' => $run->exportId,
            'exported_at' => $run->exportedAt,
        ];
        $purgedKept = !array_key_exists('file_purged_at', $changes);
        $next = [...$current, ...$changes];

        $this->pdo->prepare(
            'UPDATE data_migration_runs
                SET state = ?, file_path = ?, progress = ?, analysis = ?, chosen_years = ?, report = ?,
                    rejection_reason = ?, confirmed_at = ?, finished_at = ?,
                    file_purged_at = CASE WHEN ? = 1 THEN file_purged_at ELSE ? END,
                    format = ?, format_version = ?, installation_id = ?, export_id = ?, exported_at = ?,
                    updated_at = ?
              WHERE id = ?'
        )->execute([
            $next['state'],
            $next['file_path'],
            self::encode($next['progress']),
            self::encode($next['analysis']),
            self::encode($next['chosen_years']),
            self::encode($next['report']),
            $next['rejection_reason'],
            $next['confirmed_at'],
            $next['finished_at'],
            $purgedKept ? 1 : 0,
            $next['file_purged_at'],
            $next['format'],
            $next['format_version'],
            $next['installation_id'],
            $next['export_id'],
            $next['exported_at'],
            $now,
            $run->id,
        ]);

        return $this->find($run->id) ?? throw new \LogicException('A run vanished while it was updated.');
    }

    public function find(int $id): ?Run
    {
        $stmt = $this->pdo->prepare('SELECT * FROM data_migration_runs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function latestFor(string $importerKey): ?Run
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM data_migration_runs WHERE importer_key = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$importerKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The runs still holding a deposited file whose last movement is older
     * than `$before`.
     *
     * @return list<Run>
     */
    public function findWithFileIdleSince(string $before): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM data_migration_runs WHERE file_path IS NOT NULL AND updated_at < ? ORDER BY id'
        );
        $stmt->execute([$before]);

        return array_map(fn (array $row): Run => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * A run left `running` with no slice since `$before` was interrupted —
     * its page was closed. Its last movement is left as it was, so the 24 h
     * of the purge still count from the last slice.
     *
     * @return int how many runs this marked
     */
    public function interruptRunningIdleSince(string $before): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE data_migration_runs SET state = 'interrupted' WHERE state = 'running' AND updated_at < ?"
        );
        $stmt->execute([$before]);

        return $stmt->rowCount();
    }

    /**
     * Every file path a run still references, for the orphan sweep.
     *
     * @return list<string>
     */
    public function referencedFilePaths(): array
    {
        $stmt = $this->pdo->query('SELECT file_path FROM data_migration_runs WHERE file_path IS NOT NULL');

        return $stmt === false ? [] : array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Run
    {
        $parameters = [];
        if ($row['source_parameters'] !== null && $row['source_parameters'] !== '') {
            $decoded = json_decode($this->encryption->decrypt((string) $row['source_parameters'], self::ENCRYPTION_CONTEXT), true);
            $parameters = is_array($decoded) ? $decoded : [];
        }

        return new Run(
            (int) $row['id'],
            (string) $row['importer_key'],
            (string) $row['state'],
            self::nullableString($row['file_path']),
            self::nullableString($row['file_name']),
            $row['file_size'] === null ? null : (int) $row['file_size'],
            $parameters,
            self::nullableString($row['format']),
            self::nullableString($row['format_version']),
            self::nullableString($row['installation_id']),
            self::nullableString($row['export_id']),
            self::nullableString($row['exported_at']),
            self::decodeObject($row['progress']),
            self::decodeObject($row['analysis']),
            array_values(array_filter(self::decodeObject($row['chosen_years']), 'is_string')),
            self::decodeObject($row['report']),
            self::nullableString($row['rejection_reason']),
            $row['created_by'] === null ? null : (int) $row['created_by'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            self::nullableString($row['confirmed_at']),
            self::nullableString($row['finished_at'])
        );
    }

    private static function encode(mixed $value): mixed
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeObject(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
