<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * A decided incident as the renter's tracking page shows it (#980, §22.5):
 * what was observed, when, and whether it is billed.
 *
 * **A separate object, not an `Incident` with fields to hide.** It has no
 * slot for the internal texts — what an assessment waits for, its
 * reference, why a damage is not billed — nor for the amount a manager
 * proposed, so no template can print one by mistake (ARCHITECTURE.md
 * §7.6, rule 3). An incident still being assessed never becomes one: the
 * page only learns that one exists (`RenterIncidents`).
 */
final class RenterIncident
{
    public function __construct(
        public readonly string $description,
        public readonly \DateTimeImmutable $reportedAt,
        /** What is billed, null when the damage is not billed. */
        public readonly ?int $chargedCents
    ) {
    }

    public function isCharged(): bool
    {
        return $this->chargedCents !== null;
    }
}
