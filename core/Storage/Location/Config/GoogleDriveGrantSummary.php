<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

/**
 * What a screen may know about a Google grant: whether there is one,
 * whether a client secret is stored, and which account it belongs to.
 *
 * **It exists so that the credentials cannot reach a template even by
 * accident.** {@see GoogleDriveSecret} carries `clientSecret` and
 * `refreshToken` as public readonly strings, and handing that object to
 * a render context puts both one `dump()` away from a page — which is
 * exactly what {@see \Core\Storage\Location\StorageLocationRepository}'s
 * class docblock forbids for the whole codebase: « a DTO that could hold
 * a credential is a credential that reaches a template, a JSON body or a
 * support package the first time somebody dumps an object while
 * debugging ». Templates reading only three fields was never the
 * guarantee; it was the current state of two files.
 *
 * The account address is here on purpose, and it is the one thing here
 * that is personal data: it is the e-mail of a real person, kept
 * encrypted at rest because a support package would otherwise carry it,
 * and the one place it legitimately appears is in front of the
 * administrator deciding whether the right account is connected.
 */
final class GoogleDriveGrantSummary
{
    public function __construct(
        public readonly bool $hasGrant = false,
        public readonly bool $hasClientSecret = false,
        public readonly string $account = ''
    ) {
    }

    /** Narrows a secret to what a screen is allowed to see of it. */
    public static function of(GoogleDriveSecret $secret): self
    {
        return new self($secret->hasGrant(), $secret->hasClientSecret(), $secret->account);
    }
}
