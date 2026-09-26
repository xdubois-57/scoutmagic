<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Modules\News\Api\ArticleShareSourceInterface;
use Modules\News\Api\SharedArticle;

/**
 * The news module as the social module sees it: one article, described
 * only to the account that may edit it.
 */
final class FakeArticleSource implements ArticleShareSourceInterface
{
    public const EDITOR_ACCOUNT = 7;

    public function __construct(public ?SharedArticle $article)
    {
    }

    public function describe(int $articleId, string $role, int $accountId): ?SharedArticle
    {
        return $this->article !== null && $this->article->id === $articleId && $accountId === self::EDITOR_ACCOUNT
            ? $this->article
            : null;
    }
}
