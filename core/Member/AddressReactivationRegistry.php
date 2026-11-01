<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Core\Member;

use Modules\MassMail\Api\AddressReactivationInterface;

/** Shared mutable handle: core is built before the optional mass_mail block. */
final class AddressReactivationRegistry implements AddressReactivationInterface
{
    private ?AddressReactivationInterface $provider = null;

    public function provide(AddressReactivationInterface $provider): void
    {
        if ($this->provider !== null) {
            throw new \LogicException('An address reactivation provider is already registered.');
        }
        $this->provider = $provider;
    }

    public function reactivateAddress(string $email, ?int $actorId): int
    {
        return $this->provider?->reactivateAddress($email, $actorId) ?? 0;
    }
}
