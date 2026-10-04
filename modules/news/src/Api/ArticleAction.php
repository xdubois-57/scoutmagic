<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * One button another module adds to an article's editor: what it says,
 * where it leads, which Bootstrap icon it carries (`bi-…`).
 */
final class ArticleAction
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly string $icon,
        /**
         * Draw the icon alone, with the label as its accessible name and
         * its tooltip, at a 44 × 44 touch target (design.md). For an
         * action whose icon says it — « Partager » — beside controls that
         * matter more on the page.
         */
        public readonly bool $iconOnly = false,
    ) {
    }
}
