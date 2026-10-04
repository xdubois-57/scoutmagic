<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\News\Api\ArticleAction;
use Modules\News\Api\ArticleActionProviderInterface;
use Modules\News\Api\ArticleNote;
use Modules\Social\Api\SocialSharingInterface;

/**
 * « Partager » in an article's editor (ARCHITECTURE.md §7.6), and the line
 * that takes its place when there is nowhere to publish.
 *
 * It opens the one composer, prefilled — there is no article share page any
 * more (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01). Same reasoning
 * as {@see AlbumShareAction}, including why the question is `canPublish()`
 * rather than the Meta accounts alone.
 */
final class ArticleShareAction implements ArticleActionProviderInterface
{
    public const PATH = '/medias-sociaux/nouvelle/article/';

    public function __construct(
        private readonly SocialSharingInterface $sharing,
        private readonly ShareViewer $viewer
    ) {
    }

    public function actionsFor(int $articleId): array
    {
        return $this->canPublish()
            ? [new ArticleAction('Partager', self::PATH . $articleId, 'bi-share', true)]
            : [];
    }

    public function noteFor(int $articleId): ?ArticleNote
    {
        if ($this->canPublish()) {
            return null;
        }

        return new ArticleNote(
            ShareViewer::NO_DESTINATION,
            $this->viewer->mayConfigureMeta() ? 'Configurer les médias sociaux' : null,
            $this->viewer->mayConfigureMeta() ? ShareViewer::CONFIG_PATH : null
        );
    }

    private function canPublish(): bool
    {
        return $this->sharing->canPublish($this->viewer->email, $this->viewer->role, $this->viewer->userAccountId);
    }
}
