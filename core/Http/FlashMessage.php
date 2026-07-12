<?php

declare(strict_types=1);

namespace Core\Http;

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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::SESSION_KEY] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * Get and clear the flash message.
     *
     * @return array{type: string, message: string}|null
     */
    public static function get(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $flash = $_SESSION[self::SESSION_KEY] ?? null;

        if ($flash !== null) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $flash;
    }
}
