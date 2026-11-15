<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\File\FileRepository;
use Core\Security\Role;
use Modules\News\Service\ArticleService;
use Modules\News\Service\BodyImageAccess;

/**
 * Who may read an image inserted in an article's text, decided at every
 * request for it (issue #1110).
 *
 * The guard's `role_min` check runs first: the floor of the widest text
 * naming the image (Service\BodyImageAccess). This adds what a floor
 * written at save time cannot know: which of those articles is online
 * NOW, and visible to THIS reader. The image is served when at least one
 * article naming it is online and passes ArticleService::canView() for the
 * reader — the article page's own rule — or when the reader manages the
 * news, who must see a draft's images while writing it.
 *
 * Asked here rather than written down, for the cover's reason (#845):
 * an article goes online at its time with nothing flipping, and an image
 * taken out of a text names nobody any more — both are answered at the
 * very request, with nothing to re-sync and nothing to forget.
 *
 * The owner id is the file's own id: the question is about every article
 * naming it, not about one. With the module disabled, nothing answers for
 * `news_text_image` and the guard refuses — fail-closed.
 */
final class NewsTextImageOwnershipChecker implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'news_text_image';

    public function __construct(
        private readonly FileRepository $files,
        private readonly BodyImageAccess $bodyImages,
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
        if (ArticleService::managesNews($currentRole)) {
            return true;
        }

        $file = $this->files->findById($ownerId);
        if ($file === null) {
            return false;
        }

        foreach ($this->bodyImages->articlesNaming($file) as $article) {
            if ($this->articleService->isLive($article) && $this->articleService->canView($article, $currentRole)) {
                return true;
            }
        }

        return false;
    }
}
