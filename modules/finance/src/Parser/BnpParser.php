<?php

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Modules\Finance\Service\FinanceException;

/**
 * BNP Fortis CSV export parser — semicolon-delimited, UTF-8 with BOM,
 * amounts with comma decimal separator, one row per transaction with the
 * account's own IBAN repeated on every line (column "Numéro de compte").
 * Real parsing is "itération 3" of the module spec — stubbed for now so
 * BankStatementParserFactory can already resolve a 'bnp' parser instance.
 */
final class BnpParser implements BankStatementParserInterface
{
    public function extractSourceIban(string $filePath): ?string
    {
        throw new FinanceException("L'import de relevés BNP n'est pas encore disponible.");
    }

    public function parse(string $filePath): array
    {
        throw new FinanceException("L'import de relevés BNP n'est pas encore disponible.");
    }
}
