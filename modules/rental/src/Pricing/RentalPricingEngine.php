<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * The whole of §6.10's pricing model, and nothing else.
 *
 * ```
 * quantity × unit price   (quantity raised to the floor if a minimum applies)
 * + fees
 * = total
 * ```
 *
 * **There is no rule precedence, no resolution, and no rule that cancels
 * another.** That is the core of the simplification, and it is what makes a
 * quote explainable to a renter line by line. Duration tiers, progressive
 * rates, day-of-week rates, a per-person price detached from time, base +
 * supplement beyond a threshold, packages stacked on the unit price: all
 * explicitly out of scope, and each one would turn this method into a
 * resolver. Do not reintroduce them.
 *
 * **Pure**: no database, no session, no clock. The same engine serves the
 * public page, the configuration simulator, the agreed price and the
 * contract — the simulator is only a guard-rail against a wrong tariff if it
 * is provably the same code, so there is deliberately no second path.
 *
 * All money is in **cents**, as integers, everywhere. A price is never a
 * float at any point.
 */
class RentalPricingEngine
{
    public function quote(PricingSettings $settings, PricingRequest $request): PriceQuote
    {
        $warnings = [];
        $nights = $request->nights();
        $unit = $settings->billingUnit;

        $category = $this->resolveCategory($settings, $request, $warnings);
        $period = $this->resolvePeriod($settings, $request, $warnings);

        // The minimum is applied BEFORE the quantity is computed, and stays
        // visible in the line label — never applied afterwards as a silently
        // corrected total (§6.10).
        $effectivePersons = $request->persons;
        $minimumPersonsApplied = false;
        if ($settings->minimumPersons !== null && $unit->isPersonBased() && $request->persons < $settings->minimumPersons) {
            $effectivePersons = $settings->minimumPersons;
            $minimumPersonsApplied = true;
        }

        $quantity = $unit->quantityFor($nights, $effectivePersons, $request->units, $request->rooms);

        $lines = [];
        $baseCents = 0;
        $unitPriceCents = $settings->unitPriceCentsFor($period?->id, $category?->id);

        if ($unitPriceCents === null) {
            $warnings[] = "Aucun tarif n'est encore défini pour cette période et cette catégorie.";
        } elseif ($quantity > 0) {
            $baseCents = $unitPriceCents * $quantity;
            $lines[] = new PriceLine(
                label: $this->baseLabel($unit, $nights, $effectivePersons, $request, $minimumPersonsApplied),
                quantity: $quantity,
                unitPriceCents: $unitPriceCents,
                amountCents: $baseCents,
                rule: PriceLine::RULE_BASE,
                meta: [
                    'period_id' => $period?->id,
                    'category_id' => $category?->id,
                    'minimum_persons_applied' => $minimumPersonsApplied ? 1 : 0,
                ]
            );
        }

        // A floor amount tops the base subtotal up, as its own visible line
        // rather than by rewriting the base line: the renter has to be able
        // to see both the rate they were quoted and the top-up that was
        // added, not a base line whose arithmetic does not check out.
        $minimumAmountApplied = false;
        if ($settings->minimumAmountCents !== null && $unitPriceCents !== null && $baseCents < $settings->minimumAmountCents) {
            $topUpCents = $settings->minimumAmountCents - $baseCents;
            $minimumAmountApplied = true;
            $lines[] = new PriceLine(
                label: sprintf('Complément jusqu\'au minimum de %s', self::formatCents($settings->minimumAmountCents)),
                quantity: 1,
                unitPriceCents: null,
                amountCents: $topUpCents,
                rule: PriceLine::RULE_BASE,
                meta: ['minimum_amount_applied' => 1]
            );
            $baseCents = $settings->minimumAmountCents;
        }

        // Fees are summed in configuration order. None of them ever
        // modifies, caps or cancels another.
        $feesCents = 0;
        foreach ($this->sortedFees($settings->fees) as $fee) {
            if (!$fee->isQuotable()) {
                // A meter fee is not "unknown", it is unknowable before the
                // stay. Listing it with a number would read as a commitment.
                $lines[] = new PriceLine(
                    label: $fee->label,
                    quantity: 0,
                    unitPriceCents: $fee->amountCents,
                    amountCents: 0,
                    rule: PriceLine::RULE_FEE_METER,
                    isInformational: true,
                    meta: ['fee_id' => $fee->id, 'meter_unit' => $fee->meterUnit]
                );
                continue;
            }

            // Deliberately the REAL head count, never the minimum-raised one
            // — see RentalFee::amountForCents().
            $amount = $fee->amountForCents($request->persons);
            $feesCents += $amount;

            $lines[] = new PriceLine(
                label: $fee->label,
                quantity: $fee->nature === RentalFee::NATURE_PER_PERSON ? max(0, $request->persons) : 1,
                unitPriceCents: $fee->nature === RentalFee::NATURE_PER_PERSON ? $fee->amountCents : null,
                amountCents: $amount,
                rule: $fee->nature === RentalFee::NATURE_PER_PERSON
                    ? PriceLine::RULE_FEE_PER_PERSON
                    : PriceLine::RULE_FEE_FIXED,
                meta: ['fee_id' => $fee->id]
            );
        }

        if ($nights <= 0) {
            $warnings[] = 'Les dates ne couvrent aucune nuit.';
        }
        if ($unit->isPersonBased() && $request->persons <= 0) {
            $warnings[] = 'Le nombre de participants n\'est pas encore indiqué.';
        }

        return new PriceQuote(
            lines: $lines,
            totalCents: $baseCents + $feesCents,
            nights: $nights,
            persons: $effectivePersons,
            quantity: $quantity,
            billingUnit: $unit,
            period: $period,
            category: $category,
            minimumApplied: $minimumPersonsApplied || $minimumAmountApplied,
            warnings: $warnings
        );
    }

    /**
     * The line label, which must **state the minimum out loud** when one
     * applied — "25 pers. (minimum) × 3 nuits", never a total quietly
     * corrected upward (§6.10). A renter who was told "18 people" and billed
     * for 25 deserves to read why on the quote itself.
     */
    private function baseLabel(
        BillingUnit $unit,
        int $nights,
        int $persons,
        PricingRequest $request,
        bool $minimumApplied
    ): string {
        $timeUnits = $unit->timeUnitsFor($nights);
        $timeNoun = $unit->timeUnitNoun($timeUnits);

        if ($unit->isPersonBased()) {
            $personPart = $minimumApplied
                ? sprintf('%d pers. (minimum)', $persons)
                : sprintf('%d pers.', $persons);

            return sprintf('%s × %d %s', $personPart, $timeUnits, $timeNoun);
        }

        if ($unit === BillingUnit::PER_ROOM_DAY) {
            return sprintf('%d pièce%s × %d %s', $request->rooms, $request->rooms > 1 ? 's' : '', $timeUnits, $timeNoun);
        }

        if ($unit === BillingUnit::FLAT_STAY) {
            return $request->units > 1
                ? sprintf('Forfait par séjour × %d', $request->units)
                : 'Forfait par séjour';
        }

        return $request->units > 1
            ? sprintf('%d × %d %s', $request->units, $timeUnits, $timeNoun)
            : sprintf('%d %s', $timeUnits, $timeNoun);
    }

    /**
     * @param string[] $warnings
     */
    private function resolveCategory(PricingSettings $settings, PricingRequest $request, array &$warnings): ?RenterCategory
    {
        if ($settings->categories === []) {
            return null;
        }

        foreach ($settings->categories as $candidate) {
            if ($candidate->id === $request->renterCategoryId) {
                return $candidate;
            }
        }

        if ($request->renterCategoryId !== null) {
            // A category id that matches nothing is a stale form or a forged
            // one; pricing it from the default rate silently would hand the
            // visitor whichever rate happened to be cheapest.
            $warnings[] = 'La catégorie de locataire choisie n\'existe plus.';

            return null;
        }

        $warnings[] = 'La catégorie de locataire n\'est pas encore indiquée.';

        return null;
    }

    /**
     * The period the stay is charged under: the one covering the **arrival
     * date**.
     *
     * A stay spanning two seasons is charged at the arrival season's rate,
     * and says so in a warning. The alternative — splitting the stay into a
     * line per season — was deliberately not implemented: it would make the
     * result's single `period`, `quantity` and unit price meaningless, and
     * §6.10's own worked example ("25 pers. (minimum) × 3 nuits") is a single
     * line for the whole stay. A manager who disagrees with the arrival-season
     * rate for a straddling stay edits the line by hand (§6.12), which is
     * exactly the escape hatch the spec provides and keeps the automatic path
     * predictable.
     *
     * @param string[] $warnings
     */
    private function resolvePeriod(PricingSettings $settings, PricingRequest $request, array &$warnings): ?PricePeriod
    {
        if ($settings->periods === []) {
            return null;
        }

        $arrival = $request->arrival();
        $overlapping = $settings->allPeriodsForDate($arrival);

        if (count($overlapping) > 1) {
            // Two seasons covering the same day is a configuration mistake,
            // not something to resolve by precedence — this engine has none.
            $warnings[] = sprintf(
                'Plusieurs périodes tarifaires se chevauchent au %s : la première configurée est appliquée.',
                $arrival->format('d/m/Y')
            );
        }

        $period = $overlapping[0] ?? null;

        if ($period === null) {
            $warnings[] = sprintf(
                'Aucune période tarifaire ne couvre le %s.',
                $arrival->format('d/m/Y')
            );

            return null;
        }

        // Warn as soon as any night of the stay falls outside the arrival
        // period — the manager needs to know the rate was picked from one end
        // of a stay that straddles two seasons.
        $nights = $request->nights();
        for ($offset = 1; $offset <= $nights; $offset++) {
            $day = $arrival->modify("+{$offset} days");
            if ($offset === $nights && $settings->billingUnit->isNightBased()) {
                break; // The departure day is not occupied in a nights model.
            }
            if (!$period->covers($day)) {
                $warnings[] = sprintf(
                    'Le séjour dépasse la période « %s » : le tarif de la date d\'arrivée est appliqué à tout le séjour.',
                    $period->label
                );
                break;
            }
        }

        return $period;
    }

    /**
     * @param RentalFee[] $fees
     * @return RentalFee[]
     */
    private function sortedFees(array $fees): array
    {
        $sorted = $fees;
        usort($sorted, fn(RentalFee $a, RentalFee $b) => $a->sortOrder <=> $b->sortOrder ?: $a->id <=> $b->id);

        return $sorted;
    }

    /**
     * Cents as a French amount ("187,50 €"). Presentation, but it belongs
     * with the engine because it also appears inside generated line labels,
     * which are part of the snapshot.
     */
    public static function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
