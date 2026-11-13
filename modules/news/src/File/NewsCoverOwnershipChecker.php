<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Service\ArticleService;

/**
 * Who may read an article's COVER image, decided at every request for it
 * (issue #845).
 *
 * The guard's `role_min` check runs first and stays the visibility's floor
 * (Service\ArticleService::coverImageRoleMin()). This adds the one thing a
 * flat floor cannot say: whether the article is online. Before it is, the
 * cover is refused to everybody but those who manage the news — the same
 * rule as the article's own page.
 *
 * **Why at the request and not by a task.** The list and the article page
 * load the cover through /files/{id}, which this guards. An article
 * programmed for 8:00 is in the list at 8:00 by its query alone; a cover
 * opened by a task passing at 8:01 would show a broken image for that
 * minute to every visitor. Asking here, at each request, opens the cover
 * at exactly the instant the article goes online and closes it again on a
 * return to draft, with nothing to flip and nothing to forget.
 *
 * With the module disabled, nothing answers for `news_cover` and the guard
 * refuses — fail-closed, as for every owner type.
 */
final class NewsCoverOwnershipChecker implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'news_cover';

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ArticleService $articleService
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        $article = $this->articles->findById($ownerId);
        if ($article === null) {
            return false;
        }

        return $this->articleService->isLive($article) || ArticleService::managesNews($currentRole);
    }
}
