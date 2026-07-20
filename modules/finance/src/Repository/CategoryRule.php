<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class CategoryRule
{
    public const CONDITION_KEYWORD = 'keyword';
    public const CONDITION_COUNTERPARTY_ACCOUNT = 'counterparty_account';
    public const CONDITION_AMOUNT_RANGE = 'amount_range';

    public function __construct(
        public readonly int $id,
        public readonly int $categoryId,
        public readonly int $priority,
        public readonly string $conditionType,
        public readonly string $conditionValue,
        public readonly bool $isActive
    ) {
    }
}
