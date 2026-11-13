<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Service\BodyImageAccess;

/**
 * One pass over every article, putting the images of its text on the
 * floor of that text (issue #1110), then closing the text images no saved
 * article claims.
 *
 * Service\BodyImageAccess does it at every save of an article's text now;
 * nothing ever did it for the articles saved before, whose images were
 * all stored `public` — a « Chef d'Unité » article's photographs included.
 * Seeded once by public/index.php until DONE_FLAG is set, on the model of
 * RealignCoverImageAccessHandler.
 */
class RealignBodyImageAccessHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'realign_body_image_access';
    public const REFERENCE = 'backfill';
    public const DONE_FLAG = 'news_body_image_access_realigned';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $access = BodyImageAccess::forPdo($pdo);

        $articles = 0;
        $realigned = 0;
        foreach ((new ArticleRepository($pdo))->findAll() as $article) {
            $articles++;
            $realigned += $access->syncArticle($article->id);
        }
        $closed = $access->closeUnclaimed();

        // An access floor moved in both directions without anybody
        // watching: the counts are what tells a maintainer, months later,
        // whether an image readable today was made so here or by an author.
        $context->journal->log(
            'news',
            'news_body_image_access_realigned',
            'security',
            sprintf(
                'Reprise des accès aux images insérées dans les actualités : %d article(s) parcouru(s), '
                    . '%d image(s) réalignée(s), %d image(s) jamais enregistrée(s) refermée(s).',
                $articles,
                $realigned,
                $closed
            ),
            ['articles' => $articles, 'realigned' => $realigned, 'closed' => $closed]
        );

        $context->settings->setInternal(self::DONE_FLAG, '1', 'news');
    }
}
