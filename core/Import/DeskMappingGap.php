<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * One Desk value this installation could not match to anything it knows,
 * as it stands right now (issue #356).
 *
 * Derived, never stored: {@see DeskMappingGapService} recomputes the whole
 * list on every read, so a value somebody resolves disappears from it by
 * itself, with nothing to clean up and no second source of truth to go
 * stale. That is the same decision the receiving end makes for the central
 * page, one version later, and for the same reason.
 *
 * Carries a federal label and a count — never a member, a section name or
 * anything that could single anybody out (SECURITY.md §11).
 */
final class DeskMappingGap
{
    /**
     * @param string $rawValue    the value exactly as Desk spelled it, which
     *                            is the whole point: a maintainer reading
     *                            « Animateur Baladinss » can tell a typo
     *                            from a tariff the federation just invented,
     *                            and a normalised one hides both
     * @param int    $affectedCount how many of the unit's records carry it —
     *                            people for a function or a tariff, sections
     *                            for a branch. Zero is a real answer: a value
     *                            nobody holds any more is still a value this
     *                            code does not recognise
     */
    public function __construct(
        public readonly DeskMappingGapKind $kind,
        public readonly string $rawValue,
        public readonly int $affectedCount
    ) {
    }
}
