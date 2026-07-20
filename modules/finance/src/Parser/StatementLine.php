<?php

declare(strict_types=1);

namespace Modules\Finance\Parser;

/**
 * One transaction line extracted from a bank statement export, before it
 * becomes a finance_transactions row. bankReference is whatever the bank
 * uses as a stable per-line identifier (dedup key); counterpartyAccount is
 * transient (see CategoryRuleEngine's doc comment — never persisted).
 */
final class StatementLine
{
    public function __construct(
        public readonly string $bankReference,
        public readonly \DateTimeImmutable $transactionDate,
        public readonly float $amount,
        public readonly string $label,
        public readonly ?string $counterpartyAccount = null
    ) {
    }
}
