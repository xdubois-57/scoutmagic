<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/** One frozen roster composition: which import, when, how many people. */
final class RosterSnapshot
{
    public function __construct(
        public readonly int $id,
        public readonly int $scoutYearId,
        public readonly \DateTimeImmutable $takenAt,
        public readonly int $memberCount
    ) {
    }
}
