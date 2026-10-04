<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * What `bootstrap/bootstrap.php` hands to the setup wizard (#719, D).
 *
 * **Frozen, and on purpose.** The bootstrap a unit downloads today is
 * always newer than the wizard it installs: to restore an archive written
 * by an older ScoutMagic, it installs that older release first. So
 * whatever the two exchange must keep meaning the same thing in every
 * release from this one on — a value changed here would leave a newer
 * bootstrap talking to an older wizard that no longer understands it.
 * Three things cross that line, and only these:
 *
 * - **where the archive is deposited** — {@see ARCHIVE_PATH}, relative to
 *   the installation root, with chunks on their way in under
 *   {@see INCOMING_DIR};
 * - **the proof that the operator typed the installation token** —
 *   {@see PROOF_COOKIE}, computed by {@see proofValue()} from the token in
 *   `token.php`, which the wizard reads from disk and recomputes: there
 *   is no session shared between the two;
 * - **the address of the restore mode** — {@see RESTORE_MODE_URL}.
 *
 * The bootstrap cannot load this class (it runs before `vendor/` exists),
 * so it carries its own copy of each value; `tests/Bootstrap` pins the two
 * to each other. Change neither without a new versioned name beside the
 * old one.
 */
final class BootstrapHandoff
{
    /** The deposited portable archive, relative to the installation root. */
    public const ARCHIVE_PATH = 'storage/restore/portable-restore.zip';

    /** Where the bootstrap assembles the archive's chunks before moving it to {@see ARCHIVE_PATH}. */
    public const INCOMING_DIR = 'storage/restore/incoming';

    /** An archive nobody restored nor discarded for this long is deleted. */
    public const ABANDONED_AFTER_SECONDS = 7 * 86400;

    public const PROOF_COOKIE = 'scoutmagic_setup_proof';

    /** Long enough for a large archive to upload, short enough to be a proof of now. */
    public const PROOF_LIFETIME_SECONDS = 2 * 3600;

    /** The setup wizard showing the deposited archive first. */
    public const RESTORE_MODE_URL = '/setup?restauration=1';

    /** Domain separation: this MAC proves a token, and nothing else keyed by it. */
    private const PROOF_CONTEXT = 'scoutmagic-setup-proof-v1';

    /**
     * The cookie value: its expiry, and an HMAC of that expiry keyed by the
     * installation token — « 1800007200.3f9a… ». Never the token itself,
     * which is never sent back to a browser.
     */
    public static function proofValue(string $token, int $expiresAt): string
    {
        return $expiresAt . '.' . hash_hmac('sha256', self::PROOF_CONTEXT . '|' . $expiresAt, $token);
    }

    /**
     * Whether a cookie proves the token now: well formed, not expired, not
     * claiming a lifetime longer than {@see PROOF_LIFETIME_SECONDS}, and
     * signed with this token. An empty token proves nothing.
     */
    public static function proofIsValid(string $cookie, string $token, int $now): bool
    {
        if ($token === '' || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $cookie, $parts) !== 1) {
            return false;
        }

        $expiresAt = (int) $parts[1];
        if ($expiresAt <= $now || $expiresAt > $now + self::PROOF_LIFETIME_SECONDS) {
            return false;
        }

        return hash_equals(self::proofValue($token, $expiresAt), $cookie);
    }
}
