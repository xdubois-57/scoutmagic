<?php

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * Owns the per-session scout-year preview override.
 *
 * The stored value is a plain scout_year_id. It is NEVER persisted to the
 * database and is cleared on logout. Role enforcement (chief/admin only) lives
 * in ScoutYearResolver, which re-checks the current role on every request — this
 * class only stores and reads the id, mirroring ConfigurationMode.
 */
class ScoutYearSession
{
    private const SESSION_KEY = '_scout_year_override';

    /**
     * Store a preview year for the current session.
     */
    public static function setPreview(int $yearId): void
    {
        $_SESSION[self::SESSION_KEY] = $yearId;
    }

    /**
     * Get the preview year id, or null if no preview is active.
     */
    public static function getPreviewId(): ?int
    {
        $value = $_SESSION[self::SESSION_KEY] ?? null;

        return $value !== null ? (int) $value : null;
    }

    /**
     * Clear the preview override.
     */
    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
