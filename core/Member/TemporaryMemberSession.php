<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Security\SessionStore;

/**
 * Owns the per-session "temporary member" override: one member_years.id an
 * admin has added to their own list of animés for the lifetime of the
 * session, so they can see the site as that member sees it and act on their
 * behalf.
 *
 * The stored value is a plain member_years.id. It is NEVER persisted to the
 * database, holds no personal data, and is cleared on logout — the same
 * shape and the same guarantees as Core\ScoutYear\ScoutYearSession, which
 * this class is deliberately modeled on.
 *
 * One member at a time: set() replaces whatever was there. Role enforcement
 * (admin only) lives in Core\Member\Controller\TemporaryMemberController and
 * in Core\Member\SessionTemporaryMemberProvider, which re-checks the current
 * role on every request — this class only stores and reads the id, mirroring
 * ScoutYearSession and Core\View\ConfigurationMode.
 */
class TemporaryMemberSession
{
    private const SESSION_KEY = '_temporary_member';

    /**
     * Store the temporarily-added member for the current session,
     * replacing any previous one.
     */
    public static function set(int $memberYearId): void
    {
        SessionStore::set(self::SESSION_KEY, $memberYearId);
    }

    /**
     * The temporarily-added member_years.id, or null when none is active.
     */
    public static function get(): ?int
    {
        $value = SessionStore::get(self::SESSION_KEY);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Drop the temporary member. Called on logout, when configuration mode
     * is deactivated, and from the banner's own "Retirer" control.
     */
    public static function clear(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }
}
