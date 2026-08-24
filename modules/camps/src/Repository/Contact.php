<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/**
 * A person to call about one stay (schema.sql: camp_contacts), already
 * decrypted — Repository\ContactRepository is the only layer that sees
 * the ciphertext.
 */
class Contact
{
    public function __construct(
        public readonly int $id,
        public readonly int $campId,
        public readonly ?string $name,
        public readonly ?string $roleLabel,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $otherDetails,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    /**
     * What the contact list shows as the heading. A contact with no name
     * is normal — "le numéro de la ferme" is a contact — so the role
     * stands in, and only then a neutral placeholder.
     */
    public function displayName(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return $this->roleLabel ?? 'Contact sans nom';
    }
}
