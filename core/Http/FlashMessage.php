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
        // Not gated on session_status() === PHP_SESSION_ACTIVE: public/
        // index.php calls session_write_close() early, which leaves
        // session_status() as PHP_SESSION_NONE for the rest of the
        // request even though $_SESSION itself stays populated and
        // readable in memory — gating the read on ACTIVE would make every
        // flash message silently vanish. The removal below does need an
        // active session to actually persist, though, since it's a write.
        $flash = $_SESSION[self::SESSION_KEY] ?? null;

        if ($flash !== null) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $flash;
    }
}
