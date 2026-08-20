<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

class AuthSession
{
    private const SESSION_KEY = '_auth';

    /**
     * Log in: store user info in session. Regenerate session ID (SECURITY.md §2).
     *
     * 'issued_at' is what makes server-side revocation possible: it is
     * compared against user_accounts.sessions_valid_from on every request
     * (Core\Security\SessionRevalidator), so a password change can end
     * sessions that PHP's file-based session store gives us no way to
     * enumerate and delete directly.
     */
    public static function login(int $userAccountId, string $email, string $role): void
    {
        SessionStore::ensureWritable();
        session_regenerate_id(true);
        // Rotate the CSRF token across the privilege boundary — a token minted
        // for the anonymous visitor must not survive into the authenticated
        // session (audit hardening).
        CsrfGuard::rotate();

        SessionStore::set(self::SESSION_KEY, [
            'user_account_id' => $userAccountId,
            'email' => $email,
            'role' => $role,
            'issued_at' => time(),
        ]);
    }

    /**
     * Log out: clear auth data from session. Regenerate session ID.
     */
    public static function logout(): void
    {
        SessionStore::ensureWritable();

        SessionStore::remove(self::SESSION_KEY);
        session_regenerate_id(true);
        CsrfGuard::rotate();
    }

    /**
     * Check if a user is currently authenticated.
     */
    public static function isAuthenticated(): bool
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        return isset($auth['user_account_id']);
    }

    /**
     * Get the current user's account ID, or null if not authenticated.
     */
    public static function getUserAccountId(): ?int
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        return $auth['user_account_id'] ?? null;
    }

    /**
     * Get the current user's email, or null.
     */
    public static function getEmail(): ?string
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        return $auth['email'] ?? null;
    }

    /**
     * Get the current user's effective role, or 'public'.
     */
    public static function getRole(): string
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        return $auth['role'] ?? 'public';
    }

    /**
     * Replace the session's cached role — used by Core\Security\
     * SessionRevalidator when re-resolving it against current data, so a
     * demotion takes effect on the member's next request instead of
     * lingering until the 30-day session cookie finally expires.
     */
    public static function setRole(string $role): void
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        if (!isset($auth['user_account_id'])) {
            return;
        }

        $auth['role'] = $role;
        SessionStore::set(self::SESSION_KEY, $auth);
    }

    /**
     * When this session was established, as a Unix timestamp. Null for a
     * session predating this field (issued before the revocation stamp
     * shipped) — treated as "unknown, therefore revoked" by
     * Core\Security\SessionRevalidator whenever the account carries a
     * sessions_valid_from at all.
     */
    public static function getIssuedAt(): ?int
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        $issuedAt = $auth['issued_at'] ?? null;

        return is_int($issuedAt) ? $issuedAt : null;
    }

    /**
     * Re-stamp this session as issued now. Called right after a member
     * changes their OWN password: the change revokes every session for the
     * account, and this exempts the tab they are actively using — the
     * usual "you stay logged in here, everywhere else is signed out".
     */
    public static function refreshIssuedAt(): void
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        if (!isset($auth['user_account_id'])) {
            return;
        }

        $auth['issued_at'] = time();
        SessionStore::set(self::SESSION_KEY, $auth);
    }

    /**
     * Store linked member year IDs in session.
     *
     * @param int[] $memberYearIds
     */
    public static function setLinkedMembers(array $memberYearIds): void
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        $auth['linked_members'] = $memberYearIds;
        SessionStore::set(self::SESSION_KEY, $auth);
    }

    /**
     * Get linked member year IDs from session.
     *
     * @return int[]
     */
    public static function getLinkedMembers(): array
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        return $auth['linked_members'] ?? [];
    }
}
