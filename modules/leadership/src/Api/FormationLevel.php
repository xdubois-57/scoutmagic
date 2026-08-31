<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Api;

/**
 * What a raw Desk formation wording resolves to, as seen from outside the
 * leadership module (ARCHITECTURE.md §7.5).
 *
 * Deliberately not the module's own `FormationStep`: an enum living
 * outside `Api\` cannot be named by a consumer, and a contract that hands
 * one back forces exactly the import the boundary exists to prevent. What
 * a consumer needs is not the case — it is the two answers the case
 * carries, plus a label to print.
 *
 * **`recognised` is the field to read before drawing a conclusion.** False
 * means the site cannot say what the wording means, which is a different
 * statement from "this person holds nothing": `countsForFederationDiscount`
 * is false in both cases, and a consumer that treats the pair as one will
 * report a missing reduction on a unit whose export is merely worded
 * differently.
 */
final class FormationLevel
{
    public function __construct(
        /** The stored step value — 't1', 'bacv', 'unknown'… Stable; safe to persist. */
        public readonly string $code,
        /** French label, for display. Never an identifier. */
        public readonly string $label,
        /** False only when the site could not resolve the wording at all. */
        public readonly bool $recognised,
        /** Recognised by the ONE for its supervision ratio — the BACV alone. */
        public readonly bool $countsForOneRatio,
        /** Opens the federation's fee reduction for a qualified animateur. */
        public readonly bool $countsForFederationDiscount
    ) {
    }
}
