<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Parser\StatementLine;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Evaluates category rules. Two entry points, for two different moments
 * in a movement's life:
 * - countMatches() backs the config page's "Tester" button — evaluated
 *   against already-persisted transactions.
 * - apply() runs during import (Service\ImportService), evaluated
 *   against a not-yet-persisted StatementLine, in ascending priority
 *   order; the first active rule that matches wins. Only apply() can
 *   evaluate "counterparty_account" — that data is never persisted on
 *   finance_transactions (see countMatches()'s doc comment), so it only
 *   ever exists at this transient, pre-persistence stage.
 */
class CategoryRuleEngine
{
    public function __construct(
        private TransactionRepository $transactionRepository,
        private CategoryRuleRepository $categoryRuleRepository
    ) {
    }

    /**
     * How many existing transactions this rule's condition would match —
     * "keyword" and "amount_range" are evaluated against the persisted
     * label/amount; "counterparty_account" always returns 0 here, since
     * the counterparty IBAN is only ever available at import time (from
     * the freshly-parsed bank statement line), never persisted on the
     * transaction itself afterwards.
     */
    public function countMatches(CategoryRule $rule): int
    {
        if ($rule->conditionType === CategoryRule::CONDITION_COUNTERPARTY_ACCOUNT) {
            return 0;
        }

        $count = 0;
        foreach ($this->transactionRepository->findAll() as $transaction) {
            if ($this->matchesTransaction($transaction, $rule->conditionType, $rule->conditionValue)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The first active rule (ascending priority) whose condition matches
     * $line, or null when none does — the movement is then imported with
     * category_id = NULL ("À catégoriser" in the UI).
     */
    public function apply(StatementLine $line): ?int
    {
        foreach ($this->categoryRuleRepository->findActiveOrderedByPriority() as $rule) {
            if ($this->matchesStatementLine($line, $rule->conditionType, $rule->conditionValue)) {
                return $rule->categoryId;
            }
        }

        return null;
    }

    private function matchesTransaction(Transaction $transaction, string $conditionType, string $conditionValue): bool
    {
        return match ($conditionType) {
            CategoryRule::CONDITION_KEYWORD => $this->matchesKeyword($transaction->label, $conditionValue),
            CategoryRule::CONDITION_AMOUNT_RANGE => $this->matchesAmountRange(abs($transaction->amount), $conditionValue),
            default => false,
        };
    }

    private function matchesStatementLine(StatementLine $line, string $conditionType, string $conditionValue): bool
    {
        return match ($conditionType) {
            CategoryRule::CONDITION_KEYWORD => $this->matchesKeyword($line->label, $conditionValue),
            CategoryRule::CONDITION_AMOUNT_RANGE => $this->matchesAmountRange(abs($line->amount), $conditionValue),
            CategoryRule::CONDITION_COUNTERPARTY_ACCOUNT => $this->matchesCounterpartyAccount($line->counterpartyAccount, $conditionValue),
            default => false,
        };
    }

    private function matchesKeyword(string $label, string $keyword): bool
    {
        if (trim($keyword) === '') {
            return false;
        }
        return mb_stripos($label, $keyword) !== false;
    }

    /**
     * $range is either ">N" (strictly greater than N) or "N-M" (inclusive
     * range), evaluated against an already-absolute amount.
     */
    private function matchesAmountRange(float $amount, string $range): bool
    {
        $range = trim($range);

        if (str_starts_with($range, '>')) {
            $threshold = (float) substr($range, 1);
            return $amount > $threshold;
        }

        if (str_contains($range, '-')) {
            [$min, $max] = array_map('trim', explode('-', $range, 2));
            if ($min === '' || $max === '') {
                return false;
            }
            return $amount >= (float) $min && $amount <= (float) $max;
        }

        return false;
    }

    /**
     * Exact or partial match on the counterparty's IBAN — conditionValue
     * may be a full IBAN or a fragment of one (spaces and case ignored on
     * both sides).
     */
    private function matchesCounterpartyAccount(?string $counterpartyAccount, string $conditionValue): bool
    {
        if ($counterpartyAccount === null || trim($conditionValue) === '') {
            return false;
        }

        $normalize = fn(string $value): string => strtoupper(str_replace(' ', '', $value));

        return str_contains($normalize($counterpartyAccount), $normalize($conditionValue));
    }
}
