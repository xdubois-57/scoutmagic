<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * One animé named by an Api\ScoutYearRegistrationReviewProvider list, as
 * one scout year's Desk row knows them.
 *
 * `$memberYearId` is the row of the year the list is about — the id
 * `/admin/members/{id}` takes — and `$sectionId` that row's section. The
 * name is already decrypted (in the module's repository, SECURITY.md §5);
 * nothing else about the person travels.
 */
final class ScoutYearMember
{
    public function __construct(
        public readonly int $memberId,
        public readonly int $memberYearId,
        public readonly string $name,
        public readonly ?int $sectionId
    ) {
    }
}
