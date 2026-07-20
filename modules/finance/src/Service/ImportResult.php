<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\StatementImport;

final class ImportResult
{
    /**
     * $balanceDiscrepancy is the difference (provided balance minus the
     * balance calculated from the previous checkpoint + transactions,
     * before this import's new checkpoint was created) — null when no
     * balance was provided, or when this was the account's first import
     * (there is nothing to compare against yet).
     */
    public function __construct(
        public readonly StatementImport $statementImport,
        public readonly ?float $balanceDiscrepancy = null
    ) {
    }
}
