<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * Hand-editing a quote (§6.12), and — the part that actually matters —
 * re-quoting one without losing those edits.
 *
 * Pure, like the engine: no database, no session, no clock. Every method
 * returns a new `PriceQuote` and touches nothing.
 *
 * **A line a manager touched is never recalculated again.** That is the
 * whole rule, and `merge()` is where it is enforced: re-quoting rebuilds
 * the automatic lines from the live tariff and carries the manual ones
 * across exactly as they were. A manager who negotiated 300 € for a week
 * and then corrects the arrival date must not find their 300 € quietly
 * replaced by the grid's answer.
 *
 * The total is always recomputed from the lines, never carried across.
 * Storing a total that could disagree with the lines under it is how a
 * quote stops being explainable, which §6.10 exists to prevent.
 */
final class QuoteEditor
{
    /**
     * Replaces one line, marking it manual.
     *
     * A null argument leaves that field as it was, so "the manager only
     * changed the amount" does not have to re-send the label. The unit
     * price is dropped whenever an amount is typed directly: keeping
     * `2 × 60 €` next to an amount of `150 €` would show a line whose own
     * arithmetic does not work.
     */
    public function editLine(
        PriceQuote $quote,
        int $index,
        ?string $label = null,
        ?int $quantity = null,
        ?int $amountCents = null
    ): PriceQuote {
        $lines = $quote->lines;
        if (!isset($lines[$index])) {
            return $quote;
        }

        $existing = $lines[$index];
        $newAmount = $amountCents ?? $existing->amountCents;
        $amountWasTyped = $amountCents !== null && $amountCents !== $existing->amountCents;

        $lines[$index] = new PriceLine(
            label: $label !== null && trim($label) !== '' ? trim($label) : $existing->label,
            quantity: $quantity ?? $existing->quantity,
            unitPriceCents: $amountWasTyped ? null : $existing->unitPriceCents,
            amountCents: $newAmount,
            rule: $existing->rule,
            isInformational: $existing->isInformational,
            isManual: true,
            meta: $existing->meta
        );

        return $this->withLines($quote, $lines);
    }

    /**
     * Adds a free line at the end — a discount as a negative amount, an
     * extra service, a one-off arrangement.
     *
     * Negative amounts are allowed on purpose: "remise exceptionnelle
     * −50,00 €" as its own visible line is how a discount stays legible,
     * far better than silently shaving the base line.
     */
    public function addLine(PriceQuote $quote, string $label, int $quantity, int $amountCents): PriceQuote
    {
        $label = trim($label);
        if ($label === '') {
            return $quote;
        }

        $lines = $quote->lines;
        $lines[] = new PriceLine(
            label: $label,
            quantity: max(1, $quantity),
            unitPriceCents: null,
            amountCents: $amountCents,
            rule: PriceLine::RULE_MANUAL,
            isInformational: false,
            isManual: true
        );

        return $this->withLines($quote, $lines);
    }

    public function removeLine(PriceQuote $quote, int $index): PriceQuote
    {
        $lines = $quote->lines;
        if (!isset($lines[$index])) {
            return $quote;
        }

        unset($lines[$index]);

        return $this->withLines($quote, array_values($lines));
    }

    /**
     * Re-quotes without losing hand edits: automatic lines come from
     * $recomputed, manual lines are carried over from $current.
     *
     * Manual lines keep their original order relative to each other and are
     * appended after the automatic ones. Trying to restore their exact
     * former positions would mean guessing where a line belongs among a set
     * of lines that no longer exists, and getting it wrong silently.
     *
     * Everything else about the quote — nights, head count, period,
     * category, warnings — comes from $recomputed: those describe the stay
     * as it is now, and keeping the old ones is what would make the quote
     * disagree with the booking.
     */
    public function merge(PriceQuote $recomputed, PriceQuote $current): PriceQuote
    {
        $manual = array_values(array_filter($current->lines, fn(PriceLine $l) => $l->isManual));
        $automatic = array_values(array_filter($recomputed->lines, fn(PriceLine $l) => !$l->isManual));

        return $this->withLines($recomputed, array_merge($automatic, $manual));
    }

    /**
     * Whether $quote carries any hand-edited line — what the interface uses
     * to warn that a re-quote will not restore the tariff's own answer.
     */
    public function hasManualLines(PriceQuote $quote): bool
    {
        foreach ($quote->lines as $line) {
            if ($line->isManual) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param PriceLine[] $lines
     */
    private function withLines(PriceQuote $quote, array $lines): PriceQuote
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
