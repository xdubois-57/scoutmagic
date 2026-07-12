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
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = [
            'user_account_id' => $userAccountId,
            'email' => $email,
            'role' => $role,
        ];
    }

    /**
     * Log out: clear auth data from session. Regenerate session ID.
     */
    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Check if a user is currently authenticated.
     */
    public static function isAuthenticated(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]['user_account_id']);
    }

    /**
     * Get the current user's account ID, or null if not authenticated.
     */
    public static function getUserAccountId(): ?int
    {
        return $_SESSION[self::SESSION_KEY]['user_account_id'] ?? null;
    }

    /**
     * Get the current user's email, or null.
     */
    public static function getEmail(): ?string
    {
        return $_SESSION[self::SESSION_KEY]['email'] ?? null;
    }

    /**
     * Get the current user's effective role, or 'public'.
     */
    public static function getRole(): string
    {
        return $_SESSION[self::SESSION_KEY]['role'] ?? 'public';
    }
}
