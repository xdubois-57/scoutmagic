<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Task;

use Core\File\FileRepository;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Service\ArticleService;

/**
 * One-shot backfill: puts every article cover's `files.role_min` back on
 * Service\ArticleService::coverImageRoleMin(), which create() and
 * update() have applied since issue #211 but which nothing ever applied
 * to articles already published.
 *
 * Two different wrongs to correct, and only the first is new:
 *
 * - a « Membres connectés » article's cover was uploaded `identified`,
 *   and its og:image is now emitted — so without this pass every
 *   existing members-only article is posted with a preview whose picture
 *   403s, which is the feature not working rather than a detail;
 * - an article whose VISIBILITY was changed after publication kept the
 *   floor of the old one, in both directions. That predates #211 (the
 *   editor uploads nothing when only the visibility changes, so nothing
 *   re-synced the file), and a public-to-staff move leaving a cover
 *   readable by anyone holding its URL is the direction worth fixing
 *   without waiting for the author's next save.
 *
 * Seeded once from the composition root, guarded by the non-editable
 * `news_cover_access_realigned` setting — the same runtime-flag pattern
 * as GenerateImageVariantsHandler next door. Fully idempotent: it writes
 * the floor each article's visibility calls for, so an interrupted run
 * simply resumes on its rescheduled successor.
 */
class RealignCoverImageAccessHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'realign_cover_image_access';
    public const REFERENCE = 'backfill';
    public const DONE_FLAG = 'news_cover_access_realigned';

    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $fileRepository = new FileRepository($pdo);

        $covers = 0;
        $realigned = 0;
        foreach ((new ArticleRepository($pdo))->findAll() as $article) {
            if ($article->imageFileId === null) {
                continue;
            }

            $file = $fileRepository->findById($article->imageFileId);
            if ($file === null) {
                continue;
            }

            $covers++;
            $expected = ArticleService::coverImageRoleMin($article->visibility);
            if ($file->roleMin === $expected) {
                continue;
            }

            $fileRepository->updateRoleMin($article->imageFileId, $expected);
            $realigned++;
        }

        // This pass moves an access floor, in both directions, without
        // anybody watching. Saying how many it moved is what tells a
        // maintainer reading the journal months later whether a cover
        // that is readable today was made so here or by an author.
        $context->journal->log(
            'news',
            'news_cover_access_realigned',
            'security',
            sprintf(
                'Reprise des accès aux images d\'articles : %d couverture(s) parcourue(s), %d réalignée(s).',
                $covers,
                $realigned
            ),
            ['covers' => $covers, 'realigned' => $realigned]
        );

        $context->settings->setInternal(self::DONE_FLAG, '1', 'news');
    }
}
