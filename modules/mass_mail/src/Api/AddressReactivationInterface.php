<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\MassMail\Api;

/** The module's part of an explicitly requested, address-wide reactivation. */
interface AddressReactivationInterface
{
    /**
     * Restore contacts and free-address eligibility, not frozen deliveries
     * or bounce blocks. The caller has verified ownership of the address,
     * or an administrator's right to restore a contact at its owner's request.
     * Must join the caller's transaction on the composition root's shared PDO;
     * failure must propagate so member and module changes roll back together.
     * Returns the number of changed records, zero for an unavailable module
     * or an already restored address. Never sends mail.
     */
    public function reactivateAddress(string $email, ?int $actorId): int;
}
