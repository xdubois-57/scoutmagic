<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\News\Api\ArticleAction;
use Modules\News\Api\ArticleActionProviderInterface;
use Modules\Social\Api\SocialSharingInterface;

/**
 * « Partager » in an article's editor (ARCHITECTURE.md §7.6) — offered only
 * while there is somewhere to publish.
 */
final class ArticleShareAction implements ArticleActionProviderInterface
{
    public const PATH = '/partage/actualite/';

    public function __construct(private readonly SocialSharingInterface $sharing)
    {
    }

    public function actionsFor(int $articleId): array
    {
        return $this->sharing->connectedDestinations() === []
            ? []
            : [new ArticleAction('Partager', self::PATH . $articleId, 'bi-share')];
    }
}
