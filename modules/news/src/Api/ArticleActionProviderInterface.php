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
}
