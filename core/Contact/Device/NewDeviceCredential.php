<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Device;

/**
 * A credential that has just been created, carrying the ONE and ONLY
 * cleartext copy of its secret there will ever be.
 *
 * It exists to make that fact structural rather than a convention: the
 * secret travels from {@see DeviceCredentialService::create()} to the
 * screen that displays it once, inside this object, and lives nowhere
 * else — not in a column, not in the journal, not in a flash message
 * that would survive in the session, not in a response that anything
 * re-renders.
 */
final class NewDeviceCredential
{
    public function __construct(
        public readonly DeviceCredential $credential,
        public readonly string $secret
    ) {
    }
}
