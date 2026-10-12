<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Http\SafeRedirect;

/**
 * Where a visitor goes once logged in: the page that sent them to log in,
 * rather than always the home page (issue #833).
 *
 * A page that needs a session sends its visitor to `/login?return=<path>`.
 * GET /login keeps that path in the session, here, and every way of
 * logging in ends by reading it back:
 *
 * - the password and the passkey answer with it in their JSON;
 * - a magic link is collected by the window that asked for it
 *   (AuthController::pollMagicLink()), which is the session that holds it —
 *   so it survives the link being opened in another tab or on another
 *   device, neither of which ever sees it;
 * - when the link opens in that same session, the confirmation page offers
 *   it too, without spending it: the polling tab still collects it.
 *
 * It lives in the session, not in the page, precisely for that magic-link
 * case: the tab that started the flow may be discarded by the phone and
 * reloaded, and only the session outlives it.
 *
 * Only an internal path is ever kept (SafeRedirect::loginReturnPath()):
 * this value arrives in a link anyone can send, and without the check it
 * is an open redirect behind the unit's own login page.
 */
final class LoginReturn
{
    private const SESSION_KEY = '_login_return';

    /**
     * Called by GET /login with whatever its `return` parameter held. A
     * missing or refused value forgets any earlier one, so a login started
     * from the plain login page never lands on a page from another visit.
     */
    public static function remember(string $raw): void
    {
        $path = SafeRedirect::loginReturnPath($raw, '');
        if ($path === '' || $path === '/') {
            self::forget();
            return;
        }

        SessionStore::set(self::SESSION_KEY, $path);
    }

    /** Where to go, without spending it: `/` when nothing was asked. */
    public static function target(): string
    {
        $path = SessionStore::get(self::SESSION_KEY);

        // Checked again on the way out: the session is ours, but a value
        // that cannot be an open redirect is cheaper than an argument.
        return is_string($path) ? SafeRedirect::loginReturnPath($path) : '/';
    }

    /** Where to go, once: the login that reads it has used it. */
    public static function consume(): string
    {
        $target = self::target();
        self::forget();

        return $target;
    }

    public static function forget(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }

    /**
     * The login link for a page that needs a session — what a module's
     * « Se connecter » button points at to bring its visitor back.
     */
    public static function loginUrl(string $returnPath): string
    {
        $path = SafeRedirect::loginReturnPath($returnPath, '');

        return $path === '' || $path === '/' ? '/login' : '/login?return=' . rawurlencode($path);
    }
}
