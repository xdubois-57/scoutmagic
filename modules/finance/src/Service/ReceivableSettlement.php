<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * Where one receivable stands: what it asks for, what has actually been
 * allocated to it, and what arrived for it that it could not absorb.
 *
 * Everything here is derived — from finance_receivable_allocations, from
 * the receivable's own abandon and refund-request marks, and from the
 * credits whose text spells out its communication. Nothing is stored,
 * which is what lets a correction take effect the moment it is made.
 */
final class ReceivableSettlement
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_WAIVED = 'waived';

    /** No surplus at all — the ordinary case. */
    public const REFUND_NONE = 'none';
    /** A surplus exists and nobody has decided anything about it yet. */
    public const REFUND_OPEN = 'open';
    /** Somebody decided it is owed back; no debit has gone out yet. */
    public const REFUND_REQUESTED = 'requested';
    /** A debit covering it has been allocated: the money really left. */
    public const REFUND_DONE = 'refunded';

    public function __construct(
        public readonly int $receivableId,
        public readonly int $amountDueCents,
        /** Sum of the settling allocations — what this receivable has actually absorbed. */
        public readonly int $amountAllocatedCents,
        /** What arrived designating this receivable, absorbed or not. */
        public readonly int $amountDesignatedCents,
        /** Sum of the refund allocations, as a positive number. */
        public readonly int $amountRefundedCents,
        /** Still owed back: designated beyond due, less what has been refunded. */
        public readonly int $amountOverpaidCents,
        /** @var self::STATUS_* */
        public readonly string $status,
        /** @var self::REFUND_* */
        public readonly string $refundState
    ) {
    }

    /** What is still expected. Never negative: a surplus is not a debt owed to nobody. */
    public function amountRemainingCents(): int
    {
        return max(0, $this->amountDueCents - $this->amountAllocatedCents);
    }

    public function isWaived(): bool
    {
        return $this->status === self::STATUS_WAIVED;
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_PAID || $this->status === self::STATUS_WAIVED;
    }

    /**
     * Whether a treasurer still has something to do here: money is
     * missing, or money is sitting on the account that this receivable
     * was never entitled to. A paid receivable carrying a surplus nobody
     * has decided about is NOT done, which is why the campaign screen's
     * default filter uses this rather than the status alone.
     */
    public function needsAttention(): bool
    {
        if ($this->amountOverpaidCents > 0 && $this->refundState !== self::REFUND_DONE) {
            return true;
        }

        return $this->status === self::STATUS_UNPAID || $this->status === self::STATUS_PARTIAL;
    }

    /**
     * The shape Modules\Finance\Api\ExpectedReceivableInterface has
     * always returned, so a consuming module keeps reading what it read
     * before.
     *
     * **`amount_received` is what ARRIVED, not what was absorbed**, and
     * the two parted company the day allocations started being capped at
     * the amount due. A rental of 467,50 € paid 500 € has allocated
     * 467,50 €; reporting that would clamp the surplus out of existence
     * on the very page a renter looks at to see it. `status` comes from
     * the allocations, `amount_received` from what the account actually
     * saw — which is also why it can exceed `amount_due`, exactly as it
     * did before this class existed.
     *
     * @return array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'|'waived'}
     */
    public function toApiArray(): array
    {
        /** @var 'paid'|'partial'|'unpaid'|'waived' $status */
        $status = $this->status;

        return [
            'amount_due' => $this->amountDueCents,
            'amount_received' => $this->amountDesignatedCents,
            'status' => $status,
        ];
    }
}
