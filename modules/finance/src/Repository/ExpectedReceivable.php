<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class ExpectedReceivable
{
    public function __construct(
        public readonly int $id,
        public readonly string $sourceModule,
        public readonly int $sourceReferenceId,
        public readonly int $accountId,
        public readonly int $amountDueCents,
        public readonly string $communication,
        public readonly ?string $label,
        public readonly string $createdAt,
        /**
         * Who owes this, when the debtor is a member of the unit — always
         * set by a payment campaign, never by `rental`, which invoices
         * outside renters. `members.id`, the persistent identity: a
         * receivable outlives the scout year that saw it born.
         */
        public readonly ?int $memberId = null,
        public readonly ?string $waivedAt = null,
        public readonly ?int $waivedBy = null,
        public readonly ?string $refundRequestedAt = null,
        public readonly ?int $refundRequestedBy = null
    ) {
    }

    /**
     * A dispense, a goodwill gesture, an invoicing mistake: the
     * receivable is settled and nothing entered the account. Kept
     * strictly apart from being paid — confounding the two would inflate
     * every incoming total a treasurer reads.
     */
    public function isWaived(): bool
    {
        return $this->waivedAt !== null;
    }

    /** Somebody has decided the surplus on this receivable is owed back. */
    public function isRefundRequested(): bool
    {
        return $this->refundRequestedAt !== null;
    }
}
