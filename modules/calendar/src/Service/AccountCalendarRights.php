<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;

/**
 * The two halves of CalendarEventService::getEditableCalendars() — a
 * role and the sections staffed — for an account named by its id, with no
 * session to read them from.
 *
 * The chief calendar page takes both from the session; an import keeps
 * running in later requests, so its rights are resolved here, afresh, at
 * every check: the role exactly as the session revalidator would compute
 * it (Core\Security\SessionRevalidator, over the authorization years), and
 * the sections for the effective scout year the chief calendar page uses
 * (ScoutYearResolver::getEffectiveYear(), without its display-only session
 * preview), widened to all of them for an administrator by
 * SectionStaffAuthorizationService itself — the same year, through the same
 * fallback, as that page.
 */
class AccountCalendarRights
{
    public function __construct(
        private UserAccountRepository $accounts,
        private RoleResolver $roles,
        private AuthorizationYearService $authorizationYears,
        private SectionStaffAuthorizationService $staff,
        private ScoutYearResolver $scoutYears
    ) {
    }

    /**
     * The account's role and staffed section ids, or null for an account
     * that no longer exists or was deactivated — which then writes nowhere.
     *
     * @return array{role: Role, sections: list<int>}|null
     */
    public function of(int $userAccountId): ?array
    {
        $account = $this->accounts->findById($userAccountId);
        if ($account === null || !$account->isActive) {
            return null;
        }

        $role = Role::fromString(
            $this->roles->resolveAcrossYears($account->email, $this->authorizationYears->resolve())
        );
        $sections = $this->staff->getStaffedSections(
            $account->email,
            $role->value,
            $this->scoutYears->getEffectiveYear(null, $role)->id
        );

        return [
            'role' => $role,
            'sections' => array_values(array_map(static fn (array $s): int => (int) $s['id'], $sections)),
        ];
    }
}
