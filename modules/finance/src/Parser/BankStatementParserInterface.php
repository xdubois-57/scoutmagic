<?php

declare(strict_types=1);

namespace Modules\Finance\Parser;

interface BankStatementParserInterface
{
    /**
     * The IBAN the statement file is for, as found in the file itself —
     * used by ImportService to verify the file matches the target account
     * (via blind index) before any line is imported. Null if the format
     * doesn't carry it and it must be assumed from context.
     */
    public function extractSourceIban(string $filePath): ?string;

    /**
     * @return StatementLine[]
     */
    public function parse(string $filePath): array;
}
