<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * A bearer credential in a URL: whoever holds the string may do the one
 * thing it authorises, and nothing else.
 *
 * Five modules issue one — a renter's tracking link, a one-click
 * unsubscribe, an ICS feed URL, a retro board, a registration request's
 * follow-up page — and each of them was reinventing the same three
 * decisions. This class holds the decisions. It deliberately does NOT hold
 * the storage: whether a module keeps a hash, an encrypted copy or a blind
 * index is a real per-module trade-off (a hash can only answer "is this
 * the token?", which is useless when an email has to *contain* the link),
 * and it does not hold the routing either.
 *
 * **The contract, which is the point of this class existing:**
 *
 * 1. **Absent ≡ wrong.** A missing token, an empty string, an unknown id
 *    and a wrong token all produce the same refusal, in the same time,
 *    with the same response. Distinguishing them turns a URL into an
 *    enumeration oracle — "this booking exists but you have the wrong
 *    link" is one bit an attacker did not have.
 * 2. **Never logged, never journaled, never shown in an error.** A token
 *    in a log file is a token in every backup, every support package and
 *    every screenshot of a stack trace. Record the id of the thing it
 *    opens, never the token.
 * 3. **Regenerate, never recover.** "Lost your link?" is answered by
 *    issuing a new one and invalidating the old, not by fetching the old
 *    one for whoever asked. The single exception the codebase allows is a
 *    module whose whole purpose is to *send* the link (a renter has no
 *    account to log into instead) — and that module stores the token
 *    encrypted rather than hashed precisely so it can, which is a
 *    deliberate, documented, local decision.
 * 4. **Full entropy, so no KDF.** 256 bits from `random_bytes()` is not
 *    guessable and not dictionary-attackable, which is the entire reason
 *    `password_hash()` is slow. A plain SHA-256 plus a constant-time
 *    compare is the right hash for a token and the wrong one for a
 *    password; the difference is where the entropy comes from.
 *
 * Every comparison here is constant-time. A byte-wise `===` on a secret
 * leaks its prefix through timing, which is how a token gets guessed one
 * character at a time.
 */
final class CapabilityToken
{
    /**
     * 32 random bytes, hex-encoded — 64 characters, URL-safe by
     * construction (no escaping, no `+`/`/` to mangle in an email client).
     */
    public const BYTES = 32;

    /**
     * A fresh token. The raw value exists in memory for exactly as long as
     * the caller keeps it: store what you chose to store, put it in the
     * link, and let it go.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    /**
     * The stored form for a module that keeps a hash.
     *
     * SHA-256, not `password_hash()`: see contract point 4. A module that
     * already stores `password_hash()` output keeps doing so — changing a
     * stored hash format is a migration, and this class is not one.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Whether a presented token matches a stored `hash()`.
     *
     * A null or empty stored hash is a token that was never issued, and
     * answers false — same refusal as a wrong one (contract point 1).
     */
    public static function verifyAgainstHash(string $presented, ?string $storedHash): bool
    {
        if ($storedHash === null || $storedHash === '' || $presented === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($presented));
    }

    /**
     * Whether a presented token matches one the module can read back — an
     * encrypted column it just decrypted, or a plain one.
     *
     * Null/empty on either side is false, never true: "no token stored"
     * must not be openable by presenting nothing at all, which is exactly
     * what an unguarded `===` between two empty strings would allow.
     */
    public static function equalsConstantTime(?string $stored, ?string $presented): bool
    {
        if ($stored === null || $stored === '' || $presented === null || $presented === '') {
            return false;
        }

        return hash_equals($stored, $presented);
    }
}
