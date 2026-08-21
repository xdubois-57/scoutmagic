<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Payment;

/**
 * How an asset's deposit is worked out (§6.19).
 *
 * Whatever the mode, the result is a **threshold on the single rental
 * receivable**, never a second receivable. Two receivables would mean two
 * communications, and a renter paying the balance with the deposit's
 * reference would settle the wrong one.
 */
enum DepositMode: string
{
    case NONE = 'none';
    case FIXED = 'fixed';
    case PERCENTAGE = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::NONE => "Pas d'acompte",
            self::FIXED => 'Montant fixe',
            self::PERCENTAGE => 'Pourcentage du total',
        };
    }

    /**
     * The deposit for a rental totalling $totalCents.
     *
     * Rounded **up** to the cent: a deposit that rounds down leaves a
     * threshold a renter can reach while still owing a cent, and "acompte
     * reçu" would flip on a payment that is a cent short.
     *
     * Never more than the total — a 100 % deposit is the whole rental, and
     * a percentage misconfigured above that must not ask for more than the
     * rental is worth.
     */
    public function amountFor(int $totalCents, ?int $fixedCents, ?int $percentage): ?int
    {
        $amount = match ($this) {
            self::NONE => null,
            self::FIXED => $fixedCents,
            self::PERCENTAGE => $percentage !== null
                ? (int) ceil($totalCents * max(0, min(100, $percentage)) / 100)
                : null,
        };

        if ($amount === null) {
            return null;
        }

        return max(0, min($amount, $totalCents));
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }
}
