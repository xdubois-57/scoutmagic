<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\StatementImportStatusInterface;
use Modules\Finance\Repository\StatementImportRepository;

/**
 * How far an account's money is known — the one date behind
 * `Api\StatementImportStatusInterface`.
 *
 * A class of its own rather than a method on `ImportService`: what a
 * consumer wants is a read, and `ImportService` carries the parsers, the
 * PDO handle and the checkpoint bookkeeping that doing an import needs.
 * Wiring all of that into another module's graph to obtain a date would
 * be the wrong dependency by an order of magnitude.
 */
class StatementImportStatusService implements StatementImportStatusInterface
{
    public function __construct(private StatementImportRepository $imports)
    {
    }

    public function lastStatementImportedAt(int $accountId): ?string
    {
        return $this->imports->findMostRecentForAccount($accountId)?->importedAt;
    }
}
