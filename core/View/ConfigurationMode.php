<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Security\AuthSession;
use Core\Security\Role;
use Core\Security\SessionStore;

class ConfigurationMode
{
    private const SESSION_KEY = '_config_mode';

    /**
     * Activate configuration mode for the current session. Callable by
     * any chief d'unité (admin) or higher — widened from superadmin-only
     * once the toggle itself moved from the superadmin-gated Configuration
     * menu to "Espace chefs d'U" (role_min: admin). Core\Http\Controller\
     * ConfigModeController's own route role_min only gets an admin session
     * in the door; this is the actual enforcement point, same as the
     * route/service split everywhere else in this codebase.
     */
    public static function activate(string $currentRole): bool
    {
        if (!Role::fromString($currentRole)->hasAccess(Role::ADMIN)) {
            return false;
        }

        SessionStore::set(self::SESSION_KEY, true);

        return true;
    }

    /**
     * Deactivate configuration mode.
     */
    public static function deactivate(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }

    /**
     * Check if configuration mode is currently active. Returns true only
     * if the flag is set AND the current session role is still admin (or
     * higher) — re-checked on every call (not just at activation time) so
     * a session that's since lost that role (e.g. a Desk import demotion)
     * has the flag revoked immediately rather than staying stuck active.
     */
    public static function isActive(): bool
    {
        if (empty(SessionStore::get(self::SESSION_KEY))) {
            return false;
        }

        if (!Role::fromString(AuthSession::getRole())->hasAccess(Role::ADMIN)) {
            self::deactivate();
            return false;
        }

        return true;
    }
}
