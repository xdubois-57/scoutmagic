<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * "This much of that bank movement pays for this receivable."
 *
 * The sign of $amountCents is the row's meaning, not a detail: positive
 * settles, zero is a human's "this movement pays nothing towards this
 * receivable" (which the automatic pass must not undo), negative is a
 * debit refunding an overpayment. See finance_receivable_allocations in
 * schema.sql.
 */
final class ReceivableAllocation
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public readonly int $id,
        public readonly int $transactionId,
        public readonly int $receivableId,
        public readonly int $amountCents,
        public readonly string $source,
        public readonly ?int $createdBy,
        public readonly string $createdAt
    ) {
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /** A settlement, as opposed to a refund or a human's "pays nothing". */
    public function isSettlement(): bool
    {
        return $this->amountCents > 0;
    }

    /** A debit paying an overpayment back. */
    public function isRefund(): bool
    {
        return $this->amountCents < 0;
    }
}
