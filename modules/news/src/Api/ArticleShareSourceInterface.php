<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * An article as another module may publish it (`social`, docs/chantiers/
 * CHANTIER-partage-social.md) — answered only to someone who may edit it,
 * by the news module's own rule.
 */
interface ArticleShareSourceInterface
{
    /**
     * Null when the article does not exist or this person may not edit it.
     *
     * @param string $role the requester's role (`Core\Security\Role` value)
     */
    public function describe(int $articleId, string $role, int $accountId): ?SharedArticle;
}
