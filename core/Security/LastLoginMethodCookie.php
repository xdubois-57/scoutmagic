<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Cookie\CookieConsentException;
use Core\Cookie\CookieConsentService;
use Core\Cookie\CookieHelper;

/**
 * Remembers which of the three login methods (magic link / password /
 * passkey) the user picked last time, purely so the login page can
 * pre-select that tab by default — a functional cookie (module addendum),
 * never set without functional consent, and removed the moment that
 * consent is withdrawn (see Core\Http\Controller\CookieController).
 */
class LastLoginMethodCookie
{
    public const NAME = 'last_login_method';
    private const EXPIRY_DAYS = 395;

    /** @var string[] */
    private const VALID_METHODS = ['magic-link', 'password', 'passkey'];

    /**
     * Best-effort — silently does nothing when functional cookies aren't
     * consented to (never blocks/fails a login over this).
     */
    public static function remember(string $method, CookieConsentService $consentService): void
    {
        if (!in_array($method, self::VALID_METHODS, true)) {
            return;
        }

        try {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

            // secure must match the actual connection — CookieHelper::set()
            // defaults to true, and a Secure cookie is silently dropped by
            // the browser over plain HTTP, which was why this never
            // actually persisted.
            CookieHelper::set(self::NAME, $method, time() + self::EXPIRY_DAYS * 86400, 'functional', $consentService, '/', true, $isHttps);
        } catch (CookieConsentException) {
            // Functional cookies not consented to — simply don't remember.
        }
    }

    public static function read(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;
        return is_string($value) && in_array($value, self::VALID_METHODS, true) ? $value : null;
    }

    /**
     * Removed whenever functional consent is withdrawn (module addendum:
     * "deleted if he removes his agreement") — also safe to call
     * unconditionally, e.g. on logout, since a missing cookie is a no-op.
     */
    public static function forget(): void
    {
        setcookie(self::NAME, '', ['expires' => time() - 3600, 'path' => '/']);
        unset($_COOKIE[self::NAME]);
    }
}
