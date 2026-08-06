<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Security\SessionStore;

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
