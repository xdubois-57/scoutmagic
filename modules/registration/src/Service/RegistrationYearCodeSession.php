<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Security\SessionStore;

/**
 * Owns the per-session "in-year code accepted" unlock: once a visitor
 * enters a valid registration_year_codes code for the CURRENT public year
 * while the general form is closed, the full form becomes available to
 * that visitor — and only that visitor, for the rest of their browser
 * session — without making the form globally open for every other one
 * (module spec: an in-year code lets a family register even while the
 * general form is shut, but that must never leak into "the form is open
 * for everyone"). Never persisted to the database; cleared naturally
 * when the session ends, and re-checked against the CURRENT public year
 * on every read so an unlock from a past year never carries forward
 * after the public year changes.
 */
class RegistrationYearCodeSession
{
    private const SESSION_KEY = '_registration_year_code_unlocked_year_id';

    public static function unlock(int $scoutYearId): void
    {
        SessionStore::set(self::SESSION_KEY, $scoutYearId);
    }

    public static function isUnlockedFor(int $scoutYearId): bool
    {
        $stored = SessionStore::get(self::SESSION_KEY);

        return $stored !== null && (int) $stored === $scoutYearId;
    }
}
