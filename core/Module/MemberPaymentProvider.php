<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to show "what this member still
 * owes" on their own page (Core\Member\MemberPageService), without core
 * depending on the module directly. Same precedent as Core\Module\
 * FormationPathProvider (ARCHITECTURE.md §7.4).
 *
 * **This hook decides nothing about who may look.** The member page has
 * already answered that question — Core\Http\Controller\MemberController
 * ::show() admits the member themselves (their Desk address, which for a
 * young member is their parents') and a chief or admin — and
 * MemberPageService resolves this provider under the same flag as every
 * other personal block. A provider that re-derived its own audience
 * would be a second answer waiting to disagree with that one.
 */
interface MemberPaymentProvider
{
    /**
     * How many closed rows getSettledPayments() may return. On the
     * interface rather than in the module, so the page can say « les 20
     * plus récents » without guessing, and a second implementation
     * cannot quietly answer at a different scale.
     */
    public const SETTLED_LIMIT = 20;

    /**
     * Everything this member still owes, most recent first, or an empty
     * list when they owe nothing.
     *
     * Deliberately NOT scoped to a scout year: an unpaid receivable from
     * last year is still unpaid, and hiding it the day the year turns
     * would quietly write the debt off. Settled and abandoned ones are
     * already gone — the page shows what to do, not an account
     * statement.
     *
     * @return list<MemberPaymentView>
     */
    public function getOpenPayments(int $memberId): array;

    /**
     * The demands that are over — paid, abandoned, refunded — most
     * recent first, capped at self::SETTLED_LIMIT.
     *
     * **A second method rather than a parameter on the first**, and that
     * is the decision worth writing down. `getOpenPayments()` is already
     * consumed by the member's own page and by the homepage band, and
     * both want open receivables and nothing else. Widening its
     * signature would ripple a change through callers that asked for
     * nothing — so this is the deliberate exception to the "one hook,
     * one method" rule: two genuinely different questions, two methods.
     *
     * **Only the admin member page calls this.** A parent reading their
     * child's page does not need to re-read their 2023 transfers, and
     * mixing closed rows into the open ones would drown the two lines
     * that actually call for an action.
     *
     * Capped because a member present for ten years accumulates dozens
     * of closed rows, and this page is a summary: the complete payment
     * history belongs to the finance module, which is built for it.
     *
     * @return list<MemberSettledPaymentView>
     */
    public function getSettledPayments(int $memberId): array;
}
