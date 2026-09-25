<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Cookie;

class CookieHelper
{
    /**
     * Set a cookie with consent check.
     *
     * @throws CookieConsentException if the category is not allowed
     */
    public static function set(
        string $name,
        string $value,
        int $expiry,
        string $category,
        CookieConsentService $consentService,
        string $path = '/',
        bool $httpOnly = true,
        bool $secure = true,
        string $sameSite = 'Lax'
    ): void {
        if ($category !== 'necessary' && !$consentService->isAllowed($category)) {
            throw new CookieConsentException(
                "Cannot set cookie '{$name}': category '{$category}' not consented."
            );
        }

        $options = [
            'expires' => $expiry,
            'path' => $path,
            'httponly' => $httpOnly,
            'secure' => $secure,
            'samesite' => $sameSite,
        ];

        // The attributes are assembled BEFORE the two paths part, and both
        // keep them — the same order, and for the same reason, as
        // CookieConsentService::writeCookie(): computing them after the
        // test path returned is what made an expiry impossible to observe
        // from a test, and with it the thirteen months this site promises
        // (#444, and #515 for this helper). What production sends is
        // unchanged: `setcookie()` still receives exactly this array.
        if (self::$recorder !== null) {
            (self::$recorder)($name, $value, $options);
            return;
        }

        setcookie($name, $value, $options);
    }

    /**
     * What a write applied, for a test — and nothing else.
     *
     * `setcookie()` has no seam: it writes a header and returns a bool,
     * so `LastLoginMethodCookie`'s thirteen months could be changed to
     * ten years with the whole suite staying green (#515). A test installs
     * a recorder, drives the real writer, and reads back the attributes
     * production would have sent.
     *
     * Static because the helper is, and always restored in a `finally` by
     * the tests that install one.
     *
     * @var (callable(string, string, array{expires: int, path: string, httponly: bool, secure: bool, samesite: string}): void)|null
     */
    private static $recorder = null;

    /**
     * @param (callable(string, string, array{expires: int, path: string, httponly: bool, secure: bool, samesite: string}): void)|null $recorder
     */
    public static function recordWith(?callable $recorder): void
    {
        self::$recorder = $recorder;
    }
}
