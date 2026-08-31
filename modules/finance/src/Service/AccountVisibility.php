<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\Role;
use Modules\Finance\Repository\Account;

/**
 * "May this session use this account at all" — the whole rule, written
 * once. A class of its own rather than a method on FinanceService because
 * two different services need the same answer (FinanceService for every
 * page and write route, ReceivablesOverviewService for the reconciliation
 * page), and the previous arrangement — each deciding for itself — is
 * exactly how that page came to be listing accounts no other page would
 * show.
 *
 * FinanceService::isAccountVisibleTo() delegates here, so a controller
 * that already holds the finance service needs no new dependency.
 *
 * It answers the sorting pile's question too
 * ({@see isUnassignedReceiptVisibleTo()}). That one is not about an
 * account — it is about a receipt that has none — but it is built from the
 * same treasurer scope, and putting it anywhere else would mean a second
 * class holding that scope and a second constructor to thread it through.
 */
class AccountVisibility
{
    /**
     * The hierarchical floor for a receipt no account claims: the receipts
     * page's own `role_min`. Explicit rather than read off the route, so
     * the file guard — which has no route — applies the same one.
     */
    public const UNASSIGNED_RECEIPT_FLOOR = Role::INTENDANT;

    public function __construct(private TreasurerScope $treasurerScope)
    {
    }

    /**
     * Two conditions, both required:
     *
     *  - `role_min_view`, the account's own hierarchical floor, unchanged;
     *  - the SECTION, for an account that has one — see treasurerAllows().
     *
     * Deliberately silent about `status`: FinanceService::
     * getAccountsForUser() excludes anything not active because a picker
     * should not offer a draft, while a movement or a receivable booked
     * against an account that has since been archived must stay reachable
     * for whoever was allowed to see it — hiding it would drop money from
     * a total rather than protect anything.
     *
     * Accepts null so a caller that has just resolved an account from
     * client input can ask one question instead of two, and so "no such
     * account" and "not yours" produce the same answer to the client
     * rather than telling it which accounts exist.
     */
    public function isVisibleTo(?Account $account, Role $role): bool
    {
        return $account !== null
            && $role->hasAccess(Role::fromString($account->roleMinView))
            && $this->treasurerAllows($account, $role);
    }

    /**
     * « Qui peut voir et trier un reçu qu'aucun compte ne réclame ? »
     *
     * A receipt arrives by email carrying no IBAN and from an address that
     * animates no single staff. It is a real accounting document, so it is
     * kept with `account_id IS NULL` — and that leaves a question
     * isVisibleTo() cannot answer, its whole rule being built from an
     * account that here does not exist.
     *
     * **Deliberately narrower than the receipts page's floor.** An unsorted
     * receipt may belong to any section, so showing it to every intendant
     * would show each of them a document that is probably somebody else's.
     * Whoever the unit named as a treasurer is the person whose job this
     * sorting is, so that is who sees the pile — plus `admin`/`superadmin`,
     * who answer for the unit's finances as a whole exactly as above.
     *
     * **A disabled badge rule falls back to the floor, and that is not an
     * oversight.** A null scope means the unit has not switched the
     * `Trésorier` rule on — which is every unit on the day it installs this
     * version. Reading that null as "nobody is a treasurer" would lock a
     * unit out of its own sorting pile until somebody thought to assign a
     * badge nothing on the screen mentions. treasurerAllows() already
     * treats the same null as "this condition narrows nothing"; answering
     * differently here would make one null mean two things inside one
     * class.
     *
     * An EMPTY scope is the opposite and denies: the rule is on, and this
     * session is nobody's treasurer.
     */
    public function isUnassignedReceiptVisibleTo(Role $role): bool
    {
        if (!$role->hasAccess(self::UNASSIGNED_RECEIPT_FLOOR)) {
            return false;
        }

        if ($role->hasAccess(Role::ADMIN)) {
            return true;
        }

        $treasurerSectionIds = $this->treasurerScope->sectionIds();

        return $treasurerSectionIds === null || $treasurerSectionIds !== [];
    }

    /**
     * The section half.
     *
     * An account with no `section_id` is the unit's own money: it belongs
     * to no section, so there is no section rule to apply and
     * `role_min_view` remains its whole answer.
     *
     * `admin`/`superadmin` — the chef d'unité — get every account
     * unconditionally. They answer for the unit's finances as a whole, and
     * TreasurerScopeService deliberately knows nothing about roles, so the
     * rule lives here rather than being re-derived by each caller.
     *
     * A null scope means the unit has not switched the rule on (nobody
     * carries the `Trésorier` badge this year, or it is deactivated) and
     * the module behaves exactly as it did before the rule existed. An
     * EMPTY scope is the opposite and denies every section account: the
     * rule is on and this session is nobody's treasurer.
     */
    private function treasurerAllows(Account $account, Role $role): bool
    {
        if ($account->sectionId === null || $role->hasAccess(Role::ADMIN)) {
            return true;
        }

        $treasurerSectionIds = $this->treasurerScope->sectionIds();

        return $treasurerSectionIds === null
            || in_array($account->sectionId, $treasurerSectionIds, true);
    }
}
