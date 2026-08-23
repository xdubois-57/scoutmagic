<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Security\SessionStore;

/**
 * One-shot message shown on the next rendered page.
 *
 * Contract: $type is exactly one of 'success', 'error' or 'warning' —
 * these are the only types the layout maps to an alert style. 'danger'
 * is a Bootstrap CSS class, not a flash type; passing it only renders by
 * accident, and tests/Core/View/UxConventionsTest.php fails the build on
 * any new occurrence. For a stale CSRF token, don't call this directly:
 * AbstractController::guardCsrf() flashes the single site-wide message
 * (AbstractController::SESSION_EXPIRED_MESSAGE).
 */
class FlashMessage
{
    private const SESSION_KEY = '_flash_message';

    /**
     * Set a flash message in the session.
     *
     * @param string $type One of: success, error, warning
     * @param string $message The message text
     */
    public static function set(string $type, string $message): void
    {
        SessionStore::set(self::SESSION_KEY, [
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * Get and clear the flash message.
     *
     * @return array{type: string, message: string}|null
     */
    public static function get(): ?array
    {
        $flash = SessionStore::get(self::SESSION_KEY);

        if ($flash !== null) {
            SessionStore::remove(self::SESSION_KEY);
        }

        return $flash;
    }
}
