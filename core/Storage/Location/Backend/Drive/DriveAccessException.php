<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend\Drive;

use Core\Storage\Location\StorageLocationException;

/**
 * Google Drive refusing something, stated in French for whoever is
 * looking at the screen.
 *
 * **It used to be `Core\Maintenance\Remote\RemoteBackupException`, and the
 * change of name is the change of scale IT-05 is about.** While Drive was
 * reachable only by the off-site backup, every refusal from it was a
 * backup that had not left. Now a Drive folder is a storage location like
 * any other: the same 401 can be a gallery that cannot render, a safety
 * copy that cannot be written, or a backup that has not left, and a
 * message calling all three « la sauvegarde » would be wrong twice out of
 * three.
 *
 * A {@see StorageLocationException}, therefore a `UserFacingException`,
 * and it carries that class's obligation: every message is written at its
 * own `throw` site, in French, naming only what an administrator typed.
 * Google's own words — English error bodies, a cURL diagnostic naming TLS
 * internals — travel as `$previous` and reach the journal only.
 *
 * `$needsReauthorisation` is the one distinction callers act on. A grant
 * that has been withdrawn is not a network problem to retry: it is a state
 * the site is now in, and the screen has to say so rather than go on
 * reporting a failure the operator cannot influence.
 */
final class DriveAccessException extends StorageLocationException
{
    private function __construct(
        string $message,
        public readonly bool $needsReauthorisation,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function of(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, false, $previous);
    }

    /**
     * The grant is gone — revoked from the Google account, or expired
     * because the consent screen is still in testing (see
     * {@see GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS}).
     */
    public static function revoked(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, true, $previous);
    }
}
