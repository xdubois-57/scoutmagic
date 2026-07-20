<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Transaction
{
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly int $fiscalYearId,
        public readonly ?string $bankReference,
        public readonly string $transactionDate,
        public readonly string $label,
        public readonly float $amount,
        public readonly ?int $categoryId,
        public readonly ?string $comment,
        public readonly string $source,
        public readonly ?string $importedAt
    ) {
    }
}
