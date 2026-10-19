<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Payment;

/**
 * Where a security deposit is in its own life (§6.20).
 *
 * Part of the booking's single receivable and its one communication
 * (issue #927): « reçue » is derived from the cumulative receipts reaching
 * the whole amount — rental and security deposit — never from a share of
 * them, and the final settlement leaves what is to be given back as the
 * receivable's overpayment.
 *
 * The **return half is recorded by hand** on the booking, with the date,
 * the amount and the reason for any withholding.
 */
enum SecurityDepositStatus: string
{
    /** No security deposit applies to this booking at all. */
    case NONE = 'none';
    case TO_RECEIVE = 'to_receive';
    case RECEIVED = 'received';
    case TO_RETURN = 'to_return';
    case RETURNED = 'returned';
    case PARTIALLY_WITHHELD = 'partially_withheld';
    case FULLY_WITHHELD = 'fully_withheld';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sans caution',
            self::TO_RECEIVE => 'À recevoir',
            self::RECEIVED => 'Reçue',
            self::TO_RETURN => 'À restituer',
            self::RETURNED => 'Restituée',
            self::PARTIALLY_WITHHELD => 'Partiellement retenue',
            self::FULLY_WITHHELD => 'Totalement retenue',
        };
    }

    /** Whether the unit is currently holding the renter's money. */
    public function isHeld(): bool
    {
        return $this === self::RECEIVED || $this === self::TO_RETURN;
    }

    /** Whether the deposit's life is over, one way or another. */
    public function isSettled(): bool
    {
        return match ($this) {
            self::RETURNED, self::PARTIALLY_WITHHELD, self::FULLY_WITHHELD => true,
            default => false,
        };
    }

    /**
     * The status a return of $returnedCents out of $amountCents lands on.
     *
     * Derived rather than chosen from a dropdown: a manager who typed the
     * amounts has already said what happened, and asking them to also pick
     * the matching label is how the two end up disagreeing.
     */
    public static function afterReturn(int $amountCents, int $returnedCents): self
    {
        if ($returnedCents >= $amountCents) {
            return self::RETURNED;
        }

        return $returnedCents <= 0 ? self::FULLY_WITHHELD : self::PARTIALLY_WITHHELD;
    }
}
