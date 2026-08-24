<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * What Service\RentalPricingEngine returns: the structured, **directly
 * snapshotable** result of §6.10.
 *
 * Snapshotable is the operative word. This same object serves all three of
 * the amounts §6.11 warns never to confuse:
 *
 * - the **estimated price**, recomputed on demand and never frozen;
 * - the **agreed price**, this object serialised at the moment of agreement
 *   and never recomputed again — which is why every fact a line depends on
 *   (period, category, minimum, billing unit) is carried *in* the result
 *   rather than looked up from the asset later. Changing the asset's rates
 *   afterwards must not move a price already agreed, and it cannot if the
 *   snapshot is self-contained;
 * - the **final settlement**, built from the agreed snapshot plus real meter
 *   readings.
 *
 * `$warnings` is deliberately part of the result rather than an exception:
 * an incomplete configuration should still produce a usable, visibly
 * incomplete quote — "dès 12,50 €" with a note — rather than a blank page.
 */
final class PriceQuote
{
    /**
     * @param PriceLine[] $lines
     * @param string[] $warnings User-facing French sentences.
     * @param PricePeriod|null $period The period the stay is charged under; the arrival period when a stay spans several.
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $totalCents,
        public readonly int $nights,
        public readonly int $persons,
        public readonly int $quantity,
        public readonly BillingUnit $billingUnit,
        public readonly ?PricePeriod $period = null,
        public readonly ?RenterCategory $category = null,
        public readonly bool $minimumApplied = false,
        public readonly array $warnings = []
    ) {
    }

    /**
     * Whether the total can be presented as a real price rather than as a
     * starting point. False whenever something needed is still missing — the
     * public page shows "dès X €" in that case (§6.7), never a firm figure
     * it would have to walk back.
     */
    public function isComplete(): bool
    {
        return $this->warnings === [];
    }

    /**
     * Lines that make up the total, excluding the informational ones.
     *
     * @return PriceLine[]
     */
    public function billableLines(): array
    {
        return array_values(array_filter($this->lines, fn(PriceLine $l) => !$l->isInformational));
    }

    /**
     * Whether this quote actually carries a price, as opposed to a table
     * of lines adding up to nothing.
     *
     * An asset with no rate configured produces a quote whose every
     * billable line is zero: rendered as a price table it reads "Total
     * 0,00 €", which a renter takes as "free" and the unit then has to
     * walk back. Everywhere that would show a total asks this first and
     * says "Tarif sur demande" instead.
     *
     * Informational lines do not count: a meter fee is settled after the
     * stay on a real reading, so a quote made only of those still has no
     * price to show.
     */
    public function hasPricedLine(): bool
    {
        foreach ($this->billableLines() as $line) {
            if ($line->amountCents !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Meter fees and anything else shown but not totalled — quoted as
     * "billed on actual consumption" and settled after the stay (§6.10).
     *
     * @return PriceLine[]
     */
    public function informationalLines(): array
    {
        return array_values(array_filter($this->lines, fn(PriceLine $l) => $l->isInformational));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn(PriceLine $l) => $l->toArray(), $this->lines),
            'total_cents' => $this->totalCents,
            'nights' => $this->nights,
            'persons' => $this->persons,
            'quantity' => $this->quantity,
            'billing_unit' => $this->billingUnit->value,
            'is_night_based' => $this->billingUnit->isNightBased(),
            'period' => $this->period !== null
                ? ['id' => $this->period->id, 'label' => $this->period->label]
                : null,
            'category' => $this->category !== null
                ? ['id' => $this->category->id, 'label' => $this->category->label]
                : null,
            'minimum_applied' => $this->minimumApplied,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Rebuilds a quote from its snapshot.
     *
     * The period and category come back as **labelled stand-ins carrying the
     * snapshotted label**, not as a fresh lookup of the live configuration:
     * a snapshot has to keep reading the way it did when it was taken, even
     * after the season was renamed or the category deleted. That is the
     * whole point of snapshotting it.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $periodData = is_array($data['period'] ?? null) ? $data['period'] : null;
        $categoryData = is_array($data['category'] ?? null) ? $data['category'] : null;

        return new self(
            lines: array_map(
                fn(array $line) => PriceLine::fromArray($line),
                is_array($data['lines'] ?? null) ? $data['lines'] : []
            ),
            totalCents: (int) ($data['total_cents'] ?? 0),
            nights: (int) ($data['nights'] ?? 0),
            persons: (int) ($data['persons'] ?? 0),
            quantity: (int) ($data['quantity'] ?? 0),
            billingUnit: BillingUnit::from((string) ($data['billing_unit'] ?? BillingUnit::FLAT_STAY->value)),
            period: $periodData !== null
                ? new PricePeriod((int) $periodData['id'], (string) $periodData['label'], '', '')
                : null,
            category: $categoryData !== null
                ? new RenterCategory((int) $categoryData['id'], (string) $categoryData['label'])
                : null,
            minimumApplied: (bool) ($data['minimum_applied'] ?? false),
            warnings: array_values(array_map('strval', is_array($data['warnings'] ?? null) ? $data['warnings'] : []))
        );
    }
}
