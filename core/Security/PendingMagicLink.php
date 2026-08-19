<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * Binds the magic-link polling endpoint (GET /auth/poll/{id}) to the very
 * session that asked for the link.
 *
 * The cross-device flow logs "Device A" in as soon as the link is confirmed
 * on "Device B", and the only thing it is handed to do so is the magic
 * link's id — a sequential AUTO_INCREMENT integer (schema/core.sql), never
 * the emailed secret token itself. Without this binding, anyone who guesses
 * a recently-issued id while it is confirmed-but-not-expired gets a session
 * as that account: possession of the emailed token stops being required at
 * all, which defeats the whole point of a magic link.
 *
 * So the id alone is deliberately NOT treated as a capability. AuthService
 * still owns the real secret check (password_verify() against the token
 * hash, in verifyMagicLink()); this class only makes sure the device
 * collecting the resulting session is the one that started the flow.
 */
final class PendingMagicLink
{
    private const SESSION_KEY = '_pending_magic_link';

    /**
     * Called when a magic link has just been issued for this session —
     * including when no account matched (AuthService::requestMagicLink()
     * creates the row and reports success either way, for no enumeration),
     * so this stays indistinguishable from the matched case.
     */
    public static function remember(int $magicLinkId): void
    {
        SessionStore::set(self::SESSION_KEY, $magicLinkId);
    }

    /**
     * Whether $magicLinkId is the link this session itself requested. Any
     * other id — guessed, enumerated, or belonging to another visitor — is
     * not this session's to collect.
     */
    public static function matches(int $magicLinkId): bool
    {
        if ($magicLinkId <= 0) {
            return false;
        }

        $pending = SessionStore::get(self::SESSION_KEY);

        return is_int($pending) && $pending === $magicLinkId;
    }

    /**
     * Dropped once the link has been collected (or the session logs out) —
     * a pending id is single-use, like the link behind it.
     */
    public static function forget(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }
}
