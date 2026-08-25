<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Journal\JournalService;

/**
 * How long a Desk import — its CSV, its roster snapshot, its row — is
 * kept, and the purge that enforces it.
 *
 * **In scout years, not in a number of imports.** A count would break the
 * invoice verification silently and late: `fees` needs November's snapshot
 * for the deposit invoice and February's for the settlement. Six imports
 * in between — a start-of-season correction, a late registration, the year
 * rollover — and November is gone. The treasurer finds out in June. A
 * retention expressed in seasons protects the snapshot by construction.
 *
 * **Whole seasons, and everything at once.** A kept file without its
 * snapshot, or the reverse, is half a dossier that answers nothing —
 * which is precisely what keeping the file was for. So the unit of
 * deletion is a scout year, and the row, the file and the snapshot go
 * together or none of them goes.
 */
class ImportRetentionService
{
    /**
     * How many scout years of imports are kept: the current one and the
     * previous one.
     *
     * A `SettingService` key rather than a constant, unlike the barrier's
     * threshold — this is a retention period a unit is entitled to decide
     * for itself under its own RGPD register, not a protection that only
     * works while nobody lowers it.
     */
    public const SETTING_KEY = 'import_retention_scout_years';
    public const DEFAULT_YEARS = 2;

    public function __construct(
        private \PDO $pdo,
        private ImportJournalRepository $importJournalRepository,
        private RosterSnapshotRepository $rosterSnapshotRepository,
        private FileRepository $fileRepository,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private JournalService $journal,
        private string $storagePath
    ) {
    }

    /**
     * The configured retention, in scout years, never below 1.
     *
     * An unset or non-numeric setting falls back to the default; an
     * explicit 0 does not — somebody typing 0 means "as short as
     * possible", not "whatever the default is", and the two must not read
     * the same. The floor is a season because the current year's
     * snapshots are what the invoice verification reads all year long:
     * dropping them in March is not a retention choice anybody can
     * meaningfully make.
     */
    public function retentionYears(): int
    {
        $configured = $this->settingService->get(self::SETTING_KEY);
        if ($configured === null || !is_numeric($configured)) {
            return self::DEFAULT_YEARS;
        }

        return max(1, (int) $configured);
    }

    /**
     * The scout years whose imports are past the retention window.
     *
     * Ordered by `start_date` descending, the kept window starts at the
     * newest year — future years included, since a season prepared in
     * advance is by definition not past anything — and runs to the
     * current year plus (retention − 1) previous ones. Everything older
     * is purged.
     *
     * @return int[] scout_years.id, oldest last
     */
    public function yearsBeyondRetention(): array
    {
        $years = $this->scoutYearService->getAll();
        if ($years === []) {
            return [];
        }

        // getAll() is oldest-first; this reasons in "seasons back from
        // now", so it wants the reverse. Sorted here rather than assumed,
        // because the day that order changes upstream is the day this
        // silently starts deleting the wrong end of the history.
        usort($years, static fn(array $a, array $b): int => strcmp($b['start_date'], $a['start_date']));

        $current = $this->scoutYearService->getCurrentYear();
        $currentIndex = null;
        foreach ($years as $index => $year) {
            if ((int) $year['id'] === (int) $current['id']) {
                $currentIndex = $index;
                break;
            }
        }
        if ($currentIndex === null) {
            return [];
        }

        $lastKeptIndex = $currentIndex + $this->retentionYears() - 1;

        return array_map(
            static fn(array $year): int => (int) $year['id'],
            array_slice($years, $lastKeptIndex + 1)
        );
    }

    /**
     * Delete every import of every out-of-window scout year, file and
     * snapshot included.
     *
     * Returns how many import rows went. The database work runs in one
     * transaction so a season is never half-deleted; the encrypted blobs
     * are unlinked afterwards, because a filesystem cannot join a
     * transaction and a file removed before a rollback could not be put
     * back. The worst case is therefore an orphan blob on disk whose
     * `files` row is gone — unreachable through `/files/{id}`, and
     * reported below rather than silently left.
     */
    public function purge(): int
    {
        $years = $this->yearsBeyondRetention();
        if ($years === []) {
            return 0;
        }

        /** @var array<int, string> $paths file id => absolute path on disk */
        $paths = [];
        foreach ($years as $scoutYearId) {
            foreach ($this->importJournalRepository->findRecordsByYear($scoutYearId) as $import) {
                if ($import->fileId === null) {
                    continue;
                }
                $file = $this->fileRepository->findById($import->fileId);
                if ($file !== null) {
                    $paths[$file->id] = $this->storagePath . '/' . $file->relativePath;
                }
            }
        }

        $importCount = 0;
        $snapshotCount = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($years as $scoutYearId) {
                $snapshotCount += $this->rosterSnapshotRepository->deleteForYear($scoutYearId);
                $importCount += $this->importJournalRepository->deleteForYear($scoutYearId);
            }

            // Last, and only once the import rows that referenced them are
            // gone: import_journal.file_id is ON DELETE SET NULL, so the
            // reverse order would leave the rows pointing at nothing for
            // the length of the transaction.
            foreach (array_keys($paths) as $fileId) {
                $this->fileRepository->delete($fileId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $orphans = 0;
        foreach ($paths as $path) {
            if (is_file($path) && !@unlink($path)) {
                $orphans++;
            }
        }

        if ($importCount > 0) {
            $this->journal->log(
                'core',
                'desk_imports_purged',
                'info',
                'Imports Desk supprimés au terme de leur durée de conservation : ' . $importCount . ' import(s)',
                [
                    'scout_year_count' => count($years),
                    'import_count' => $importCount,
                    'snapshot_count' => $snapshotCount,
                    'retention_years' => $this->retentionYears(),
                    'undeleted_files' => $orphans,
                ]
            );
        }

        return $importCount;
    }
}
