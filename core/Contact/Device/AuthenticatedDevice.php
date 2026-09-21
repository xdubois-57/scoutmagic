<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Device;

use Core\Security\Role;

/**
 * The outcome of one successful device authentication: which credential
 * presented itself, whose account it belongs to, and **the role resolved
 * for that account on this request**.
 *
 * The role is carried here rather than stored on the credential on
 * purpose: it is a fact about right now, it is recomputed on every single
 * request, and there is nowhere in this object for a stale copy of it to
 * survive between two of them.
 */
final class AuthenticatedDevice
{
    public function __construct(
        public readonly DeviceCredential $credential,
        public readonly int $userAccountId,
        public readonly string $email,
        public readonly Role $role
    ) {
    }
}
