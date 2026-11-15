<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\ScoutYear\AuthorizationYearService;

/**
 * Deletes the 'desk' status rows of addresses Desk no longer gives their
 * member (issue #1100) — MemberEmailRepository::deleteStaleDeskOverrides()
 * with the years it must keep.
 *
 * Those are the years login reads, resolved the way login resolves them
 * (AuthorizationYearService: the public year, the date-computed one, the
 * staff year), and never `scout_years.is_current`, which every row carries
 * as 1 for ever. One place, so the Desk import and the one-time pass in
 * public/index.php cannot keep different years alive.
 *
 * Nothing here grants or refuses anything: no login or access lookup reads
 * a 'desk' row any more. This keeps them from piling up, out of sight, on
 * addresses the unit replaced.
 */
final class StaleDeskEmailCleanup
{
    public function __construct(
        private MemberEmailRepository $memberEmails,
        private AuthorizationYearService $authorizationYears
    ) {
    }

    /**
     * @param int[] $alsoKeptYearIds a year the caller is writing, e.g. the
     *        one a Desk import targets, kept whatever its dates
     * @return int how many rows went
     */
    public function run(\DateTimeImmutable $today, array $alsoKeptYearIds = []): int
    {
        return $this->memberEmails->deleteStaleDeskOverrides(
            $today,
            [...$this->authorizationYears->resolve()->ids(), ...$alsoKeptYearIds]
        );
    }
}
