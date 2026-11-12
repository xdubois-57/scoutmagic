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
 *
 * **The session cookie alone is not enough either** (issue #1115). Two
 * holders of one session cookie — a shared computer, a copied cookie —
 * would otherwise race for the confirmed link: whichever polled first
 * collected the session (and, since issue #1115, the ten-minute window
 * to add a passkey or a device). remember() therefore also mints a poll
 * token that only the page which asked for the link receives, in the
 * JSON answer, and keeps nothing but its SHA-256. The page holds it in
 * memory — no cookie, no storage — and sends it with every poll
 * (`X-Magic-Link-Poll-Token`); pollTokenMatches() is the check. A page
 * reloaded or closed loses it, and asks for a new link.
 */
final class PendingMagicLink
{
    private const SESSION_KEY = '_pending_magic_link';
    private const GENERATION_KEY = '_pending_magic_link_generation';
    private const POLL_TOKEN_KEY = '_pending_magic_link_poll_token';

    /** The request header the waiting page sends its poll token in. */
    public const POLL_TOKEN_HEADER = 'HTTP_X_MAGIC_LINK_POLL_TOKEN';

    /**
     * Called when a magic link has just been issued for this session —
     * including when no account matched (AuthService::requestMagicLink()
     * creates the row and reports success either way, for no enumeration),
     * so this stays indistinguishable from the matched case.
     *
     * Returns the poll token, for the requesting page only: 32 random
     * bytes, hex-encoded. The session keeps its SHA-256 — at that entropy
     * a fast hash is as safe as any, and the comparison is constant-time.
     */
    public static function remember(int $magicLinkId, ?string $generation = null): string
    {
        $pollToken = bin2hex(random_bytes(32));

        SessionStore::set(self::SESSION_KEY, $magicLinkId);
        SessionStore::set(self::GENERATION_KEY, $generation);
        SessionStore::set(self::POLL_TOKEN_KEY, hash('sha256', $pollToken));

        return $pollToken;
    }

    /**
     * Whether $pollToken is the one remember() handed the page that asked
     * for the pending link. False with no pending link, and for a pending
     * link stored before the token existed — that page asks again.
     */
    public static function pollTokenMatches(?string $pollToken): bool
    {
        $stored = SessionStore::get(self::POLL_TOKEN_KEY);
        if (!is_string($stored) || !is_string($pollToken)) {
            return false;
        }

        return hash_equals($stored, hash('sha256', $pollToken));
    }

    /**
     * Whether $magicLinkId is the link this session itself requested. Any
     * other id — guessed, enumerated, or belonging to another visitor — is
     * not this session's to collect.
     * Without $generation this checks ownership only; authentication must
     * also match the server's current generation.
     */
    public static function matches(int $magicLinkId, ?string $generation = null): bool
    {
        if ($magicLinkId <= 0) {
            return false;
        }

        $pending = SessionStore::get(self::SESSION_KEY);

        $storedGeneration = SessionStore::get(self::GENERATION_KEY);

        return is_int($pending) && $pending === $magicLinkId
            && ($generation === null || (is_string($storedGeneration) && hash_equals($generation, $storedGeneration)));
    }

    /**
     * Dropped once the link has been collected (or the session logs out) —
     * a pending id is single-use, like the link behind it.
     */
    public static function forget(): void
    {
        SessionStore::remove(self::SESSION_KEY);
        SessionStore::remove(self::GENERATION_KEY);
        SessionStore::remove(self::POLL_TOKEN_KEY);
    }
}
