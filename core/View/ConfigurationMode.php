<?php

declare(strict_types=1);

namespace Core\View;

use Core\Security\AuthSession;
use Core\Security\Role;

class ConfigurationMode
{
    private const SESSION_KEY = '_config_mode';

    /**
     * Activate configuration mode for the current session.
     * Only callable by the top administrator role (Configuration area).
     */
    public static function activate(string $currentRole): bool
    {
        if (!Role::fromString($currentRole)->hasAccess(Role::SUPERADMIN)) {
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

        if (!Role::fromString(AuthSession::getRole())->hasAccess(Role::SUPERADMIN)) {
            self::deactivate();
            return false;
        }

        return true;
    }
}
