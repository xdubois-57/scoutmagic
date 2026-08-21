<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     *
     * $communication must contain at least one digit: settlement is
     * detected by matching its digits against the free text of the
     * account's incoming transactions, so a digit-less communication
     * could never be matched — it is rejected with a
     * Modules\Finance\Service\FinanceException rather than stored as a
     * receivable that can never be settled.
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
     * Changes what an existing receivable expects, keeping its
     * communication — and therefore every payment already matched against
     * it.
     *
     * A receivable's amount is not fixed for life: a negotiated price
     * changes, a final settlement replaces an estimate, an order is
     * amended. Deleting and recreating would mint a new communication and
     * orphan the transfers the payer already made against the old one, so
     * the amount moves in place instead.
     *
     * **Lowering it below what has already come in is refused** unless the
     * caller says, in so many words, that it means to create an
     * overpayment. Silently producing one is how a refund nobody knows
     * about happens: the receivable reads "paid", the surplus sits on the
     * account, and nothing anywhere says somebody is owed money. With
     * $allowBelowReceived the caller has stated it knows, and owes the
     * payer the difference.
     *
     * Deliberately generic: nothing in this signature names a module, and
     * the same call serves a rental's renegotiated price, an event's
     * amended order, or anything later.
     *
     * @throws \Modules\Finance\Service\FinanceException when the receivable
     *         does not exist, when $amountCents is negative, or when it is
     *         below the amount already received and $allowBelowReceived is
     *         false.
     */
    public function updateReceivableAmount(
        int $receivableId,
        int $amountCents,
        bool $allowBelowReceived = false
    ): void;

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
