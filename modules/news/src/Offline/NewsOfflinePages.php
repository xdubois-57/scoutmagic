<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Offline;

use Core\Offline\OfflinePageProvider;
use Core\Security\Role;
use Modules\News\Controller\NewsController;
use Modules\News\Service\ArticleService;

/**
 * The news the installed app holds offline (#883): the list as its first
 * page shows it, and each article of that page — exactly the articles this
 * role sees listed, nothing older.
 *
 * Never `/news/manage`, `/news/create` nor `/news/scan`: those are pages of
 * their own under the same prefix, and only the addresses named here are
 * ever cached. An older article opened online stays out of the cache, and
 * offline its link is greyed.
 */
final class NewsOfflinePages implements OfflinePageProvider
{
    public function __construct(private ArticleService $articleService)
    {
    }

    public function pagesFor(Role $role, ?string $email): array
    {
        $pages = ['/news'];
        $firstPage = $this->articleService->findPublicListPage($role, NewsController::LIST_PER_PAGE, 0);
        foreach ($firstPage['articles'] as $article) {
            $pages[] = '/news/' . $article->id;
        }

        return $pages;
    }
}
