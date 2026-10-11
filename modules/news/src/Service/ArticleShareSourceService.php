<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Config\SettingService;
use Core\Security\Role;
use Modules\News\Api\ArticleShareSourceInterface;
use Modules\News\Api\SharedArticle;

/**
 * The news module's answer to « what would you publish of this article? »
 * — the same share address and the same shareability rule the article's
 * own Open Graph tags use (NewsController::socialMetaContext()), so what a
 * Page links to is what a crawler previews.
 */
final class ArticleShareSourceService implements ArticleShareSourceInterface
{
    public function __construct(
        private readonly ArticleService $articles,
        private readonly SettingService $settings
    ) {
    }

    public function describe(int $articleId, string $role, int $accountId): ?SharedArticle
    {
        $article = $this->articles->findById($articleId);
        if ($article === null || !$this->articles->canEdit($article, Role::fromString($role), $accountId)) {
            return null;
        }
        // Nothing is shared of an article that is not online (issue #845):
        // the address would lead every reader to « pas encore en ligne ».
        if (!ArticleService::isOnline($article)) {
            return null;
        }

        $base = rtrim((string) ($this->settings->get('base_url') ?: ''), '/');
        $url = $base === '' ? '' : $base . ($article->shortUrlCode !== null
            ? '/s/' . $article->shortUrlCode
            : '/news/' . $article->id);

        return new SharedArticle(
            $article->id,
            $article->title,
            $url,
            $article->imageFileId,
            $this->articles->isSociallyShareable($article)
        );
    }

    /**
     * Existence and an address, and nothing else — the question a public
     * page asks to decide one button (issue #706, IT-04).
     *
     * The short address when the article has one, like {@see describe()}
     * and like the article's own `og:url`: a visitor may have been handed
     * either form, and both lead to the same page.
     */
    public function pagePath(int $articleId): ?string
    {
        $article = $this->articles->findById($articleId);
        if ($article === null) {
            return null;
        }

        return $article->shortUrlCode !== null
            ? '/s/' . $article->shortUrlCode
            : '/news/' . $article->id;
    }
}
