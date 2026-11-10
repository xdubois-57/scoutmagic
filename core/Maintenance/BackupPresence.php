<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\File\FileRepository;

/**
 * Whether a backup's files are on this server NOW (issue #847).
 *
 * The integrity check (`BackupIntegrity`) runs once a day and can date from
 * yesterday; a restore can bring back the row of an archive retention
 * deleted since, and the list used to offer a download that answered 404.
 * What a row offers — download, restore — follows what is on the disk at
 * display time, and **presence outranks the last integrity result**: a
 * backup checked « intact » yesterday whose archive is gone today is gone.
 *
 * An existence check and nothing more: no digest is computed here, the
 * daily pass keeps that job. The way a component resolves to a file is the
 * one BackupIntegrity uses ({@see self::pathOf()}), so the two can never
 * disagree on where a backup's archive is.
 */
final class BackupPresence
{
    /** The refusal of an export (`Backup::EXPORT_TYPE`), said the same way everywhere. */
    public const EXPORT_REFUSAL = 'Restauration refusée. Une sauvegarde « Base de données » est un export : sans '
        . 'les fichiers du site, elle ne se restaure pas ici. Rien n\'a été modifié.';

    public function __construct(
        private readonly FileRepository $files,
        private readonly string $storagePath
    ) {
    }

    /**
     * The file a backup component points at, or null when its `files` row
     * is gone. Shared with BackupIntegrity::verify().
     */
    public function pathOf(int $fileId): ?string
    {
        $file = $this->files->findById($fileId);

        return $file === null ? null : rtrim($this->storagePath, '/') . '/' . $file->relativePath;
    }

    /**
     * Each declared component, present or not; null for one the backup does
     * not have — a row without an archive is never « Archive absente ».
     *
     * **A completed backup always has a dump**: every path that completes
     * one registers it. A completed row without one has lost it — its
     * `files` row was deleted and `ON DELETE SET NULL` cleared the
     * reference, by a purge interrupted between the two — and is « gone »,
     * not « never had one », or a restore of it would only be refused after
     * the safety copy.
     *
     * @return array{archive: ?bool, dump: ?bool}
     */
    public function of(Backup $backup): array
    {
        $completed = $backup->status === 'completed';
        $dump = match (true) {
            $backup->dbDumpFileId !== null => $this->isPresent($backup->dbDumpFileId),
            $completed => false,
            default => null,
        };
        // The archive likewise — `file_id` has the same ON DELETE SET NULL,
        // and BackupRetention::forget() removes the archive's row first.
        // An export is not restored here whatever its files say.
        $archive = match (true) {
            $backup->fileId !== null => $this->isPresent($backup->fileId),
            $completed && $backup->type !== Backup::EXPORT_TYPE => false,
            default => null,
        };

        return ['archive' => $archive, 'dump' => $dump];
    }

    /** Whether every component the backup declares is present. */
    public function isComplete(Backup $backup): bool
    {
        return !in_array(false, $this->of($backup), true);
    }

    /**
     * Why this backup cannot be restored because of what is on the disk, or
     * null when everything it declares is there — the sentence the restore
     * form and the restore task both say.
     */
    public function refusal(Backup $backup): ?string
    {
        $components = $this->of($backup);
        $what = match (true) {
            $components['archive'] === false && $components['dump'] === false
                => 'ses fichiers ne sont plus sur ce serveur',
            $components['archive'] === false => 'son archive n\'est plus sur ce serveur',
            $components['dump'] === false => 'sa base de données n\'est plus sur ce serveur',
            default => null,
        };

        return $what === null
            ? null
            : 'Restauration refusée. Cette sauvegarde ne peut plus être restaurée : ' . $what
                . '. Rien n\'a été modifié.';
    }

    private function isPresent(int $fileId): bool
    {
        $path = $this->pathOf($fileId);

        return $path !== null && is_file($path) && is_readable($path);
    }
}
