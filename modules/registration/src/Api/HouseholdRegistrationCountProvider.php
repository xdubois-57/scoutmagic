<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

use Core\Member\Household\HouseholdKey;

/**
 * The module's own public API (ARCHITECTURE.md §7.5) letting Core\Member\
 * FeeEstimationService optionally count 'accepted'/'encoded' registration
 * requests alongside existing members when suggesting a household fee
 * category — wired as a nullable constructor dependency in the
 * composition root, only when this module is enabled. Core never depends
 * on the module directly; this interface is the only contract between
 * them.
 *
 * **It speaks of households, never of addresses** (issue #630). The core
 * hands over a {@see HouseholdKey}: an identity it derives, that this
 * module stores and compares but never builds or reads. Until #630 the
 * contract took the address blind index as a string, and the module
 * recomputed that index itself when a request was created — so it had to
 * know the core's normalizer and purpose string, and a change on either
 * side would have broken the match without anything failing.
 */
interface HouseholdRegistrationCountProvider
{
    /**
     * Count of 'accepted'/'encoded' registration requests submitted from
     * $household, targeting $scoutYearId, excluding $excludeRequestId (a
     * request being estimated on its own fiche must never count itself).
     */
    public function countInHousehold(HouseholdKey $household, int $scoutYearId, ?int $excludeRequestId): int;

    /**
     * The same count for a whole batch of addresses, in one query.
     *
     * Core\Member\Household\HouseholdService enumerates every household of
     * a scout year at once; asking {@see countInHousehold()} per household
     * would be one query per household, over the entire unit. There is no
     * exclusion parameter here on purpose: that one exists for a request
     * estimating its own fiche, which is a single-address question by
     * construction.
     *
     * @param HouseholdKey[] $households
     * @return array<string, int> {@see HouseholdKey::storable()} => count; a
     *         household with no matching request is simply absent, never a
     *         zero row
     */
    public function countsInHouseholds(array $households, int $scoutYearId): array;
}
