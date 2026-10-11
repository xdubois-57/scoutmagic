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

    /**
     * The article's own page on the site, or null when there is no longer
     * an article there.
     *
     * **It carries no content and asks about no caller**, which is why it
     * is not {@see describe()}: the one thing that needs it is the public
     * page of a communication sharing this article (issue #706, IT-04),
     * which has to decide whether to offer a « Voir sur le site » button
     * to a visitor with no account — and `describe()` answers only
     * someone who may EDIT the article, so it would say « gone » for
     * every visitor.
     *
     * The article is not made public by this. The page the path names
     * applies its own visibility rule to whoever follows it, which for a
     * staff-only article means the same refusal as typing the address.
     */
    public function pagePath(int $articleId): ?string;
}
