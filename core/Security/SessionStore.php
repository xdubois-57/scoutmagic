<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * The single place that touches $_SESSION for writes/removals. Application
 * code should never write to $_SESSION directly — public/index.php calls
 * session_write_close() early (before the DB connection/migration) to
 * avoid holding the session file lock for the whole request, which leaves
 * session_status() as PHP_SESSION_NONE for the rest of the request even
 * though $_SESSION itself stays populated and readable in memory. A write
 * made without reopening the session first is silently lost (never
 * persisted) — this class makes that impossible to forget by reopening
 * internally on every write/remove, instead of relying on each call site
 * to remember the guard (see the CsrfGuard/FlashMessage/WebAuthnService/
 * SetupController regressions this exact mistake caused).
 *
 * Reads never need to reopen anything: $_SESSION stays populated in
 * memory for the whole request regardless of write-close state.
 */
final class SessionStore
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::ensureWritable();
        $_SESSION[$key] = $value;
    }

    public static function remove(string ...$keys): void
    {
        self::ensureWritable();
        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Reopens the session for writing if it was already closed. Exposed
     * for the rare caller that needs to pair a $_SESSION write with
     * another session function (e.g. session_regenerate_id()) —
     * set()/remove() already call this internally for the common case.
     */
    public static function ensureWritable(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
