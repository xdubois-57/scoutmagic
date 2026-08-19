<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Movement;

/**
 * The outcome of classifying one member's year-over-year movement. The
 * previous section/branch ids are carried along (never names — resolving a
 * display name is the caller's job, from data it already has in memory) so
 * a consumer can explain "came from section X" without an extra query.
 */
final class MemberMovementResult
{
    public function __construct(
        public readonly MemberMovementStatus $status,
        public readonly ?int $previousSectionId = null,
        public readonly ?int $previousAgeBranchId = null
    ) {
    }
}
