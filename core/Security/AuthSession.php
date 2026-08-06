<?php

declare(strict_types=1);

namespace Core\Security;

class AuthSession
{
    private const SESSION_KEY = '_auth';

    /**
     * Log in: store user info in session. Regenerate session ID (SECURITY.md §2).
     */
    public static function login(int $userAccountId, string $email, string $role): void
    {
        SessionStore::ensureWritable();
        session_regenerate_id(true);

        SessionStore::set(self::SESSION_KEY, [
            'user_account_id' => $userAccountId,
            'email' => $email,
            'role' => $role,
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
