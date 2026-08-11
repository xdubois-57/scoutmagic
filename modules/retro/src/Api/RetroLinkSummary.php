<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Api;

/**
 * Public contract DTO for consuming modules (ARCHITECTURE.md §7.5) — a
 * retrospective board as seen from outside the retro module: just enough
 * to render a link, never internal ids/tokens/settings.
 */
final class RetroLinkSummary
{
    public function __construct(
        public readonly string $url,
        public readonly string $title
    ) {
    }
}
