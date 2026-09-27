<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * What one uploaded file did, account by account — the report the import
 * result page renders after the fact (there is deliberately no summary to
 * confirm BEFORE writing: the file's own IBANs decide where each line goes).
 *
 * $accounts holds one entry per site account that received the file's
 * lines, in the order the file names them; $skipped one per account IBAN
 * of the file whose lines were set aside, with the reason.
 */
final class ImportResult
{
    /**
     * @param list<AccountImportOutcome> $accounts
     * @param list<SkippedAccount> $skipped
     */
    public function __construct(
        public readonly array $accounts,
        public readonly array $skipped = []
    ) {
    }

    public function linesNew(): int
    {
        return array_sum(array_map(static fn (AccountImportOutcome $a): int => $a->statementImport->linesNew, $this->accounts));
    }

    public function linesDuplicate(): int
    {
        return array_sum(array_map(
            static fn (AccountImportOutcome $a): int => $a->statementImport->linesDuplicate,
            $this->accounts
        ));
    }
}
