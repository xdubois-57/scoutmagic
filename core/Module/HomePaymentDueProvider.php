<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to surface "your family still owes
 * something" on the homepage (Core\Http\Controller\PageController::
 * home()) — without core depending on the module directly. Same
 * precedent, and the same shape, as Core\Module\HomeGroupActivityProvider
 * (ARCHITECTURE.md §7.4).
 *
 * The homepage is where this belongs for the same reason the group
 * activity strip is there: a mail can be missed and a notification needs
 * a granted permission, while the homepage is the one surface every
 * visitor passes through.
 */
interface HomePaymentDueProvider
{
    /**
     * One compact summary of everything the caller's family still owes —
     * a total, one line per member, and the date up to which payments
     * have been read.
     *
     * Returns null when there is nothing to show: for an anonymous
     * visitor, for somebody with no members linked to their address, and
     * for a family that owes nothing. The band is hidden at zero, never
     * rendered empty. The implementation resolves the caller from the
     * session itself, because the answer is entirely per-address — so no
     * access check belongs in the template.
     *
     * A parent of three sees ONE band with three lines, never three
     * bands.
     *
     * `statement_date` is the date of the most recent bank statement the
     * unit has imported for the accounts these demands sit on, or null
     * when none has been imported yet. The template turns it into "les
     * paiements reçus jusqu'au …" followed by a plain-prose delay — there
     * is deliberately no bank-closing computation anywhere, because a
     * constant of hours and working days would be wrong on every public
     * holiday while the sentence absorbs weekends and holidays without
     * ever lying.
     *
     * @return null|array{
     *     total_cents: int,
     *     demands: list<array{member_year_id: int, member_name: string, label: string, amount_cents: int}>,
     *     single_member_year_id: ?int,
     *     statement_date: ?string
     * }
     */
    public function getHomePaymentSummaryForCurrentUser(): ?array;
}
