<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Transaction
{
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_MANUAL = 'manual';

    public const CATEGORY_SOURCE_MANUAL = 'manual';
    public const CATEGORY_SOURCE_AUTO = 'auto';

    public function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly int $fiscalYearId,
        public readonly ?string $bankReference,
        public readonly string $transactionDate,
        public readonly string $label,
        public readonly float $amount,
        public readonly ?int $categoryId,
        public readonly ?string $comment,
        public readonly string $source,
        public readonly ?string $importedAt,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $extraDetails = null,
        public readonly ?string $categorySource = null
    ) {
    }

    /**
     * Whether category_id was set by Service\CategoryRuleEngine (at
     * import) or Service\BulkCategorizationService (rules or AI,
     * backfilling) rather than an admin picking it by hand on the
     * movements page — Controller\MovementController's "Automatique"
     * badge.
     */
    public function isCategoryAutoAssigned(): bool
    {
        return $this->categorySource === self::CATEGORY_SOURCE_AUTO;
    }

    /**
     * Free-text search used by both Repository\TransactionRepository::
     * findFiltered() and Controller\DashboardController's own movements
     * filter — a single source of truth for "which fields does a
     * movement search match on" so the two never drift apart. label/
     * comment/counterparty name/counterparty account/extra_details are
     * all encrypted (non-deterministic ciphertext), so this is always
     * run against already-decrypted values in PHP, never as a SQL LIKE.
     */
    public function matchesTextSearch(string $search): bool
    {
        if (mb_stripos($this->label, $search) !== false) {
            return true;
        }
        if ($this->comment !== null && mb_stripos($this->comment, $search) !== false) {
            return true;
        }
        if ($this->counterpartyName !== null && mb_stripos($this->counterpartyName, $search) !== false) {
            return true;
        }
        if ($this->counterpartyAccount !== null && mb_stripos($this->counterpartyAccount, $search) !== false) {
            return true;
        }
        if ($this->extraDetails !== null && mb_stripos($this->extraDetails, $search) !== false) {
            return true;
        }
        return str_contains(number_format($this->amount, 2, '.', ''), $search);
    }
}
