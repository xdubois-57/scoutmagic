<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * A module that contributes an action to an article's editor
 * (ARCHITECTURE.md §7.6) — the news module renders what it is given and
 * never learns who gave it.
 *
 * Asked once per editor page, for the one article shown, which is already
 * behind the news module's own rule (its author, or an administrator).
 */
interface ArticleActionProviderInterface
{
    /**
     * @return list<ArticleAction>
     */
    public function actionsFor(int $articleId): array;

    /**
     * One line explaining why this provider offers no action right now, or
     * null when it has nothing to explain — either because it is offering
     * an action, or because its absence needs no words.
     *
     * Asked alongside actionsFor() rather than instead of it: a provider
     * that answers both is contributing a button AND a caveat, which is
     * its own business, not this page's.
     */
    public function noteFor(int $articleId): ?ArticleNote;
}
