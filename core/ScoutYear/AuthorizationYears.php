<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * The scout years an access decision is allowed to reason about, and
 * which one of them is the public year.
 *
 * Immutable, and deliberately dumb: everything about how the set is
 * built — the deduplication, the one-year bound, the refusal to create a
 * row — lives in AuthorizationYearService. What lives here is the one
 * distinction every consumer needs afterwards, because the rule that
 * uses this set is not symmetric: a role obtained in the PUBLIC year
 * counts whatever it is, a role obtained in any other year of the set
 * counts only from `intendant` up (Core\Security\RoleResolver).
 *
 * The session preview is not in here and cannot be put in here — it is
 * not a constructor parameter of the service that builds this, so no
 * caller can hand it one by accident. A preview is chosen by the person
 * it would authorise, over any year that ever existed, so a set built
 * from it would let whoever was Staff d'U in 2019-2020 walk back in.
 * Previewing decides what is DISPLAYED and never who may see it; see
 * ARCHITECTURE.md §4 « Scout year » and §8.26.
 */
final class AuthorizationYears
{
    /**
     * @param int|null $publicYearId the public year's id, or null when
     *                               the installation has no scout year on
     *                               record at all (a fresh install, before
     *                               the first Desk import)
     * @param int[]    $ids          every year an access decision may use,
     *                               public year first, without duplicates
     */
    public function __construct(
        public readonly ?int $publicYearId,
        public readonly array $ids
    ) {
    }

    /**
     * @return int[]
     */
    public function ids(): array
    {
        return $this->ids;
    }

    public function isPublicYear(int $scoutYearId): bool
    {
        return $this->publicYearId !== null && $scoutYearId === $this->publicYearId;
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }
}
