<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * The one thing a manager adds to a price: a discount (#927, chantier
 * §2.3).
 *
 * A booking's price is the tariff's answer for its dates and its
 * participants — every line read only, recomputed whenever those change —
 * plus **one free « Remise » line at most**, a label and a negative
 * amount. There is no editing a line, no adding one, and no « recalculer »:
 * a price that can be hand-edited line by line is one nobody can explain a
 * month later, and a re-quote that keeps some lines and not others is a
 * second rule the renter has to trust.
 *
 * Pure, like the engine: no database, no session, no clock. The total is
 * always recomputed from the lines, never carried across.
 */
final class QuoteEditor
{
    /** The label a discount is given when the manager types none. */
    public const DEFAULT_DISCOUNT_LABEL = 'Remise';

    /**
     * `$tariff` — the engine's own quote — with the discount appended as
     * its own visible line, and any discount it carried dropped. A zero or
     * missing amount adds nothing; a positive one is not a discount and is
     * left to the caller to refuse.
     */
    public function withDiscount(PriceQuote $tariff, ?string $label, ?int $discountCents): PriceQuote
    {
        $lines = array_values(array_filter(
            $tariff->lines,
            static fn(PriceLine $line): bool => $line->rule !== PriceLine::RULE_DISCOUNT
        ));

        if ($discountCents !== null && $discountCents < 0) {
            $label = trim((string) $label);
            $lines[] = new PriceLine(
                label: $label !== '' ? $label : self::DEFAULT_DISCOUNT_LABEL,
                quantity: 1,
                unitPriceCents: null,
                amountCents: $discountCents,
                rule: PriceLine::RULE_DISCOUNT,
                isInformational: false,
                isManual: true
            );
        }

        return self::withLines($tariff, $lines);
    }

    /** The discount line of a price, or null. */
    public static function discountLine(?PriceQuote $quote): ?PriceLine
    {
        foreach ($quote->lines ?? [] as $line) {
            if ($line->rule === PriceLine::RULE_DISCOUNT) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param PriceLine[] $lines
     */
    private static function withLines(PriceQuote $quote, array $lines): PriceQuote
    {
        $total = 0;
        foreach ($lines as $line) {
            if (!$line->isInformational) {
                $total += $line->amountCents;
            }
        }

        return new PriceQuote(
            lines: array_values($lines),
            totalCents: $total,
            nights: $quote->nights,
            persons: $quote->persons,
            quantity: $quote->quantity,
            billingUnit: $quote->billingUnit,
            period: $quote->period,
            category: $quote->category,
            minimumApplied: $quote->minimumApplied,
            warnings: $quote->warnings
        );
    }
}
