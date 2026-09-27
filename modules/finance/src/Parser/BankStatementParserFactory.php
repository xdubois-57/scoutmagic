<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Modules\Finance\Api\FinanceException;

/**
 * Not final — Tests\Modules\Finance\Service\ImportServiceTest overrides
 * create() and detect() to inject a fake parser without touching the real
 * formats below.
 */
class BankStatementParserFactory
{
    /**
     * The formats the site reads, and how the import screen names them —
     * which it only does after detect() failed (issue #511): the file knows
     * what it is, the treasurer does not have to.
     */
    private const FORMAT_LABELS = [
        'coda' => 'CODA (toutes les banques belges)',
        'bnp' => 'BNP Paribas Fortis (CSV)',
    ];

    /**
     * @return string[] bank codes accepted by import()/create()
     */
    public function getSupportedBankCodes(): array
    {
        return array_keys(self::FORMAT_LABELS);
    }

    /**
     * @return array<string, string> bank code => label, for the manual choice
     */
    public function getFormatLabels(): array
    {
        return self::FORMAT_LABELS;
    }

    public function create(string $bankCode): BankStatementParserInterface
    {
        return match ($bankCode) {
            'coda' => new CodaParser(),
            'bnp' => new BnpParser(),
            default => throw new FinanceException('Format bancaire non pris en charge.'),
        };
    }

    /**
     * The code of the first format that recognizes the file, or null when
     * none does — the import screen then offers the list, and only then.
     */
    public function detect(string $filePath): ?string
    {
        foreach ($this->getSupportedBankCodes() as $bankCode) {
            if ($this->create($bankCode)->recognizes($filePath)) {
                return $bankCode;
            }
        }

        return null;
    }
}
