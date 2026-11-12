<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Task;

use Core\File\FileRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Gives every file that predates `files.public_id` its opaque identifier
 * (issue #1109, `Core\File\FilePublicIds`), a slice per pass.
 *
 * A task rather than inline work in `public/index.php`: on a library of
 * thousands of files, filling them on the request path meant thousands of
 * writes in whatever request came first — an API call, the offline
 * manifest poll — which is what the composition root forbids (« never do
 * migration work inline on a normal page load »). Same shape as
 * `Modules\News\Task\GenerateImageVariantsHandler`: a slice, then a
 * re-arm with no delay while rows remain, then the done flag that stops
 * the seed.
 *
 * Until it reaches a file, that file has no address (`FilePublicIds::
 * urlFor()` returns an empty string): an installation upgraded with
 * existing files shows them again within a few cron passes.
 */
final class BackfillFilePublicIdsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'backfill_file_public_ids';
    public const REFERENCE = 'backfill';
    public const DONE_FLAG = 'files_public_ids_backfilled';

    /** Rows one pass fills: a few hundred single-row updates, well inside any time limit. */
    public const BATCH_SIZE = 500;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $files = new FileRepository($context->connection->getPdo());
        $filled = $files->backfillPublicIds(self::BATCH_SIZE);
        $remaining = $filled >= self::BATCH_SIZE;

        // A one-shot pass in an installation's life: saying it ran, and on
        // how many files, is what tells « elle n'a jamais tourné » from
        // « elle a tourné » when a file shows no address.
        $context->journal->log(
            'core',
            'file_public_ids_backfilled',
            'info',
            sprintf(
                'Identifiants opaques attribués à %d fichier(s)%s.',
                $filled,
                $remaining ? ', reprise réarmée pour la suite' : ''
            ),
            ['files' => $filled, 'remaining' => $remaining]
        );

        if ($remaining) {
            // Rows may remain: straight on, through rearmAfter() so a seed
            // queued meanwhile stands down rather than doubling the chain.
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, 0);

            return;
        }

        $context->settings->setInternal(self::DONE_FLAG, '1');
    }
}
