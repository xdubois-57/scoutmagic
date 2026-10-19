<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * A debit that could be the refund of one receivable's surplus
 * (ExpectedReceivableInterface::findRefundCandidates()): the transfer a
 * manager made from the bank and that carried no communication, so the
 * import could not recognise it on its own.
 *
 * **Three facts and an identifier, nothing else.** The candidates are
 * movements of the unit's account that belong to nobody yet — they may
 * just as well be a hardware shop's invoice — so the consuming module is
 * told only what a person needs to recognise their own transfer: when,
 * how much, to whom. Never the bank label, the counterparty's account
 * number, or anything about the rest of the account.
 */
final class RefundCandidate
{
    public function __construct(
        /** What ExpectedReceivableInterface::reconcileRefund() takes back. */
        public readonly int $transactionId,
        /** The debit's booking date, `Y-m-d`. */
        public readonly string $date,
        /** The amount that left, as a positive number of cents. */
        public readonly int $amountCents,
        public readonly ?string $counterpartyName
    ) {
    }
}
