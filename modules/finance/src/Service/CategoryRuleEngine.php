<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Evaluates a category rule's condition against a transaction. Only
 * countMatches() is implemented this iteration, backing the config
 * page's "Tester" button (module spec §"Règles de catégorisation").
 * Applying rules to categorize incoming transactions during a bank
 * statement import is "itération 3" functionality — deliberately not
 * built yet (see Service\ImportService).
 */
class CategoryRuleEngine
{
    public function __construct(private TransactionRepository $transactionRepository)
    {
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
            if ($this->matchesCondition($transaction, $rule->conditionType, $rule->conditionValue)) {
                $count++;
            }
        }
        return $count;
    }

    private function matchesCondition(Transaction $transaction, string $conditionType, string $conditionValue): bool
    {
        return match ($conditionType) {
            CategoryRule::CONDITION_KEYWORD => $this->matchesKeyword($transaction->label, $conditionValue),
            CategoryRule::CONDITION_AMOUNT_RANGE => $this->matchesAmountRange(abs($transaction->amount), $conditionValue),
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
}
