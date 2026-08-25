<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * One person inside a frozen roster — foreign keys and codes only, no
 * name and no birth date (see `schema/core.sql`). A screen that
 * needs a readable person joins back to `member_years` on
 * (memberId, the snapshot's scout year).
 */
final class RosterSnapshotMember
{
    public function __construct(
        public readonly int $memberId,
        public readonly ?int $feeCategoryId,
        public readonly ?int $sectionId,
        public readonly ?string $functionRole,
        public readonly ?string $formationLevel,
        /**
         * The same main function's own id. The role says what access it
         * carries, this says what it IS — a member can change function
         * without changing role, and the import report reports both.
         */
        public readonly ?int $functionId,
        public readonly bool $leaving
    ) {
    }
}
