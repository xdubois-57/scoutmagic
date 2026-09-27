<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

/**
 * A badge and everybody who wore it in one scout year — one block of the
 * Badges holders pages (issue #621). Never empty: a badge nobody wore is
 * not listed at all.
 */
final class BadgeHolders
{
    /**
     * @param list<BadgeHolder> $holders
     */
    public function __construct(
        public readonly Badge $badge,
        public readonly array $holders
    ) {
    }

    /** Generated per section and renamed with it — never by hand. */
    public function isAutomatic(): bool
    {
        return $this->badge->referentSectionId !== null;
    }
}
