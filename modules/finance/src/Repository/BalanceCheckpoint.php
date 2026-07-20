<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class BalanceCheckpoint
{
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly string $checkpointDate,
        public readonly float $balance,
        public readonly string $source
    ) {
    }
}
