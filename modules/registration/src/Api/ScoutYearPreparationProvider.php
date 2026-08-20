<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The module's second public API (ARCHITECTURE.md §7.5), alongside
 * ScoutYearTransitionVetoProvider: it lets the "Année scoute" workflow
 * (Core\ScoutYear\ScoutYearTransitionService) tell whether the Passage
 * page still has work waiting, without core ever knowing what a passage
 * is or which tables hold one.
 *
 * Wired as a nullable dependency in the composition root, only when this
 * module is enabled. When it is null the workflow hides its three
 * preparation steps entirely — Départs, Passage and the confirmation of
 * registrations are this module's pages, and a step pointing at a page
 * that does not exist would be worse than no step at all.
 *
 * Both counts are about the year Passage itself targets — the public year
 * plus one (module spec §18.2) — never whatever year an admin happens to
 * be previewing. Core passes no year for exactly that reason: the module
 * owns that rule, and duplicating it on the calling side is how the two
 * would eventually disagree.
 */
interface ScoutYearPreparationProvider
{
    /**
     * Animés who change branch next year and therefore need a destination
     * section — whether or not one has been chosen yet.
     */
    public function countPassages(): int;

    /**
     * Of those, how many still have no destination section chosen. Zero
     * means the Passage page has nothing left to decide, which is also
     * true — legitimately — when there is no passage at all this year.
     */
    public function countUnassignedPassages(): int;
}
