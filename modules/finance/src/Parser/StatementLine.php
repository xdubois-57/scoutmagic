<?php

declare(strict_types=1);

namespace Modules\Finance\Parser;

/**
 * One transaction line extracted from a bank statement export, before it
 * becomes a finance_transactions row. bankReference is whatever the bank
 * uses as a stable per-line identifier (dedup key); counterpartyAccount is
 * transient — only available here, at import time, since
 * finance_transactions has no column for it (see
 * Service\CategoryRuleEngine::countMatches()'s doc comment, which is why
 * that method always returns 0 for the counterparty_account condition
 * type — Service\CategoryRuleEngine::apply() is the counterpart that runs
 * against a StatementLine, before persistence, where this value still
 * exists). balanceAfter is the bank's own running balance after this
 * line, when the format provides one (BNP Fortis does not); currently
 * unused but kept for future parsers/reconciliation.
 */
final class StatementLine
{
    public function __construct(
        public readonly string $bankReference,
        public readonly \DateTimeImmutable $transactionDate,
        public readonly float $amount,
        public readonly string $label,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?float $balanceAfter = null
    ) {
    }
}
