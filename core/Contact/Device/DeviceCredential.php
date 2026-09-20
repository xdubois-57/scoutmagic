<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Device;

/**
 * One row of `device_credentials` — a credential an address-book client
 * synchronises with.
 *
 * **The secret is not here, and there is no property for it.** Only its
 * SHA-256 is stored at all, and even that never leaves the repository:
 * what this object carries is what a screen may show.
 */
final class DeviceCredential
{
    public function __construct(
        public readonly int $id,
        public readonly int $userAccountId,
        public readonly string $label,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastSyncAt,
        public readonly ?\DateTimeImmutable $revokedAt
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * A credential declared and never used once. The screen says so
     * rather than cleaning it up on its own: it is a valid credential
     * lying around, and deciding it is forgotten rather than merely not
     * yet configured is not the site's call to make.
     */
    public function hasNeverSynchronised(): bool
    {
        return $this->lastSyncAt === null && !$this->isRevoked();
    }
}
