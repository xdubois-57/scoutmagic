<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class StatementImport
{
    public function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly string $bankCode,
        public readonly string $originalFilename,
        public readonly int $linesTotal,
        public readonly int $linesNew,
        public readonly int $linesDuplicate,
        public readonly ?int $importedBy,
        public readonly string $importedAt
    ) {
    }
}
