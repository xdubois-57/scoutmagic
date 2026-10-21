<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * A credit that could be a payment of one receivable
 * (ExpectedReceivableInterface::findPaymentCandidates()): a transfer that
 * arrived without the receivable's communication — a renter's insurer
 * settling a damage, a relative paying on their behalf — so the import
 * could not recognise it on its own.
 *
 * **Four facts and an identifier, nothing else.** The candidates are
 * movements of the unit's account that belong to nobody yet — they may as
 * well be another family's payment — so the consuming module is told only
 * what a person needs to recognise the transfer they are looking for:
 * when, how much, from whom, and what the payer wrote on it. Never the
 * counterparty's account number, a treasurer's comment, or anything about
 * the rest of the account. The list itself is narrowed before any of it
 * is handed out: the exact amount the person typed, a date, credits
 * reconciled with nothing and carrying no receivable's communication.
 */
final class PaymentCandidate
{
    public function __construct(
        /** What ExpectedReceivableInterface::reconcilePayment() takes back. */
        public readonly int $transactionId,
        /** The credit's booking date, `Y-m-d`. */
        public readonly string $date,
        /** The amount that came in, in cents — always the amount searched for. */
        public readonly int $amountCents,
        public readonly ?string $counterpartyName,
        /** The bank's free text for the transfer, decrypted. */
        public readonly string $label
    ) {
    }
}
