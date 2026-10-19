<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Api;

/**
 * Api\FeeAccuracySummaryProvider's answer for one scout year.
 *
 * `$membersWithoutAddress` is not a detail: a member whose address
 * normalises to nothing is neither compliant nor in breach, so a summary
 * without it would read as "everybody was checked" — the reason the page's
 * own report carries it too.
 */
final class FeeAccuracySummary
{
    public function __construct(
        /** Households whose encoded tariff does not match what Desk holds today (« À corriger dans Desk »). */
        public readonly int $householdsToCorrect,
        /** Members no household statement covers, for want of a usable address (« Sans adresse »). */
        public readonly int $membersWithoutAddress,
        /** Households a chef d'unité set aside, whose decision still covers the same people (« Ignorés »). */
        public readonly int $householdsSetAside,
        /** Every household the report looked at, set-aside ones included. */
        public readonly int $householdsChecked
    ) {
    }
}
