<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * One address a custom list writes to that belongs to nobody in the
 * members table — the commune, the curé, a former member.
 *
 * Decrypted: this object only ever exists on the far side of
 * Repository\ListAddressRepository, which is the single place the
 * ciphertext is read (SECURITY.md §5).
 */
final class ListAddress
{
    public function __construct(
        public readonly int $id,
        public readonly int $listId,
        /** One field, deliberately: these people have no first name and no totem to tell apart. */
        public readonly ?string $name,
        public readonly string $email,
        /** Non-null once somebody asked not to be written to; the row then survives everything. */
        public readonly ?string $unsubscribedAt,
        public readonly string $createdAt
    ) {
    }

    public function isUnsubscribed(): bool
    {
        return $this->unsubscribedAt !== null;
    }
}
