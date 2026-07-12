<?php

declare(strict_types=1);

namespace Core\Security;

class CsrfGuard
{
    private const TOKEN_KEY = '_csrf_token';

    /**
     * Generate a token and store it in the session. Returns the token string.
     */
    public static function generateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::TOKEN_KEY] = $token;

        return $token;
    }

    /**
     * Validate a submitted token against the session token.
     * Returns true if valid, false if invalid or missing.
     */
    public static function validateToken(?string $submittedToken): bool
    {
        if ($submittedToken === null || $submittedToken === '') {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $sessionToken = $_SESSION[self::TOKEN_KEY] ?? null;

        if ($sessionToken === null) {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }
}
