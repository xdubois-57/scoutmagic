<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * « Confirmez votre identité » before a new way into the account is added
 * (issue #1115, SECURITY.md §2).
 *
 * A session cookie lasts thirty days, and whoever holds it — the owner, or
 * somebody who copied it off a shared computer — used to be able to give
 * the account a first password, a passkey or a device credential without
 * proving anything. Each of those outlives the session: once the cookie is
 * revoked, the new way in is still there. So those three actions need a
 * login of less than WINDOW_SECONDS (AuthSession::authenticatedAt()); past
 * that, the person signs in again through the ordinary login page, by any
 * of its methods, and comes back to the page they left.
 *
 * Changing an existing password is not gated: it already asks for the
 * current one, and checking it counts as a fresh proof
 * (AccountController::updatePassword()).
 */
final class RecentAuthentication
{
    /** How old the last login may be — ten minutes, the maintainer's choice. */
    public const WINDOW_SECONDS = 600;

    /** What the person is told when an action is refused (the JSON refusals' `error`). */
    public const MESSAGE = 'Pour votre sécurité, cette action demande une connexion de moins de '
        . (self::WINDOW_SECONDS / 60) . ' minutes. Confirmez votre identité en vous reconnectant : '
        . 'vous reviendrez ensuite sur cette page.';

    /** What the login page says above its form in that mode. */
    public const LOGIN_NOTICE = 'Pour votre sécurité, confirmez votre identité en vous reconnectant. '
        . 'Vous reviendrez ensuite sur la page que vous quittiez.';

    /** The query flag that lets the login page show itself to a signed-in visitor. */
    public const QUERY_FLAG = 'reauth';

    public static function isRecent(?int $now = null): bool
    {
        return AuthSession::authenticatedWithin(self::WINDOW_SECONDS, $now);
    }

    /**
     * The login page in « confirm your identity » mode, bringing the
     * visitor back to $returnPath afterwards. The path goes through
     * LoginReturn's own check, like any other `return`.
     */
    public static function loginUrl(string $returnPath): string
    {
        $url = LoginReturn::loginUrl($returnPath);

        return $url . (str_contains($url, '?') ? '&' : '?') . self::QUERY_FLAG . '=1';
    }

    /**
     * The JSON body an AJAX endpoint answers (with HTTP 403) when the
     * session is too old: the message, and where to confirm.
     *
     * @return array{success: false, error: string, reauth_url: string}
     */
    public static function jsonRefusal(string $returnPath): array
    {
        return [
            'success' => false,
            'error' => self::MESSAGE,
            'reauth_url' => self::loginUrl($returnPath),
        ];
    }
}
