<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract DTO for consuming modules (ARCHITECTURE.md §7.5) — one
 * calendar a consumer may offer as a publication target: an id to store,
 * a label to draw, and whether it is a section's own calendar (a
 * consumer may want to word those differently). No visibility enum, no
 * section id — nothing internal.
 */
final class SelectableCalendar
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly bool $isSection,
    ) {
    }
}
