<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * Everything one asset's price depends on — the four configuration blocks of
 * module spec §6.10, and nothing else:
 *
 * 1. the billing unit,
 * 2. a unit-price grid on **two axes and two only**: period × renter category,
 * 3. a billable minimum: a floor amount **or** a floor number of people,
 * 4. an ordered list of fees, each of one of three natures.
 *
 * Deliberately a plain immutable value object with no database in it, so
 * Service\RentalPricingEngine stays a pure function of its inputs and the
 * public page, the simulator, the quote and the contract can all be shown to
 * use literally the same code path.
 *
 * **What must never be added here**: duration tiers, progressive rates,
 * day-of-week rates, a per-person price detached from time, base + supplement
 * beyond a threshold, or package prices stacked on the unit price. All are
 * explicitly out of scope (§6.10) — the narrowness is the feature, and each
 * of them turns "quantity × unit price" into a resolution problem.
 */
final class PricingSettings
{
    /**
     * @param PricePeriod[] $periods Seasons, in configuration order. May be empty: an asset with one rate all year needs no period at all.
     * @param RenterCategory[] $categories Configurable renter categories (youth movement, non-profit, private, company, internal…).
     * @param array<string, int> $priceGridCents Unit price in cents, keyed by self::gridKey(periodId, categoryId).
     * @param int|null $defaultUnitPriceCents Rate used when the grid has no cell for the resolved (period, category). Null means "not priced yet", which produces a warning rather than a silent zero.
     * @param int|null $minimumAmountCents Floor for the base subtotal. Mutually exclusive with $minimumPersons.
     * @param int|null $minimumPersons Floor for the person count. Mutually exclusive with $minimumAmountCents.
     * @param RentalFee[] $fees Ordered; meter fees are quoted separately (see RentalFee).
     */
    public function __construct(
        public readonly BillingUnit $billingUnit,
        public readonly array $periods = [],
        public readonly array $categories = [],
        public readonly array $priceGridCents = [],
        public readonly ?int $defaultUnitPriceCents = null,
        public readonly ?int $minimumAmountCents = null,
        public readonly ?int $minimumPersons = null,
        public readonly array $fees = []
    ) {
    }

    /**
     * The grid is keyed rather than nested so a missing cell is simply an
     * absent key — a two-level array would need every period to carry every
     * category, and a newly added category would silently price at zero
     * everywhere until someone filled the whole column.
     */
    public static function gridKey(?int $periodId, ?int $categoryId): string
    {
        return ($periodId ?? 0) . ':' . ($categoryId ?? 0);
    }

    /**
     * The unit price for a (period, category) pair, or null when neither the
     * grid nor a default rate covers it.
     *
     * Resolution is a single lookup with one fallback, never a search for
     * "the most specific matching rule": there is no rule precedence in this
     * engine (§6.10), and adding one here is how it would creep back in.
     */
    public function unitPriceCentsFor(?int $periodId, ?int $categoryId): ?int
    {
        return $this->priceGridCents[self::gridKey($periodId, $categoryId)]
            ?? $this->defaultUnitPriceCents;
    }

    /**
     * The period covering $date, or null when no period does (an asset with
     * no seasons at all, or a date outside every configured season).
     *
     * The first match wins. Overlapping periods are a configuration mistake
     * rather than something to resolve — the engine reports it as a warning
     * instead of quietly picking a winner.
     */
    public function periodForDate(\DateTimeImmutable $date): ?PricePeriod
    {
        foreach ($this->periods as $period) {
            if ($period->covers($date)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * @return PricePeriod[] Every period covering $date — more than one means the configuration overlaps.
     */
    public function allPeriodsForDate(\DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            $this->periods,
            fn(PricePeriod $period) => $period->covers($date)
        ));
    }

    public function hasMinimum(): bool
    {
        return $this->minimumAmountCents !== null || $this->minimumPersons !== null;
    }

    /**
     * Whether any rate is configured at all — a default rate or at least one
     * grid cell. False means every quote for this asset warns "aucun tarif
     * n'est encore défini", whatever the dates: the public page then offers
     * "tarif sur demande" instead of a 0,00 € table, and the managed space
     * shows the setup warning that points at the tariff page.
     */
    public function hasAnyRate(): bool
    {
        return $this->defaultUnitPriceCents !== null || $this->priceGridCents !== [];
    }
}
