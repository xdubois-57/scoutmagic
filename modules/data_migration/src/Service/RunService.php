<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

use Core\Archive\ArchiveRejectedException;
use Core\File\ChunkedUploadStore;
use Core\File\UploadException;
use Core\Journal\JournalService;
use Modules\DataMigration\Importer\FormSourceImporter;
use Modules\DataMigration\Importer\Importer;
use Modules\DataMigration\Importer\SourceRejectedException;
use Modules\DataMigration\Manifest\ManifestException;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;

/**
 * The life of a run, from the deposit to the report — every state change
 * the page asks for, each refused when the run is not in a state that
 * allows it.
 *
 * ```
 * uploaded ──(slices: verify, analyse)──▶ analysed ──confirm──▶ confirmed
 *     │                                       │                     │
 *     └─ rejected archive ─▶ abandoned ◀──────┴── abandon ──────────┤
 *                                                                  ▼
 *            done ◀──(slices)── running ◀──resume── interrupted ◀──pause / 10 min of silence
 * ```
 *
 * Nothing persistent is written before the confirmation (step 4) except
 * the run's own row and the past scout years the administrator ticked in
 * that same confirmation.
 */
class RunService
{
    /** A `running` run with no slice for this long was left by its page: it is interrupted. */
    public const STALE_RUNNING_SECONDS = 600;

    /** A deposited file is deleted this long after the run last moved. */
    public const FILE_RETENTION_SECONDS = 86400;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private RunRepository $runs,
        private ImportEngine $engine,
        private MissingScoutYears $years,
        private UploadStorage $storage,
        private ChunkedUploadStore $chunks,
        private JournalService $journal,
        private ?\Closure $clock = null
    ) {
    }

    /**
     * The latest run of this import, with a run left `running` by a closed
     * page shown for what it is: interrupted.
     */
    public function current(Importer $importer): ?Run
    {
        $this->runs->interruptRunningIdleSince($this->ago(self::STALE_RUNNING_SECONDS));

        return $this->runs->latestFor($importer->key());
    }

    /**
     * One fragment of a deposited archive. On the last one the archive is
     * opened and its manifest read: a refused archive becomes a run in
     * state `abandoned` with its reason, never an exception the page has to
     * read.
     *
     * @return array{received: int, run: ?Run}
     *
     * @throws UploadException on a fragment the store refuses, or a deposit
     *         while an import of this kind is moving
     */
    public function receiveChunk(
        Importer $importer,
        string $uploadId,
        string $sessionId,
        int $offset,
        string $chunkPath,
        bool $isLast,
        string $fileName,
        ?int $userAccountId
    ): array {
        $format = $importer->archiveFormat() ?? throw new UploadException('Cet import ne se fait pas par dépôt de fichier.');

        if ($offset === 0) {
            $this->assertNothingMoving($importer);
        }

        $assembled = $this->chunks->appendChunk($uploadId, $sessionId, $offset, $chunkPath, $isLast, $format->maxArchiveBytes);
        if ($assembled === null) {
            return ['received' => $this->chunks->receivedBytes($uploadId, $sessionId), 'run' => null];
        }

        $this->assertNothingMoving($importer);
        $this->abandonPrevious($importer);

        $relative = $this->storage->newArchivePath();
        $size = (int) (filesize($assembled) ?: 0);
        if (!@rename($assembled, $this->storage->absolute($relative))) {
            $this->chunks->discard($uploadId, $sessionId);
            throw new UploadException('Le fichier reçu n\'a pas pu être enregistré. Recommencez l\'envoi.');
        }
        @chmod($this->storage->absolute($relative), 0600);
        $this->chunks->discard($uploadId, $sessionId);

        $run = $this->createRun($importer, [
            'state' => Run::UPLOADED,
            'file_path' => $relative,
            'file_name' => self::cleanFileName($fileName),
            'file_size' => $size,
            'format' => $format->format,
            'progress' => ['phase' => ImportEngine::PHASE_VERIFY, 'position' => 0, 'measured' => 0],
        ], $userAccountId);

        return ['received' => $size, 'run' => $this->readManifest($importer, $run)];
    }

    /** Bytes this session already sent under that upload id — 0 for an id it never used, or a malformed one. */
    public function receivedBytes(string $uploadId, string $sessionId): int
    {
        try {
            return $this->chunks->receivedBytes($uploadId, $sessionId);
        } catch (UploadException) {
            return 0;
        }
    }

    /**
     * Step 1 of an import whose source is a form.
     *
     * @param array<string, mixed> $fields
     *
     * @throws SourceRejectedException
     * @throws UploadException while an import of this kind is moving
     */
    public function acceptForm(FormSourceImporter $importer, array $fields, ?int $userAccountId): Run
    {
        $this->assertNothingMoving($importer);

        $workspace = $this->storage->newWorkspace();
        try {
            $accepted = $importer->acceptSource($fields, $this->storage->absolute($workspace), (int) $userAccountId);
        } catch (\Throwable $e) {
            $this->storage->delete($workspace);
            throw $e;
        }

        $file = $accepted->filePath !== null ? $this->storage->relative($accepted->filePath) : null;
        if ($file === null || !str_starts_with($file, $workspace . '/')) {
            $this->storage->delete($workspace);
            $file = null;
        }

        $this->abandonPrevious($importer);

        return $this->createRun($importer, [
            'state' => Run::UPLOADED,
            'file_path' => $file,
            'file_name' => $accepted->label !== null ? self::cleanFileName($accepted->label) : null,
            'source_parameters' => $accepted->parameters,
            'format' => $accepted->format,
            'installation_id' => $accepted->installationId,
            'progress' => ['phase' => ImportEngine::PHASE_ANALYSE, 'position' => 0],
            'analysis' => ImportEngine::emptyAnalysis(),
        ], $userAccountId);
    }

    /** One slice of the analysis or of the execution, whichever the run is in. */
    public function advance(Importer $importer, Run $run): Run
    {
        if (!in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true)) {
            return $run;
        }

        return $this->engine->slice($importer, $run);
    }

    /**
     * Step 4: the administrator confirmed, having ticked the past scout
     * years to create (D5). Only years the analysis found missing and that
     * are creatable are taken, whatever the request carried.
     *
     * @param list<string> $chosenYears
     */
    public function confirm(Run $run, array $chosenYears): ?Run
    {
        if ($run->state !== Run::ANALYSED) {
            return null;
        }

        $offered = array_keys((array) ($run->analysis['missing_years'] ?? []));
        $chosen = array_values(array_filter(
            array_unique($chosenYears),
            fn (string $label): bool => in_array($label, $offered, true) && $this->years->isCreatable($label)
        ));
        $created = $this->years->create($chosen);

        $now = $this->now();
        $run = $this->runs->update($run, [
            'state' => Run::CONFIRMED,
            'chosen_years' => $chosen,
            'confirmed_at' => $now,
            'progress' => ['phase' => ImportEngine::PHASE_EXECUTE, 'position' => 0],
            'report' => ImportEngine::emptyReport(),
        ], $now);

        $this->journal->log('data_migration', 'import_confirmed', 'info', 'Import de migration de données confirmé', [
            'run_id' => $run->id,
            'importer' => $run->importerKey,
            'to_import' => (int) ($run->analysis['to_import'] ?? 0),
            'scout_years_created' => $created,
        ], $run->createdBy);

        return $run;
    }

    /** « Mettre en pause ». */
    public function pause(Run $run): Run
    {
        if (!in_array($run->state, [Run::CONFIRMED, Run::RUNNING], true)) {
            return $run;
        }

        return $this->runs->update($run, ['state' => Run::INTERRUPTED], $this->now());
    }

    /** « Reprendre l'import »: back to `running`, from the cursor. */
    public function resume(Run $run): Run
    {
        if ($run->state !== Run::INTERRUPTED || $run->filePath === null && $run->sourceParameters === []) {
            return $run;
        }

        return $this->runs->update($run, ['state' => Run::RUNNING], $this->now());
    }

    /**
     * « Abandonner et effacer le fichier » (D9): what is imported stays,
     * nothing is undone, the deposited file goes.
     */
    public function abandon(Run $run): Run
    {
        if (!$run->isActive()) {
            return $run;
        }

        $this->storage->delete($run->filePath);
        $now = $this->now();
        $run = $this->runs->update($run, [
            'state' => Run::ABANDONED,
            'file_path' => null,
            'file_purged_at' => $now,
            'finished_at' => $now,
        ], $now);

        $this->journal->log('data_migration', 'import_abandoned', 'info', 'Import de migration de données abandonné', [
            'run_id' => $run->id,
            'importer' => $run->importerKey,
        ]);

        return $run;
    }

    /**
     * « Relancer le même fichier »: a new run on the file the finished one
     * still holds. The correspondence table makes everything already
     * imported « déjà présent ».
     */
    public function restart(Importer $importer, Run $run, ?int $userAccountId): ?Run
    {
        if ($run->state !== Run::DONE || ($run->filePath === null && $run->sourceParameters === [])) {
            return null;
        }

        $file = $run->filePath;
        $this->runs->update($run, ['file_path' => null], $this->now());

        $new = $this->createRun($importer, [
            'state' => Run::UPLOADED,
            'file_path' => $file,
            'file_name' => $run->fileName,
            'file_size' => $run->fileSize,
            'source_parameters' => $run->sourceParameters,
            'format' => $run->format,
            'installation_id' => $importer->archiveFormat() === null ? $run->installationId : null,
            'progress' => $importer->archiveFormat() === null
                ? ['phase' => ImportEngine::PHASE_ANALYSE, 'position' => 0]
                : ['phase' => ImportEngine::PHASE_VERIFY, 'position' => 0, 'measured' => 0],
            'analysis' => $importer->archiveFormat() === null ? ImportEngine::emptyAnalysis() : [],
        ], $userAccountId);

        return $importer->archiveFormat() === null ? $new : $this->readManifest($importer, $new);
    }

    /** When this run's file will be deleted if nothing moves it, or null when it holds none. */
    public function fileExpiresAt(Run $run): ?\DateTimeImmutable
    {
        if ($run->filePath === null) {
            return null;
        }

        return \Core\Service\DateInput::fromStorage($run->updatedAt)?->add(new \DateInterval('PT' . self::FILE_RETENTION_SECONDS . 'S'));
    }

    private function readManifest(Importer $importer, Run $run): Run
    {
        try {
            $source = $this->engine->openSource($importer, $run);
        } catch (ManifestException | ArchiveRejectedException $e) {
            return $this->engine->reject($run, $e->getMessage());
        }

        $manifest = $source->manifest;
        $source->archive()->close();
        if ($manifest === null) {
            return $run;
        }

        return $this->runsUpdate($run, [
            'format_version' => $manifest->formatVersion,
            'installation_id' => $manifest->installationId,
            'export_id' => $manifest->exportId,
            'exported_at' => $manifest->exportedAt,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createRun(Importer $importer, array $fields, ?int $userAccountId): Run
    {
        $id = $this->runs->create($importer->key(), $fields, $userAccountId, $this->now());
        $run = $this->runs->find($id) ?? throw new \LogicException('A run vanished as it was created.');

        $this->journal->log('data_migration', 'run_created', 'info', 'Source de migration de données déposée', [
            'run_id' => $run->id,
            'importer' => $run->importerKey,
            'file_size' => $run->fileSize,
        ], $userAccountId);

        return $run;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function runsUpdate(Run $run, array $changes): Run
    {
        return $this->runs->update($run, $changes, $this->now());
    }

    /**
     * @throws UploadException
     */
    private function assertNothingMoving(Importer $importer): void
    {
        $current = $this->current($importer);
        if ($current !== null && $current->state === Run::RUNNING) {
            throw new UploadException(
                'Un import de ce type est en cours. Mettez-le en pause ou abandonnez-le avant d\'en déposer un autre.'
            );
        }
    }

    /** A new source replaces whatever run of this import was still waiting. */
    private function abandonPrevious(Importer $importer): void
    {
        $previous = $this->runs->latestFor($importer->key());
        if ($previous !== null && $previous->isActive()) {
            $this->abandon($previous);
        }
        // A finished run still holding its file for « Relancer » gives it up.
        if ($previous !== null && !$previous->isActive() && $previous->filePath !== null) {
            $this->storage->delete($previous->filePath);
            $this->runs->update($previous, ['file_path' => null, 'file_purged_at' => $this->now()], $this->now());
        }
    }

    private static function cleanFileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $base);
        if (!mb_check_encoding($clean, 'UTF-8')) {
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
        }

        return mb_substr($clean !== '' ? $clean : 'archive.zip', 0, 200);
    }

    private function clockNow(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }

    private function now(): string
    {
        return $this->clockNow()->format('Y-m-d H:i:s');
    }

    private function ago(int $seconds): string
    {
        return $this->clockNow()->sub(new \DateInterval('PT' . $seconds . 'S'))->format('Y-m-d H:i:s');
    }
}
