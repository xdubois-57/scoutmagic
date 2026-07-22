<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Account
{
    public const TYPE_BANK = 'bank';
    public const TYPE_CASH = 'cash';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';

    /**
     * Reversible, like a category's is_active — an admin toggles an
     * account between active/inactive (Controller\ConfigAccountController's
     * "activate"/"deactivate" actions), never a one-way archive. The
     * stored value stays 'archived' (unchanged schema.sql ENUM — nothing
     * to gain from a data migration just to rename it) even though
     * nothing in the app calls it that anymore.
     */
    public const STATUS_INACTIVE = 'archived';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $accountType,
        public readonly ?int $sectionId,
        public readonly ?string $iban,
        public readonly ?string $holderName,
        public readonly string $roleMinView,
        public readonly string $status,
        public readonly bool $isDefault = false
    ) {
    }
}
