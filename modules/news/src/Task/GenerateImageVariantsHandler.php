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

    public function handle(array $payload, TaskContext $context): void
    {
        $fileRepository = new FileRepository($context->connection->getPdo());
        $variantService = new ImageVariantService(
            $fileRepository,
            new ImageVariantProcessor(),
            $context->storagePath
        );

        foreach ($fileRepository->findIdsByPathPrefix('news/images/') as $fileId) {
            $file = $fileRepository->findById($fileId);
            if ($file === null) {
                continue;
            }

            foreach (ImageVariantService::VARIANTS as $variant) {
                if ($variantService->resolvePath($file->relativePath, $variant) === null) {
                    // generate() never throws — an image that cannot be
                    // decoded is simply left without derivatives, exactly
                    // like the upload path.
                    $variantService->generate($fileId, $variant);
                }
            }
        }

        $context->settings->setInternal(self::DONE_FLAG, '1', 'news');
    }
}
