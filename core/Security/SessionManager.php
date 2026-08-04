<?php

declare(strict_types=1);

namespace Core\Security;

class SessionManager
{
    // 30 days (Notifications Lot 2 §8) — an installed PWA must not demand
    // a fresh magic link every few days. A session-only cookie (the
    // previous setting, lifetime 0) is wiped by many mobile OSes/browsers
    // on every standalone-app relaunch, which reads to the user as
    // "logged out constantly". gc_maxlifetime is raised to match so PHP's
    // own session GC doesn't purge the server-side session file before
    // the cookie itself expires — a cookie surviving longer than its
    // backing session data would otherwise silently re-log the user out.
    private const COOKIE_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

    /**
     * Start the session with secure settings.
     * Called once in the front controller boot sequence.
     */
    public static function start(): void
    {
        if (self::isActive()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

        // Use a dedicated session save path to avoid OS-level temp cleanup issues
        $savePath = dirname(__DIR__, 2) . '/storage/temp/sessions';
        if (!is_dir($savePath)) {
            mkdir($savePath, 0700, true);
        }

        ini_set('session.save_path', $savePath);
        ini_set('session.name', 'SM_SESSION');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_lifetime', (string) self::COOKIE_LIFETIME_SECONDS);
        ini_set('session.gc_maxlifetime', (string) self::COOKIE_LIFETIME_SECONDS);

        session_start();
    }

    /**
     * Check if a session is active.
     */
    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }
}
