<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

/**
 * What a connection test found, phrased for the operator who asked.
 *
 * A result rather than an exception, because a failed test is not a
 * failure of the request: the operator pressed a button whose whole
 * purpose is to come back with bad news when there is bad news.
 *
 * `$needsReauthorisation` separates the one cause the operator can
 * actually do something about — the grant is gone, so reconnect — from
 * everything else, which is a matter of waiting or of looking at the
 * journal.
 */
final class RemoteConnectionCheck
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly bool $needsReauthorisation = false,
        public readonly ?RemoteQuota $quota = null,
        public readonly string $account = ''
    ) {
    }

    public static function success(string $account, ?RemoteQuota $quota): self
    {
        return new self(true, 'La connexion fonctionne : un fichier témoin a été écrit puis supprimé.', false, $quota, $account);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public static function revoked(string $message): self
    {
        return new self(false, $message, true);
    }
}
