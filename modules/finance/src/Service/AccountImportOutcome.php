<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\StatementImport;

/**
 * One site account's share of an imported file.
 *
 * $balanceDiscrepancy is the difference (provided balance minus the
 * balance calculated from the previous checkpoint + transactions, before
 * this import's new checkpoint was created) — null when no balance was
 * provided, or when this was the account's first import (there is nothing
 * to compare against yet).
 */
final class AccountImportOutcome
{
    public function __construct(
        public readonly Account $account,
        public readonly StatementImport $statementImport,
        public readonly ?float $balanceDiscrepancy = null
    ) {
    }
}
