<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Export\TabularSpreadsheet;
use Core\Maintenance\Backup;
use Core\Service\DateInput;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `backups.xlsx` — every row of `backups`, whatever its status, set beside
 * what is actually on disk for it (issue #846).
 *
 * `filesystem.txt` lists names; it cannot say which archive belongs to
 * which backup, nor whether the archive a row points at still exists. That
 * is the question after a restore: the restored database brings back the
 * `backups` and `files` rows of the day it was taken, including rows for
 * archives retention has deleted since — and the list then offers a
 * download that answers 404.
 *
 * **Three things kept apart, because they are three different facts:**
 * the `files` row (what the database says), the file on disk when the
 * package was collected (what is true now), and the last integrity check
 * (what was true when it ran). An archive checked `intact` last week and
 * deleted yesterday reads « intact » and « absente » side by side, which is
 * the finding.
 *
 * **Read-only and cheap.** No archive, dump, key, password or byte of
 * content leaves through here, and no path either — the `files` id is what
 * a reader lines up with the rest. Existence is an `is_file()`; no hash is
 * computed (the stored integrity result is reported as stored). Nothing is
 * corrected or purged.
 *
 * **« Sur ce serveur », said rather than inferred.** These rows are the
 * archives this installation keeps itself. The off-site copies
 * (`SendRemoteBackupHandler`) are written to their destination and leave
 * no `backups` row, so they are not here, and the destination configured
 * today is never attributed to an archive of the past.
 */
class BackupsCollector implements SupportCollectorInterface
{
    /** Retention keeps the table small; this only bounds the unexpected. */
    public const MAX_ROWS = 500;

    public const LOCAL_STORAGE = 'Sur ce serveur';

    private const ERROR_MAX_LENGTH = 300;

    public function name(): string
    {
        return 'backups';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $stmt = $context->pdo()->prepare(
            'SELECT b.id, b.type, b.status, b.error_message, b.integrity_status, b.integrity_checked_at,
                    b.created_at, b.completed_at, b.file_id, b.db_dump_file_id,
                    a.id AS archive_row, a.relative_path AS archive_path, a.size_bytes AS archive_size,
                    d.id AS dump_row, d.relative_path AS dump_path, d.size_bytes AS dump_size
             FROM backups b
             LEFT JOIN files a ON a.id = b.file_id
             LEFT JOIN files d ON d.id = b.db_dump_file_id
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT ' . (self::MAX_ROWS + 1)
        );
        $stmt->execute();
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (count($entries) > self::MAX_ROWS) {
            $entries = array_slice($entries, 0, self::MAX_ROWS);
            $context->addNote('Inventaire tronqué aux ' . self::MAX_ROWS . ' sauvegardes les plus récentes');
        }

        $rows = [];
        $absent = 0;
        foreach ($entries as $entry) {
            $archive = $this->presence(
                $context,
                $entry['file_id'] ?? null,
                $entry['archive_row'] ?? null,
                $entry['archive_path'] ?? null
            );
            $dump = $this->presence(
                $context,
                $entry['db_dump_file_id'] ?? null,
                $entry['dump_row'] ?? null,
                $entry['dump_path'] ?? null
            );
            if ($archive === 'absente' || $dump === 'absente') {
                $absent++;
            }
            $type = self::asString($entry['type'] ?? null);
            $rows[] = [
                self::asString($entry['id'] ?? null),
                $type,
                Backup::typeLabel($type),
                self::asString($entry['status'] ?? null),
                self::localTimestamp(self::asString($entry['created_at'] ?? null)),
                self::localTimestamp(self::asString($entry['completed_at'] ?? null)),
                self::LOCAL_STORAGE,
                self::asString($entry['file_id'] ?? null),
                self::rowState($entry['file_id'] ?? null, $entry['archive_row'] ?? null),
                $archive,
                self::asString($entry['archive_size'] ?? null),
                self::asString($entry['db_dump_file_id'] ?? null),
                self::rowState($entry['db_dump_file_id'] ?? null, $entry['dump_row'] ?? null),
                $dump,
                self::asString($entry['dump_size'] ?? null),
                self::asString($entry['integrity_status'] ?? null),
                self::localTimestamp(self::asString($entry['integrity_checked_at'] ?? null)),
                // A failure message quotes whatever threw — a PDO error, a
                // path —, so it goes through the archive's redaction.
                $context->redact(self::asString($entry['error_message'] ?? null), self::ERROR_MAX_LENGTH),
            ];
        }

        $context->addNote(count($rows) . ' sauvegarde(s) enregistrée(s)');
        if ($absent > 0) {
            $context->addNote($absent . ' sauvegarde(s) dont un fichier est absent du disque');
        }
        $context->addFileFromContent(
            'backups.xlsx',
            TabularSpreadsheet::build(
                [
                    'Sauvegarde n°', 'Type', 'Libellé', 'État',
                    'Créée le (heure locale du serveur)', 'Terminée le',
                    'Stockage',
                    'Archive ZIP — fichier n°', 'Archive ZIP — ligne files',
                    'Archive ZIP — sur le disque à la collecte',
                    'Archive ZIP — taille enregistrée (octets)',
                    'Dump SQL — fichier n°', 'Dump SQL — ligne files', 'Dump SQL — sur le disque à la collecte',
                    'Dump SQL — taille enregistrée (octets)',
                    'Dernier contrôle d\'intégrité', 'Contrôlée le',
                    'Erreur',
                ],
                $rows,
                'Sauvegardes'
            )
        );
    }

    /**
     * Whether the file a row points at is on disk NOW: « présente »,
     * « absente », or « — » when there is nothing to look for.
     */
    private function presence(SupportCollectorContext $context, mixed $fileId, mixed $row, mixed $relativePath): string
    {
        if ($fileId === null || $row === null) {
            return '—';
        }
        $path = self::asString($relativePath);
        if ($path === '' || str_contains($path, '..')) {
            return 'absente';
        }

        return is_file($context->storagePath() . '/' . ltrim($path, '/')) ? 'présente' : 'absente';
    }

    /** Whether the `files` row the backup names exists — a reference can outlive it. */
    private static function rowState(mixed $fileId, mixed $row): string
    {
        if ($fileId === null) {
            return 'aucune';
        }

        return $row === null ? 'absente' : 'présente';
    }

    /** With its own offset, like the journal's cells. */
    private static function localTimestamp(string $storedValue): string
    {
        if ($storedValue === '') {
            return '';
        }

        return DateInput::fromStorage($storedValue)?->format('Y-m-d H:i:sP') ?? $storedValue;
    }

    private static function asString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
