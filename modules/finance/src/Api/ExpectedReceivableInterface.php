<?php

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5).
 * Registers and tracks generic "money we expect to receive" entries,
 * keyed by (source_module, source_reference_id) — the consuming module
 * never touches Finance's internal tables directly.
 */
interface ExpectedReceivableInterface
{
    /**
     * Registers an expected payment. $amountCents and $communication are
     * caller-computed (see StructuredCommunicationInterface::generate()).
     * Returns the new receivable's id.
     */
    public function createReceivable(
        string $sourceModule,
        int $sourceReferenceId,
        int $accountId,
        int $amountCents,
        string $communication,
        ?string $label
    ): int;

    /**
     * @return array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'}
     */
    public function getReceivableStatus(int $receivableId): array;

    /**
     * Removes every receivable registered for this source instance — a
     * consuming module calls this when the source itself is deleted (e.g.
     * the news module deleting an article's form and all its responses).
     */
    public function deleteReceivablesForSource(string $sourceModule, int $sourceReferenceId): void;
}
