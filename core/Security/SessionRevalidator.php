<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Journal\JournalService;

/**
 * Re-checks an already-authenticated session against current data, once per
 * request, before anything reads the role.
 *
 * Two things were previously decided once at login and then trusted for the
 * lifetime of a 30-day session cookie:
 *
 * 1. Whether the session should still exist at all. A password reset is
 *    supposed to recover a compromised account, but PHP's file-based
 *    sessions have no per-user registry to walk and destroy, so an
 *    attacker's existing session simply survived the reset. Sessions now
 *    carry the instant they were issued and accounts carry
 *    sessions_valid_from; a session older than that stamp is dropped here,
 *    on its next request.
 *
 * 2. The effective role, snapshotted by AuthController::resolveRole() at
 *    login. Losing a function (or the whole membership) therefore didn't
 *    reduce anyone's access until they happened to log in again. It is
 *    re-resolved here every request, so a demotion lands on the next click.
 *
 * Deliberately fail-safe rather than fail-open: an account that has
 * vanished, or is no longer allowed to log in at all, is logged out rather
 * than left with whatever the session last said.
 */
class SessionRevalidator
{
    private ?JournalService $journalService = null;

    public function __construct(
        private UserAccountRepository $userAccountRepo,
        private RoleResolver $roleResolver
    ) {
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * Returns true when the session survived (or there was none to check),
     * false when it was revoked and the caller is now anonymous.
     *
     * $currentScoutYearId is a callable rather than a value so an anonymous
     * visitor — the majority of traffic on a public site — costs nothing
     * here: there is no session to check, so the year is never resolved.
     *
     * @param callable(): int $currentScoutYearId
     */
    public function revalidate(callable $currentScoutYearId): bool
    {
        if (!AuthSession::isAuthenticated()) {
            return true;
        }

        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return true;
        }

        $account = $this->userAccountRepo->findById($userAccountId);

        // The account was deleted out from under a live session.
        if ($account === null) {
            $this->revoke('account_deleted', $userAccountId);
            return false;
        }

        if ($this->isRevokedByCredentialChange($account)) {
            $this->revoke('credentials_changed', $userAccountId);
            return false;
        }

        $scoutYearId = $currentScoutYearId();

        // Same gate AuthController applies at login: a user_accounts row is
        // never sufficient on its own, the address must still match a real
        // member of the unit this year (super-admins excepted). Letting an
        // existing session outlive that would just be the login gate with
        // an up-to-30-day grace period.
        if (!$this->roleResolver->isEmailAuthorizedToLogin($account->email, $scoutYearId)) {
            $this->revoke('no_longer_a_member', $userAccountId);
            return false;
        }

        $freshRole = $this->roleResolver->resolve($account->email, $scoutYearId);
        if ($freshRole !== AuthSession::getRole()) {
            $this->journalService?->log(
                'core', 'session_role_refreshed', 'security',
                'Rôle de session actualisé',
                ['from' => AuthSession::getRole(), 'to' => $freshRole],
                $userAccountId
            );
            AuthSession::setRole($freshRole);
        }

        return true;
    }

    /**
     * A session is revoked when it was issued before the account's
     * sessions_valid_from stamp. A session with no issue timestamp at all
     * predates that mechanism, so it can't be shown to be newer than the
     * stamp — and is revoked too, rather than given the benefit of the
     * doubt. Accounts that never changed a password carry no stamp and are
     * unaffected either way.
     */
    private function isRevokedByCredentialChange(UserAccount $account): bool
    {
        if ($account->sessionsValidFrom === null) {
            return false;
        }

        $issuedAt = AuthSession::getIssuedAt();
        if ($issuedAt === null) {
            return true;
        }

        return $issuedAt < $account->sessionsValidFrom->getTimestamp();
    }

    private function revoke(string $reason, int $userAccountId): void
    {
        $this->journalService?->log(
            'core', 'session_revoked', 'security', 'Session invalidée',
            ['reason' => $reason], $userAccountId
        );

        AuthSession::logout();
        PendingMagicLink::forget();
    }
}
