<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The module's own public API (ARCHITECTURE.md §7.5) letting Core\Member\
 * FeeEstimationService optionally count 'accepted'/'encoded' registration
 * requests alongside existing members when suggesting a household fee
 * category — wired as a nullable constructor dependency in the
 * composition root, only when this module is enabled. Core never depends
 * on the module directly; this interface is the only contract between
 * them.
 */
interface HouseholdRegistrationCountProvider
{
    /**
     * Count of 'accepted'/'encoded' registration requests whose submitted
     * address blind-indexes to $addressBlindIndex, targeting
     * $scoutYearId, excluding $excludeRequestId (a request being
     * estimated on its own fiche must never count itself).
     */
    public function countAtAddress(string $addressBlindIndex, int $scoutYearId, ?int $excludeRequestId): int;
}
