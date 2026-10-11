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
use Modules\News\Service\ArticleService;

/**
 * Tells the author that their scheduled article is online (issue #845,
 * part 2) — the one thing about a scheduled publication that no query can
 * deduce.
 *
 * **Nothing else waits for this task.** The lists, the page, the form and
 * the cover all compare the publication date with the clock themselves
 * (ArticleService::isLive(), ArticleRepository::liveCondition(),
 * File\NewsCoverOwnershipChecker): at the minute set, the article is out
 * whether this ran or not. A run a few minutes late only delays the
 * notification.
 *
 * Scheduled per article by the editor's « Programmer » (the reference is
 * the article), at the publication time, and re-scheduled when that time
 * changes — so it runs with the site's cron, on the minute, without a
 * chain ticking every minute for articles nobody scheduled.
 *
 * **Once, whatever happens.** The notice is claimed on the article itself
 * (`publication_notice_pending`, ArticleRepository::
 * claimPublicationNotice()) before anything is sent: a replayed run, a
 * stale row left from an earlier time, or a « Publier maintenant » that
 * came first all find nothing to claim. A manual publication lowers the
 * flag and is never announced — the author was there to see it.
 */
class PublishScheduledArticlesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'publish_scheduled_articles';
    public const NOTIFICATION_TYPE = 'news.article_published';

    public static function referenceFor(int $articleId): string
    {
        return 'article-' . $articleId;
    }

    public function handle(array $payload, TaskContext $context): void
    {
        $articles = new ArticleRepository($context->connection->getPdo());
        $article = $articles->findById((int) ($payload['article_id'] ?? 0));
        // Deleted, returned to draft, or moved later: the run for the new
        // time does the work, if any is left.
        if ($article === null || !ArticleService::isOnline($article)) {
            return;
        }

        if (!$articles->claimPublicationNotice($article->id)) {
            return;
        }

        // The moment the article went out by itself, nobody at the
        // keyboard: the journal is where it can be found afterwards.
        $context->journal->log(
            'news',
            'article_online',
            'info',
            "Article « {$article->title} » en ligne à l'heure programmée",
            ['article_id' => $article->id, 'published_at' => $article->publishedAt]
        );

        if ($context->notifications === null) {
            return;
        }

        $context->notifications->dispatch(
            self::NOTIFICATION_TYPE,
            [['userAccountId' => $article->createdBy, 'memberId' => null]],
            [
                'title' => 'Actualité en ligne',
                'body' => 'Votre actualité « ' . $article->title . ' » est en ligne.',
                'url' => '/news/' . $article->id,
            ]
        );
    }
}
