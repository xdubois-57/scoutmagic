<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class FiscalYear
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly bool $isCurrent
    ) {
    }
}
