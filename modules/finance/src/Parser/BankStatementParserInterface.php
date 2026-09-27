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
     * Whether the file has this format's structure — a cheap sniff of its
     * first bytes, never an exception. BankStatementParserFactory::detect()
     * asks every parser in turn, so the treasurer never has to know what
     * their bank exported.
     */
    public function recognizes(string $filePath): bool;

    /**
     * Every account of the unit the file covers, as normalized IBANs
     * (Service\IbanNormalizer), in the order the file names them — read
     * from the file itself, never from the person uploading it.
     * Service\ImportService resolves each one to the site account carrying
     * that IBAN (via blind index); a line's own StatementLine::$accountIban
     * is always one of them.
     *
     * @return list<string> never empty
     * @throws FinanceException if the file is unreadable, empty, or no
     *                           account IBAN can be found in it
     */
    public function extractAccountIbans(string $filePath): array;

    /**
     * @return StatementLine[]
     * @throws FinanceException if the file is unreadable or malformed
     */
    public function parse(string $filePath): array;

    /**
     * The balance each account stands at after the file, keyed by its
     * normalized IBAN, when the format states it — an EMPTY array for a
     * format that does not (the BNP CSV), and then a first import needs one
     * typed by hand.
     *
     * @return array<string, ClosingBalance>
     * @throws FinanceException if the file is unreadable or malformed
     */
    public function closingBalances(string $filePath): array;
}
