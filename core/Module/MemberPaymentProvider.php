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
}
