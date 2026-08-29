<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Modules\Finance\Api\FinanceException;

interface BankStatementParserInterface
{
    /**
     * The IBAN the statement file is for, as found in the file itself —
     * used by ImportService to verify the file matches the target account
     * (via blind index) before any line is imported.
     *
     * @throws FinanceException if the file is unreadable, empty, or the
     *                           IBAN cannot be found in it
     */
    public function extractSourceIban(string $filePath): string;

    /**
     * @return StatementLine[]
     * @throws FinanceException if the file is unreadable or malformed
     */
    public function parse(string $filePath): array;
}
