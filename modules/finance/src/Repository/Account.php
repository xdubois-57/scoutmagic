<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Account
{
    public const TYPE_BANK = 'bank';
    public const TYPE_CASH = 'cash';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $accountType,
        public readonly ?int $sectionId,
        public readonly ?string $iban,
        public readonly ?string $holderName,
        public readonly string $roleMinView,
        public readonly string $status
    ) {
    }
}
