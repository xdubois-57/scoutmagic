<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

/**
 * One transaction line extracted from a bank statement export, before it
 * becomes a finance_transactions row. accountIban is the unit's OWN account
 * the line was booked on, normalized by Service\IbanNormalizer — a file may
 * cover several accounts, and Service\ImportService sends each line to the
 * site account carrying that IBAN. bankReference is whatever the bank
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
 * future parsers/reconciliation. structuredCommunication is the Belgian
 * structured communication as its twelve digits, when the format carries it
 * in a field of its own (CODA does; the BNP CSV drowns it in free text,
 * where Service\StructuredCommunicationService::extract() still finds it) —
 * kept apart rather than concatenated into extraDetails, so reconciliation
 * reads it clean.
 */
final class StatementLine
{
    public function __construct(
        public readonly string $accountIban,
        public readonly string $bankReference,
        public readonly \DateTimeImmutable $transactionDate,
        public readonly float $amount,
        public readonly string $label,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $extraDetails = null,
        public readonly ?float $balanceAfter = null,
        public readonly ?string $structuredCommunication = null
    ) {
    }
}
