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
 * A separate workflow from the rental price, with its own receivable and
 * its own structured communication — and it **never counts as rental
 * revenue**. A unit holding 500 € of somebody else's money has not earned
 * 500 €.
 *
 * The **return half is tracked by hand**, deliberately. Finance reconciles
 * incoming transfers, not outgoing ones, so "we paid it back" cannot be
 * detected from a bank statement. Inventing that would be out of scope;
 * the limitation is documented rather than hidden, and the manager records
 * the date, the amount and the reason.
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
