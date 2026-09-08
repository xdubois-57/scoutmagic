<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Task;

use Core\File\FileRepository;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * One-shot backfill: generates the thumb/md derivatives for news images
 * uploaded before the module grew its variant pipeline. The templates
 * render /files/{id}/thumb|md, and FileController::variant() deliberately
 * never falls back to the original — without this pass, every article
 * image from before the pipeline would 404 forever.
 *
 * Seeded once from the composition root, guarded by the non-editable
 * `news_image_variants_backfilled` setting (same runtime-flag pattern as
 * the finance module's own `…_seeded` bookkeeping settings); the handler
 * flips the flag when it is done, so the pass never runs twice. Fully
 * idempotent regardless: an already-present derivative is skipped, so an
 * interrupted run simply resumes on its rescheduled successor.
 */
class GenerateImageVariantsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'generate_image_variants';
    public const REFERENCE = 'backfill';
    public const DONE_FLAG = 'news_image_variants_backfilled';

    /**
     * How many images one pass re-encodes before handing over.
     *
     * The pass used to walk the WHOLE library in one go, and re-encoding
     * two derivatives of a multi-megabyte photograph is seconds of CPU
     * each: on a unit with a few hundred article images it could not
     * finish inside a shared host's time limit, and a run killed halfway
     * left the flag unset and started again from the top on the next
     * pass — the same first images re-examined every time, the last ones
     * never reached. A slice that re-arms itself finishes, however long
     * the library is. Same shape as Task\SendPendingTicketsHandler and
     * mass_mail's send_batch.
     */
    private const BATCH_SIZE = 25;

    /**
     * @param array<string, mixed> $payload `after_id`: resume past this
     *     file id — see the cursor's own note in handle().
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $afterId = (int) ($payload['after_id'] ?? 0);

        $fileRepository = new FileRepository($context->connection->getPdo());
        $variantService = new ImageVariantService(
            $fileRepository,
            new ImageVariantProcessor(),
            $context->storagePath
        );

        $images = 0;
        $generated = 0;
        $remaining = false;
        $lastId = $afterId;

        // `findIdsByPathPrefix()` is ORDER BY id, so a high-water mark is
        // a sound cursor.
        //
        // **Without one, a file that can never be processed blocks the
        // whole backfill.** `generate()` swallows a source it cannot read
        // or decode — the upload path does the same — so such a file
        // still has no derivative afterwards and is picked up again on
        // the very next pass. With fewer than BATCH_SIZE of them that
        // only wastes a slot; with BATCH_SIZE or more (the shape a
        // partial storage restore leaves: `files` rows whose bytes are
        // gone), every pass spends its whole batch on the same files,
        // finds work « remaining », re-arms with no delay and never
        // reaches the images behind them — a hot loop that also writes a
        // journal line each time and never sets the flag.
        //
        // Resuming past what the last pass already examined bounds the
        // walk: an unprocessable file costs one attempt, once, and the
        // scan runs out.
        foreach ($fileRepository->findIdsByPathPrefix('news/images/') as $fileId) {
            if ($fileId <= $afterId) {
                continue;
            }

            $file = $fileRepository->findById($fileId);
            if ($file === null) {
                $lastId = $fileId;
                continue;
            }

            $missing = [];
            foreach (ImageVariantService::VARIANTS as $variant) {
                if ($variantService->resolvePath($file->relativePath, $variant) === null) {
                    $missing[] = $variant;
                }
            }
            if ($missing === []) {
                // Already done — cheap to check, and what makes the pass
                // idempotent and resumable whatever happened before.
                $lastId = $fileId;
                continue;
            }

            if ($images >= self::BATCH_SIZE) {
                // The rest belongs to the next pass; the flag stays unset
                // so nothing calls this finished. $lastId is NOT advanced
                // here: this file has not been examined.
                $remaining = true;
                break;
            }

            $images++;
            foreach ($missing as $variant) {
                // generate() never throws — an image that cannot be
                // decoded is simply left without derivatives, exactly
                // like the upload path. The cursor is what stops such a
                // file being retried for ever.
                $variantService->generate($fileId, $variant);
                $generated++;
            }
            $lastId = $fileId;
        }

        // A one-shot pass that runs once in an installation's life, and
        // whose failure shows up months later as an article image that
        // 404s. Saying it happened, and on how many files, is the whole
        // difference between « la reprise n'a jamais tourné » and « elle a
        // tourné et cette image-là n'a pas pu être décodée ».
        $context->journal->log(
            'news',
            'news_image_variants_backfilled',
            'info',
            sprintf(
                'Reprise des vignettes d\'articles : %d image(s) traitée(s), %d déclinaison(s) produite(s)%s.',
                $images,
                $generated,
                $remaining ? ', reprise réarmée pour la suite' : ''
            ),
            [
                'images' => $images,
                'generated' => $generated,
                'remaining' => $remaining,
                // Where the next pass picks up: the id this one examined
                // last, so a file that produced nothing is behind it.
                'after_id' => $lastId,
            ]
        );

        if ($remaining) {
            // Straight away rather than on a schedule: there is work left
            // and nothing to wait for. Through `rearmAfter()` rather than
            // `scheduleAfter()`, because a fixed reference re-armed from
            // inside a handler IS a recurring chain for as long as the
            // backlog lasts: unguarded, a second occurrence queued by the
            // composition root's seed would keep a duplicate chain alive
            // for ever instead of standing down on its next pass.
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter('news', self::TASK_KEY, self::REFERENCE, 0, ['after_id' => $lastId]);

            return;
        }

        $context->settings->setInternal(self::DONE_FLAG, '1', 'news');
    }
}
