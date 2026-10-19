<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Where one receivable stands, with the refund side of it
 * (ExpectedReceivableInterface::getReceivableStanding()) — what
 * getReceivableStatus() cannot say: how much went back, and how much is
 * still owed back.
 *
 * Every figure is in cents and derived from the bank movements, never
 * stored: « refunded » is real because a debit left the account, not
 * because somebody ticked a box.
 */
final class ReceivableStanding
{
    public function __construct(
        /** What the receivable asks for now. */
        public readonly int $amountDueCents,
        /**
         * What arrived designating it, absorbed or not — the same figure
         * as getReceivableStatus()'s `amount_received`, so it can exceed
         * the amount due.
         */
        public readonly int $amountReceivedCents,
        /** What debits have already given back, as a positive number. */
        public readonly int $amountRefundedCents,
        /** Still owed back to the payer: received beyond due, less what was refunded. Never negative. */
        public readonly int $amountOverpaidCents,
        /** Still expected from the payer. Never negative. */
        public readonly int $amountRemainingCents,
        /** @var 'paid'|'partial'|'unpaid'|'waived' */
        public readonly string $status
    ) {
    }

    public function isOverpaid(): bool
    {
        return $this->amountOverpaidCents > 0;
    }
}
