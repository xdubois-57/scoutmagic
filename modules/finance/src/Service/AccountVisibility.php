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
 */
class AccountVisibility
{
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
