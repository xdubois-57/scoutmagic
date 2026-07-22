<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Parser\StatementLine;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Evaluates category rules. A rule can combine up to three independent
 * conditions (keyword/regex on the label, counterparty account, amount
 * range) — every condition the rule actually sets must match (AND, not
 * OR); a rule with none set never matches anything. Two entry points, for
 * two different moments in a movement's life:
 * - countMatches() backs the config page's "Tester" button — evaluated
 *   against already-persisted transactions.
 * - apply() runs during import (Service\ImportService), evaluated
 *   against a not-yet-persisted StatementLine, in ascending priority
 *   order; the first active rule that matches wins.
 */
class CategoryRuleEngine
{
    public function __construct(
        private TransactionRepository $transactionRepository,
        private CategoryRuleRepository $categoryRuleRepository
    ) {
    }

    /**
     * How many existing transactions this rule's conditions would match.
     */
    public function countMatches(CategoryRule $rule): int
    {
        $count = 0;
        foreach ($this->transactionRepository->findAll() as $transaction) {
            if ($this->matches($rule, $transaction->label, $transaction->amount, $transaction->counterpartyAccount)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The first active rule (ascending priority) whose conditions all
     * match $line, or null when none does — the movement is then
     * imported with category_id = NULL ("À catégoriser" in the UI).
     */
    public function apply(StatementLine $line): ?int
    {
        foreach ($this->categoryRuleRepository->findActiveOrderedByPriority() as $rule) {
            if ($this->matches($rule, $line->label, $line->amount, $line->counterpartyAccount)) {
                return $rule->categoryId;
            }
        }

        return null;
    }

    /**
     * Same as apply(), against an already-persisted Transaction instead
     * of a not-yet-imported StatementLine — Service\
     * BulkCategorizationService's "run the rules on every uncategorized
     * movement" backfill.
     */
    public function applyToTransaction(Transaction $transaction): ?int
    {
        foreach ($this->categoryRuleRepository->findActiveOrderedByPriority() as $rule) {
            if ($this->matches($rule, $transaction->label, $transaction->amount, $transaction->counterpartyAccount)) {
                return $rule->categoryId;
            }
        }

        return null;
    }

    /**
     * A regex pattern is well-formed on its own — the delimiter/flags
     * CategoryRuleEngine wraps it in are always the same, so this is
     * exactly what Controller\ConfigRuleController validates against at
     * save time to reject a broken pattern immediately, rather than
     * silently saving a rule that can never match.
     */
    public static function isValidKeywordPattern(string $pattern): bool
    {
        return @preg_match(self::delimit(self::normalize($pattern)), '') !== false;
    }

    private function matches(CategoryRule $rule, string $label, float $amount, ?string $counterpartyAccount): bool
    {
        $hasCondition = false;

        if ($rule->keywordPattern !== null && trim($rule->keywordPattern) !== '') {
            $hasCondition = true;
            if (!$this->matchesKeywordPattern($label, $rule->keywordPattern)) {
                return false;
            }
        }

        if ($rule->amountRange !== null && trim($rule->amountRange) !== '') {
            $hasCondition = true;
            if (!$this->matchesAmountRange(abs($amount), $rule->amountRange)) {
                return false;
            }
        }

        if ($rule->counterpartyAccountPattern !== null && trim($rule->counterpartyAccountPattern) !== '') {
            $hasCondition = true;
            if (!$this->matchesCounterpartyAccount($counterpartyAccount, $rule->counterpartyAccountPattern)) {
                return false;
            }
        }

        return $hasCondition;
    }

    /**
     * $pattern is a regular expression (no delimiters — CategoryRuleEngine
     * supplies its own). A plain word with no regex metacharacters, e.g.
     * "delhaize", is itself already a valid pattern that matches that
     * substring — the simple "keyword" use case needs no special syntax.
     * Both sides are normalize()d first — trimmed, lowercased, and
     * stripped of accents — so "café" written by an admin matches a bank
     * label of "CAFE" or "Café" alike; the 'i' flag alone only handles
     * case, not accents. An invalid pattern (should never happen —
     * Controller\ConfigRuleController validates at save time) never
     * matches rather than crashing the import.
     */
    private function matchesKeywordPattern(string $label, string $pattern): bool
    {
        return @preg_match(self::delimit(self::normalize($pattern)), self::normalize($label)) === 1;
    }

    private static function delimit(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~u';
    }

    /**
     * Trims, lowercases, and strips accents/diacritics (canonical
     * decomposition, then drops the resulting combining marks) — the
     * shared normalization every keyword comparison goes through, on
     * both the stored pattern and the text it's matched against, so
     * neither side's accents/casing/stray whitespace can cause a false
     * negative. Falls back to a plain trim+lowercase if the intl
     * extension's Normalizer is unavailable, which only means accented
     * characters stop folding to their unaccented equivalent — matching
     * still works for everything else.
     */
    private static function normalize(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $value = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
            }
        }
        return mb_strtolower($value);
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
     * Exact or partial match on the counterparty's IBAN — $pattern may be
     * a full IBAN or a fragment of one (spaces and case ignored on both
     * sides).
     */
    private function matchesCounterpartyAccount(?string $counterpartyAccount, string $pattern): bool
    {
        if ($counterpartyAccount === null) {
            return false;
        }

        $normalize = fn(string $value): string => strtoupper(str_replace(' ', '', $value));

        return str_contains($normalize($counterpartyAccount), $normalize($pattern));
    }
}
