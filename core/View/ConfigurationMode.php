<?php

declare(strict_types=1);

namespace Core\View;

use Core\Security\AuthSession;

class ConfigurationMode
{
    private const SESSION_KEY = '_config_mode';

    /**
     * Activate configuration mode for the current session.
     * Only callable by admin role.
     */
    public static function activate(string $currentRole): bool
    {
        if ($currentRole !== 'admin') {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = true;

        return true;
    }

    /**
     * Deactivate configuration mode.
     */
    public static function deactivate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Check if configuration mode is currently active.
     * Returns true only if the flag is set AND the current session role is admin.
     */
    public static function isActive(): bool
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        if (AuthSession::getRole() !== 'admin') {
            self::deactivate();
            return false;
        }

        return true;
    }
}
