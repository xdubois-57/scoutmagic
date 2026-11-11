<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

use Core\Archive\ArchiveRejectedException;
use Core\Archive\BoundedZipReader;
use Core\Archive\OpenedArchive;
use Core\Database\TransactionScope;
use Core\Journal\JournalService;
use Modules\DataMigration\Importer\ImportContext;
use Modules\DataMigration\Importer\Importer;
use Modules\DataMigration\Importer\ImportSource;
use Modules\DataMigration\Importer\ItemVerdict;
use Modules\DataMigration\Importer\SourceItem;
use Modules\DataMigration\Importer\ThrottledImporter;
use Modules\DataMigration\Manifest\Manifest;
use Modules\DataMigration\Manifest\ManifestException;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;

/**
 * Moves one run forward by one slice of about five seconds.
 *
 * The hosting this site runs on gives a request 30 s and 128 MiB and no
 * background process, so nothing here ever tries to finish: the page polls
 * (`window.ScoutMagicApi.poll()`), each poll runs ONE slice against a
 * deadline, and everything the next slice needs is in the run's row — the
 * same shape as `MaintenanceController::advanceMigration()`. Closing the
 * page stops the import; opening it again resumes from the cursor.
 *
 * Three phases, in this order, each resumable at its position:
 *
 * 1. **verify** (archives only): every member listed in the manifest is
 *    hashed and compared with its SHA-256 — one member per step, the
 *    measured decompressed total carried from slice to slice so the
 *    ceiling bounds the archive and not one slice of it. A wrong
 *    fingerprint rejects the whole archive, before anything is written.
 * 2. **analyse**: every item is classified without writing — the
 *    correspondence table first (D12), then the import's own
 *    `inspect()`, then the scout year it needs (D5).
 * 3. **execute** (after the confirmation): every item is classified again
 *    and, when it is still to import, written **in its own transaction
 *    together with its correspondence row and the run's cursor**. A crash
 *    between two items loses nothing and duplicates nothing; a resumed run
 *    meets the items already written as « déjà présents ». An item that
 *    throws is rolled back, counted as a technical error, and the run
 *    goes on.
 *
 * One slice at a time per run: a named lock, so two tabs on the same
 * import cannot write the same item twice in parallel.
 */
class ImportEngine
{
    public const SLICE_SECONDS = 5.0;
    public const MAX_SAMPLES = 50;

    public const PHASE_VERIFY = 'verify';
    public const PHASE_ANALYSE = 'analyse';
    public const PHASE_EXECUTE = 'execute';

    /** How many times the current slice asked the import's inspect(). */
    private int $inspections = 0;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private \PDO $pdo,
        private RunRepository $runs,
        private ItemMappingRepository $items,
        private MissingScoutYears $years,
        private UploadStorage $storage,
        private ManifestReader $manifests,
        private JournalService $journal,
        private float $sliceSeconds = self::SLICE_SECONDS,
        private ?\Closure $clock = null
    ) {
    }

    /**
     * Runs one slice of whatever phase the run is in. Returns the run as it
     * now is — unchanged when another request holds its lock.
     */
    public function slice(Importer $importer, Run $run): Run
    {
        $lock = 'data_migration_run_' . $run->id;
        if (!\Core\Database\AdvisoryLock::acquire($this->pdo, $lock)) {
            return $run;
        }

        try {
            // Read again under the lock: the row the caller holds may be a
            // slice old.
            $run = $this->runs->find($run->id) ?? $run;
            $deadline = microtime(true) + $this->sliceSeconds;

            if ($run->state === Run::UPLOADED) {
                return $run->phase() === self::PHASE_VERIFY
                    ? $this->verify($importer, $run, $deadline)
                    : $this->analyse($importer, $run, $deadline);
            }

            if (in_array($run->state, [Run::CONFIRMED, Run::RUNNING], true)) {
                // An interrupted run waits for « Reprendre » (RunService::
                // resume()): a poll still in flight when the page paused
                // must not start it again.
                if ($run->state !== Run::RUNNING) {
                    $started = $this->runs->updateIfState($run, Run::CONFIRMED, ['state' => Run::RUNNING], $this->now());
                    if ($started === null) {
                        return $this->fresh($run);
                    }
                    $run = $started;
                }

                return $this->execute($importer, $run, $deadline);
            }

            return $run;
        } finally {
            \Core\Database\AdvisoryLock::release($this->pdo, $lock);
        }
    }

    /**
     * Rejects a run as a whole: the reason is kept for the page, the file
     * is deleted, nothing was written. The journal gets the run id and
     * nothing of the message, which can quote a member's name from the
     * archive.
     */
    public function reject(Run $run, string $reason): Run
    {
        $now = $this->now();
        // Only while the run is still where this request found it: a pause
        // or an abandon that landed meanwhile is the visitor's decision, and
        // a file it already deleted makes every read fail.
        $rejected = $this->runs->updateIfState($run, $run->state, [
            'state' => Run::ABANDONED,
            'rejection_reason' => mb_substr($reason, 0, 500),
            'file_path' => null,
            'file_purged_at' => $now,
            'finished_at' => $now,
        ], $now);
        if ($rejected === null) {
            return $this->fresh($run);
        }
        $this->storage->delete($run->filePath);
        $run = $rejected;

        // During the execution, items may already have been written: they
        // stay (D9), the report keeps counting them, and the journal says
        // which of the two happened.
        $imported = (int) ($run->report['imported'] ?? 0);
        if ($run->phase() === self::PHASE_EXECUTE) {
            $this->journal->log('data_migration', 'import_stopped', 'info', 'Import de migration arrêté : le fichier est devenu illisible en cours d\'import', [
                'run_id' => $run->id,
                'importer' => $run->importerKey,
                'imported' => $imported,
            ]);
        } else {
            $this->journal->log('data_migration', 'archive_rejected', 'info', 'Fichier de migration refusé avant toute écriture', [
                'run_id' => $run->id,
                'importer' => $run->importerKey,
            ]);
        }

        return $run;
    }

    /**
     * The source of a run, opened for reading.
     *
     * @throws ArchiveRejectedException
     * @throws ManifestException
     */
    public function openSource(Importer $importer, Run $run, int $alreadyMeasured = 0): ImportSource
    {
        $format = $importer->archiveFormat();
        $absolute = $run->filePath !== null ? $this->storage->absolute($run->filePath) : null;

        if ($format === null) {
            return new ImportSource(null, null, $run->sourceParameters, $absolute);
        }
        if ($absolute === null) {
            throw new ArchiveRejectedException('Le fichier déposé n\'est plus sur le serveur. Déposez-le à nouveau.');
        }

        $archive = (new BoundedZipReader($format->limits))->open($absolute, $alreadyMeasured);
        try {
            $manifest = $this->manifests->read($archive, $format->format, $format->majorVersion);
        } catch (ManifestException | ArchiveRejectedException $e) {
            $archive->close();
            throw $e;
        }

        return new ImportSource($archive, $manifest, $run->sourceParameters, $absolute);
    }

    private function verify(Importer $importer, Run $run, float $deadline): Run
    {
        try {
            $source = $this->openSource($importer, $run, (int) ($run->progress['measured'] ?? 0));
        } catch (ManifestException | ArchiveRejectedException $e) {
            return $this->reject($run, $e->getMessage());
        }

        $archive = $source->archive();
        $manifest = $source->manifest ?? throw new \LogicException('An archive source has a manifest.');
        $paths = self::inventoryInArchiveOrder($archive, $manifest);
        $position = $run->position();
        // What earlier slices measured, plus what THIS one reads of the
        // members — not the manifest, which every slice re-reads to open the
        // source and would otherwise be counted once per slice.
        $carried = (int) ($run->progress['measured'] ?? 0);
        $openedAt = $archive->measuredTotal();

        try {
            while ($position < count($paths)) {
                $this->manifests->verifyMember($archive, $manifest, $paths[$position]);
                $position++;
                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } catch (ManifestException | ArchiveRejectedException $e) {
            $archive->close();

            return $this->reject($run, $e->getMessage());
        }

        $measured = $carried + ($archive->measuredTotal() - $openedAt);
        $archive->close();

        if ($position < count($paths)) {
            return $this->save($run, Run::UPLOADED, ['progress' => [
                'phase' => self::PHASE_VERIFY,
                'position' => $position,
                'measured' => $measured,
                'steps' => count($paths),
            ]]);
        }

        return $this->save($run, Run::UPLOADED, [
            'progress' => ['phase' => self::PHASE_ANALYSE, 'position' => 0],
            'analysis' => self::emptyAnalysis(),
        ]);
    }

    private function analyse(Importer $importer, Run $run, float $deadline): Run
    {
        try {
            $source = $this->openSource($importer, $run);
        } catch (ManifestException | ArchiveRejectedException $e) {
            return $this->reject($run, $e->getMessage());
        }

        $analysis = $run->analysis !== [] ? $run->analysis : self::emptyAnalysis();
        $position = $run->position();
        $context = $this->context($importer, $run, $source);
        $finished = true;

        try {
            foreach ($importer->items($source, $position) as $itemPosition => $item) {
                try {
                    $verdict = $this->classify($importer, $item, $context, true);
                    $missingYear = $verdict->outcome === ItemVerdict::IMPORT
                        && $verdict->scoutYearLabel !== null
                        && $this->years->resolve($verdict->scoutYearLabel) === null;
                    $analysis = self::countAnalysis($analysis, $item, $verdict, $missingYear);
                } catch (\Throwable $e) {
                    $analysis['errors'] = (int) ($analysis['errors'] ?? 0) + 1;
                    $analysis['total'] = (int) ($analysis['total'] ?? 0) + 1;
                    $analysis['error_samples'] = self::sample($analysis['error_samples'] ?? [], $item->shownReference(), null);
                    $this->journalItemError($run, 'analyse', $e);
                }
                $position = $itemPosition + 1;

                if (microtime(true) >= $deadline) {
                    $finished = false;
                    break;
                }
            }
        } catch (ArchiveRejectedException $e) {
            self::closeSource($source);

            return $this->reject($run, $e->getMessage());
        }
        self::closeSource($source);

        if (!$finished) {
            return $this->save($run, Run::UPLOADED, [
                'progress' => ['phase' => self::PHASE_ANALYSE, 'position' => $position],
                'analysis' => $analysis,
            ]);
        }

        return $this->save($run, Run::UPLOADED, [
            'state' => Run::ANALYSED,
            'progress' => ['phase' => self::PHASE_ANALYSE, 'position' => $position],
            'analysis' => $analysis,
        ]);
    }

    private function execute(Importer $importer, Run $run, float $deadline): Run
    {
        try {
            $source = $this->openSource($importer, $run);
        } catch (ManifestException | ArchiveRejectedException $e) {
            // Verified at the analysis; a file that changed or vanished on
            // the server since is not the visitor's archive any more.
            return $this->reject($run, $e->getMessage());
        }

        $report = $run->report !== [] ? $run->report : self::emptyReport();
        $position = $run->phase() === self::PHASE_EXECUTE ? $run->position() : 0;
        $context = $this->context($importer, $run, $source, true);
        $finished = true;
        // An import whose inspection reads the network asks for fewer items
        // per slice than the deadline alone would allow: the deadline is
        // only checked between two items, and one remote page can take
        // several seconds on its own.
        $this->inspections = 0;
        $inspectionLimit = $importer instanceof ThrottledImporter ? max(1, $importer->inspectionsPerExecutionSlice()) : null;

        try {
            foreach ($importer->items($source, $position) as $itemPosition => $item) {
                // Checked before the item rather than after it, so that the
                // slice which inspects the last item also finishes the run.
                if ($inspectionLimit !== null && $this->inspections >= $inspectionLimit) {
                    $finished = false;
                    break;
                }
                $next = $itemPosition + 1;
                try {
                    $verdict = $this->classify($importer, $item, $context, false);
                } catch (\Throwable $e) {
                    // Counted, and the slice's deadline still applies below:
                    // a connection that keeps failing must not turn one poll
                    // into a walk through the whole source.
                    $verdict = null;
                    $report = self::countError($report, $item);
                    $this->journalItemError($run, 'execute', $e);
                }

                if ($verdict === null) {
                    // Nothing more to do for this item.
                } elseif ($verdict->outcome === ItemVerdict::IMPORT) {
                    try {
                        $run = TransactionScope::run($this->pdo, function () use ($importer, $item, $context, $run, $report, $next): Run {
                            $target = $importer->write($item, $context);
                            $this->items->insert(
                                $context->installationId,
                                $context->format,
                                $item->kind,
                                $item->sourceId,
                                $target->type,
                                $target->id,
                                $run->id,
                                $this->now()
                            );
                            $report['imported'] = (int) $report['imported'] + 1;

                            // Paused or abandoned while this slice ran: the
                            // item is rolled back with the cursor it would
                            // have moved, and the slice stops here.
                            return $this->runs->updateIfState($run, Run::RUNNING, [
                                'progress' => ['phase' => self::PHASE_EXECUTE, 'position' => $next],
                                'report' => $report,
                            ], $this->now()) ?? throw new RunMovedException();
                        });
                        $report = $run->report;
                    } catch (RunMovedException) {
                        self::closeSource($source);

                        return $this->fresh($run);
                    } catch (\Throwable $e) {
                        $report = self::countError($report, $item);
                        $this->journalItemError($run, 'execute', $e);
                    }
                } else {
                    $report = self::countReport($report, $item, $verdict);
                }
                $position = $next;

                if (microtime(true) >= $deadline) {
                    $finished = false;
                    break;
                }
            }
        } catch (ArchiveRejectedException $e) {
            self::closeSource($source);

            return $this->reject($run, $e->getMessage());
        }
        self::closeSource($source);

        if (!$finished) {
            return $this->save($run, Run::RUNNING, [
                'progress' => ['phase' => self::PHASE_EXECUTE, 'position' => $position],
                'report' => $report,
            ]);
        }

        $now = $this->now();
        $done = $this->runs->updateIfState($run, Run::RUNNING, [
            'state' => Run::DONE,
            'progress' => ['phase' => self::PHASE_EXECUTE, 'position' => $position],
            'report' => $report,
            'finished_at' => $now,
        ], $now);
        if ($done === null) {
            return $this->fresh($run);
        }
        $run = $done;

        $this->journal->log('data_migration', 'import_finished', 'info', 'Import de migration de données terminé', [
            'run_id' => $run->id,
            'importer' => $run->importerKey,
            'imported' => (int) $report['imported'],
            'present' => (int) $report['present'],
            'ignored' => array_sum(array_map('intval', (array) $report['ignored'])),
            'errors' => (int) $report['errors'],
        ], $run->createdBy);

        return $run;
    }

    /**
     * What becomes of one item, at the analysis (`$planning`) or right
     * before it is written.
     */
    private function classify(Importer $importer, SourceItem $item, ImportContext $context, bool $planning): ItemVerdict
    {
        if ($item->isUnreadable()) {
            return ItemVerdict::ignored((string) $item->unreadableReason);
        }
        if ($item->sourceId === '') {
            return ItemVerdict::ignored('Identifiant d\'origine absent ou qui n\'est pas une chaîne de caractères');
        }
        if ($this->items->exists($context->installationId, $context->format, $item->kind, $item->sourceId)) {
            return ItemVerdict::present();
        }

        $this->inspections++;
        $verdict = $importer->inspect($item, $context);
        $label = $verdict->scoutYearLabel;
        if ($verdict->outcome !== ItemVerdict::IMPORT || $label === null || $this->years->resolve($label) !== null) {
            return $verdict;
        }

        if (!MissingScoutYears::isLabel($label)) {
            return ItemVerdict::ignored('Année scoute illisible');
        }
        if (!$this->years->isCreatable($label)) {
            return ItemVerdict::ignored('Année scoute en cours ou à venir, absente du site');
        }

        // A past year this site does not have: offered in the preview,
        // and an item whose year was not ticked is ignored.
        return $planning ? $verdict : ItemVerdict::ignored('Année scoute non créée');
    }

    private function context(Importer $importer, Run $run, ImportSource $source, bool $executing = false): ImportContext
    {
        return new ImportContext(
            $run->id,
            (string) $run->installationId,
            $run->format ?? ($importer->archiveFormat() !== null ? $importer->archiveFormat()->format : $importer->key()),
            $run->createdBy,
            $source,
            fn (string $label): ?int => $this->years->resolve($label),
            $executing
        );
    }

    /**
     * The inventory in the order the archive stores its members, so the
     * verification reads the file front to back.
     *
     * @return list<string>
     */
    private static function inventoryInArchiveOrder(OpenedArchive $archive, Manifest $manifest): array
    {
        return array_values(array_filter($archive->names(), static fn (string $name): bool => isset($manifest->files[$name])));
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyAnalysis(): array
    {
        return [
            'total' => 0, 'to_import' => 0, 'present' => 0, 'ambiguous' => 0, 'errors' => 0,
            'ignored' => [], 'tags' => [], 'missing_years' => [], 'samples' => [], 'error_samples' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyReport(): array
    {
        return ['imported' => 0, 'present' => 0, 'errors' => 0, 'ignored' => [], 'samples' => [], 'error_samples' => []];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private static function countAnalysis(array $analysis, SourceItem $item, ItemVerdict $verdict, bool $missingYear): array
    {
        $analysis['total'] = (int) $analysis['total'] + 1;

        switch ($verdict->outcome) {
            case ItemVerdict::IMPORT:
                $analysis['to_import'] = (int) $analysis['to_import'] + 1;
                if ($missingYear && $verdict->scoutYearLabel !== null) {
                    $analysis['missing_years'] = self::increment((array) $analysis['missing_years'], $verdict->scoutYearLabel);
                }
                break;
            case ItemVerdict::PRESENT:
                $analysis['present'] = (int) $analysis['present'] + 1;
                break;
            case ItemVerdict::AMBIGUOUS:
                $analysis['ambiguous'] = (int) $analysis['ambiguous'] + 1;
                $analysis['samples'] = self::sample((array) $analysis['samples'], $item->shownReference(), (string) $verdict->reason);
                break;
            default:
                $analysis['ignored'] = self::increment((array) $analysis['ignored'], (string) $verdict->reason);
                $analysis['samples'] = self::sample((array) $analysis['samples'], $item->shownReference(), (string) $verdict->reason);
        }

        foreach ($verdict->tags as $tag) {
            $analysis['tags'] = self::increment((array) $analysis['tags'], $tag);
        }

        return $analysis;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function countReport(array $report, SourceItem $item, ItemVerdict $verdict): array
    {
        if ($verdict->outcome === ItemVerdict::PRESENT) {
            $report['present'] = (int) $report['present'] + 1;

            return $report;
        }

        $reason = (string) $verdict->reason;
        $report['ignored'] = self::increment((array) $report['ignored'], $reason);
        $report['samples'] = self::sample((array) $report['samples'], $item->shownReference(), $reason);

        return $report;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function countError(array $report, SourceItem $item): array
    {
        $report['errors'] = (int) $report['errors'] + 1;
        $report['error_samples'] = self::sample((array) $report['error_samples'], $item->shownReference(), null);

        return $report;
    }

    /**
     * @param array<mixed> $counts
     * @return array<string, int>
     */
    private static function increment(array $counts, string $key): array
    {
        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;

        return $counts;
    }

    /**
     * @param array<mixed> $samples
     * @return list<array{reference: string, reason: ?string}>
     */
    private static function sample(array $samples, string $reference, ?string $reason): array
    {
        $samples = array_values($samples);
        if (count($samples) < self::MAX_SAMPLES) {
            $samples[] = ['reference' => $reference, 'reason' => $reason];
        }

        return $samples;
    }

    /**
     * The class of what failed and nothing of its message — an importer's
     * exception can quote the data it was writing.
     */
    private function journalItemError(Run $run, string $phase, \Throwable $e): void
    {
        $this->journal->log('data_migration', 'item_failed', 'error', 'Élément de migration non importé à la suite d\'une erreur technique', [
            'run_id' => $run->id,
            'importer' => $run->importerKey,
            'phase' => $phase,
            'exception' => $e::class,
        ]);
    }

    private static function closeSource(ImportSource $source): void
    {
        if ($source->manifest !== null) {
            $source->archive()->close();
        }
    }

    /**
     * Writes a slice's result only while the run is still in the state the
     * slice found it in; otherwise the run as somebody else left it.
     *
     * @param array<string, mixed> $changes
     */
    private function save(Run $run, string $expectedState, array $changes): Run
    {
        return $this->runs->updateIfState($run, $expectedState, $changes, $this->now()) ?? $this->fresh($run);
    }

    private function fresh(Run $run): Run
    {
        return $this->runs->find($run->id) ?? $run;
    }

    private function now(): string
    {
        return ($this->clock !== null ? ($this->clock)() : new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
