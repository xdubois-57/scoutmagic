<?php

declare(strict_types=1);

namespace Core\Security;

class SessionManager
{
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
        ini_set('session.cookie_lifetime', '0');

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
