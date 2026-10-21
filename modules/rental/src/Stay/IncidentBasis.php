<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * The fingerprint of the incidents a settlement version was computed from
 * (#980, chantier §2.4), stored with the version as
 * `rental_settlements.incident_basis_hash`.
 *
 * A version is recorded, then validated later — by then a damage may have
 * been billed differently, waived, or sent back to its assessment, and
 * validating would push a figure that no longer holds onto the booking's
 * receivable. The version validates only while the live incidents give the
 * same fingerprint.
 *
 * Only what moves money goes in: each incident's id, its state, and the
 * amount it adds when it is billed. What an assessment waits for, its
 * reference or a reason for not billing changes nothing the settlement
 * says, so editing them does not outdate a version.
 *
 * Pure.
 */
final class IncidentBasis
{
    /**
     * @param Incident[] $incidents
     */
    public static function hashOf(array $incidents): string
    {
        $basis = array_map(
            static fn(Incident $incident): array => [
                $incident->id,
                $incident->decision->value,
                $incident->decision->entersSettlement() ? $incident->effectiveAmountCents() : null,
            ],
            $incidents
        );
        // The repository's order is the creation order, but the fingerprint
        // must not depend on any reader's order.
        usort($basis, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return hash('sha256', (string) json_encode($basis));
    }
}
