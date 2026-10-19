<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * One bank movement as one receivable sees it
 * (ExpectedReceivableInterface::getReceivableMovements()): a payment it
 * absorbed, or a refund of its surplus.
 *
 * **The amount is this receivable's share, never the movement's whole
 * amount.** A single transfer may pay several receivables — three
 * siblings, one household — and the whole amount, the other shares and
 * whom they pay are none of the consuming module's business. Positive is
 * a payment, negative a refund; a movement a treasurer ruled out (a
 * zero-amount allocation) is not a movement of this receivable at all and
 * is never handed out.
 *
 * The label and the counterparty are the bank's own text for that
 * movement, decrypted — what the payer (or, for a refund, the unit) wrote
 * on the transfer, for the screen that shows the payer their own money.
 */
final class ReceivableMovement
{
    public function __construct(
        /** The movement's booking date, `Y-m-d`. */
        public readonly string $date,
        public readonly string $label,
        public readonly ?string $counterpartyName,
        /** This receivable's share, in cents: positive paid it, negative refunded its surplus. */
        public readonly int $amountCents,
        /** True when the site matched it by its communication, false when a person did. */
        public readonly bool $automatic
    ) {
    }

    public function isRefund(): bool
    {
        return $this->amountCents < 0;
    }
}
