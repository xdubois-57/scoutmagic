<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Maintenance\BackupException;

/**
 * A remote destination that could not be used, stated in French for the
 * admin who triggered it.
 *
 * Extends `BackupException`, so it is `UserFacingException` and carries
 * that class's obligation: every message here is written for a person, and
 * the provider's own words — Google's English error bodies, a cURL
 * diagnostic naming TLS internals — travel as `$previous` and reach the
 * journal only.
 *
 * `$needsReauthorisation` is the one distinction callers act on. A grant
 * that has been withdrawn is not a network problem to retry: it is a
 * state the site is now in, and the screen has to say so rather than keep
 * reporting a failure the operator cannot influence.
 */
final class RemoteBackupException extends BackupException
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
