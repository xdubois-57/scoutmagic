<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class ExpectedReceivable
{
    public function __construct(
        public readonly int $id,
        public readonly string $sourceModule,
        public readonly int $sourceReferenceId,
        public readonly int $accountId,
        public readonly int $amountDueCents,
        public readonly string $communication,
        public readonly ?string $label,
        public readonly string $createdAt
    ) {
    }
}
