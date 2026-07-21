<?php

declare(strict_types=1);

namespace Modules\Finance\Parser;

/**
 * One transaction line extracted from a bank statement export, before it
 * becomes a finance_transactions row. bankReference is whatever the bank
 * uses as a stable per-line identifier (dedup key); counterpartyAccount/
 * counterpartyName are the other party's IBAN/name when the export
 * provides them — Repository\TransactionRepository persists both
 * (encrypted, like label/comment) alongside the movement, and Service\
 * CategoryRuleEngine::apply() also still reads counterpartyAccount off
 * this object directly, before persistence, for its own condition type
 * (see CategoryRuleEngine::countMatches()'s doc comment). extraDetails is
 * a single free-text field a parser concatenates every other column into
 * that doesn't get its own dedicated field — "whatever else the export
 * has" without needing a new schema column per bank format. balanceAfter
 * is the bank's own running balance after this line, when the format
 * provides one (BNP Fortis does not); currently unused but kept for
 * future parsers/reconciliation.
 */
final class StatementLine
{
    public function __construct(
        public readonly string $bankReference,
        public readonly \DateTimeImmutable $transactionDate,
        public readonly float $amount,
        public readonly string $label,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $extraDetails = null,
        public readonly ?float $balanceAfter = null
    ) {
    }
}
