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
     * Authenticators pass the server generation captured before verifying
     * credentials; sessions without one cannot survive revalidation.
     *
     * 'issued_at' is what makes server-side revocation possible: it is
     * compared against user_accounts.sessions_valid_from on every request
     * (Core\Security\SessionRevalidator), so a password change can end
     * sessions that PHP's file-based session store gives us no way to
     * enumerate and delete directly.
     *
     * 'authenticated_at' is when this session last PROVED who it is
     * (Core\Security\RecentAuthentication, issue #1115). Every way of
     * logging in ends here — magic link, password, passkey — so a login is
     * by definition that proof. It starts equal to 'issued_at' but the two
     * drift apart: refreshIssuedAt() moves the revocation stamp without
     * moving this one.
     */
    public static function login(int $userAccountId, string $email, string $role, ?string $generation = null): void
    {
        SessionStore::ensureWritable();
        session_regenerate_id(true);

        $now = time();
        SessionStore::set(self::SESSION_KEY, [
            'user_account_id' => $userAccountId,
            'email' => $email,
            'role' => $role,
            'issued_at' => $now,
            'authenticated_at' => $now,
            'generation' => $generation,
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

    /** Legacy or malformed sessions have no trustworthy installation epoch. */
    public static function getGeneration(): ?string
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        $value = $auth['generation'] ?? null;

        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    /**
     * Re-stamp this session as issued now. Called right after a member
     * changes their OWN password: the change revokes every session for the
     * account, and this exempts the tab they are actively using — the
     * usual "you stay logged in here, everywhere else is signed out".
     *
     * It does NOT count as a fresh authentication (authenticatedAt() is
     * left alone): it also runs after a FIRST password is set, which
     * proves nothing about who is at the keyboard. A caller that has just
     * checked a secret — the current password — says so itself with
     * markAuthenticatedNow().
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
     * When this session last proved who it is — a login by any method, or
     * markAuthenticatedNow() — as a Unix timestamp. Null for a session
     * that predates this field, which therefore never counts as recent.
     */
    public static function authenticatedAt(): ?int
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        $at = $auth['authenticated_at'] ?? null;

        return is_int($at) ? $at : null;
    }

    /**
     * When the browser last received this session's cookie, as a Unix
     * timestamp — the start of its 30-day lifetime, since PHP sends the
     * cookie only when the id is (re)issued. The id rotates exactly where
     * 'authenticated_at' is stamped: login() and markAuthenticatedNow().
     * Not 'issued_at': refreshIssuedAt() moves that without a new cookie.
     * A session predating 'authenticated_at' falls back to 'issued_at'.
     */
    public static function cookieIssuedAt(): ?int
    {
        return self::authenticatedAt() ?? self::getIssuedAt();
    }

    /**
     * Record that this already-signed-in session has just proved its
     * identity again: a magic link for the same account collected while
     * signed in, or the current password checked by « Mon compte ».
     *
     * It rotates the session id, as login() does: the fresh window is
     * what lets the session add a passkey or a device credential, and a
     * copy of the old cookie (a shared computer, a stolen session) must
     * not share in it. With the old id destroyed, only the request that
     * proved itself keeps the session. Everything else in it stays.
     */
    public static function markAuthenticatedNow(): void
    {
        $auth = SessionStore::get(self::SESSION_KEY, []);
        if (!isset($auth['user_account_id'])) {
            return;
        }

        SessionStore::ensureWritable();
        session_regenerate_id(true);

        $auth['authenticated_at'] = time();
        SessionStore::set(self::SESSION_KEY, $auth);
    }

    /**
     * Whether the last proof of identity is at most $seconds old. False
     * when signed out, when the session predates the field, and when the
     * stamp lies in the future (a clock stepped backwards proves nothing).
     *
     * @param int|null $now the current Unix time; null reads the clock
     */
    public static function authenticatedWithin(int $seconds, ?int $now = null): bool
    {
        if (!self::isAuthenticated()) {
            return false;
        }
        $at = self::authenticatedAt();
        if ($at === null) {
            return false;
        }
        $age = ($now ?? time()) - $at;

        return $age >= 0 && $age <= $seconds;
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
