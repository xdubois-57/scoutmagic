<?php

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Modules\Finance\Service\FinanceException;

/**
 * Not final — Tests\Modules\Finance\Service\ImportServiceTest overrides
 * create() to inject a fake parser without touching the real bnp match
 * arm below.
 */
class BankStatementParserFactory
{
    /**
     * @return string[] bank codes accepted by import()/create(), for the
     *                   import form's dropdown
     */
    public function getSupportedBankCodes(): array
    {
        return ['bnp'];
    }

    public function create(string $bankCode): BankStatementParserInterface
    {
        return match ($bankCode) {
            'bnp' => new BnpParser(),
            default => throw new FinanceException("Format bancaire non pris en charge : {$bankCode}."),
        };
    }
}
