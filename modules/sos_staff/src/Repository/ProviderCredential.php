<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

/**
 * A telephony provider's configuration row (sos_provider_credentials).
 * $config is the decrypted, provider-specific field bag (see
 * Provider\Ovh\OvhTelephonyProvider for the OVH shape) — never re-encrypted
 * or persisted directly by callers, only through the repository.
 */
final class ProviderCredential
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $provider,
        public readonly bool $isActive,
        public readonly array $config
    ) {
    }
}
