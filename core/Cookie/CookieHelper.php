<?php

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

        setcookie($name, $value, [
            'expires' => $expiry,
            'path' => $path,
            'httponly' => $httpOnly,
            'secure' => $secure,
            'samesite' => $sameSite,
        ]);
    }
}
