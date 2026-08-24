<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Support;

/**
 * Everything around the pricing engine that needs a database: loading an
 * asset's configuration, validating and storing changes to it, and pricing a
 * request for that asset.
 *
 * The engine itself stays pure and lives in `Pricing\RentalPricingEngine`.
 * `quoteForAsset()` is the single entry point every caller uses — the public
 * page, the configuration simulator, the agreed price and the contract — so
 * the simulator is provably the same code as the price a visitor is shown.
 * A simulator that ran its own arithmetic would be a guard-rail against
 * nothing.
 *
 * Never reads `$_POST`/`$_SESSION` (ARCHITECTURE.md §13).
 */
class RentalPricingService
{
    public function __construct(
        private RentalPricingRepository $pricingRepository,
        private RentalPricingEngine $engine,
        private JournalService $journal
    ) {
    }

    public function loadSettings(int $assetId): PricingSettings
    {
        return $this->pricingRepository->load($assetId);
    }

    /**
     * Price a request against an asset's live configuration.
     */
    public function quoteForAsset(int $assetId, PricingRequest $request): PriceQuote
    {
        return $this->engine->quote($this->pricingRepository->load($assetId), $request);
    }

    /**
     * Price a request against settings already in hand — for a caller that
     * prices several requests in a row (the simulator, an availability
     * calendar showing a price per candidate range) and must not reload the
     * same configuration once per call.
     */
    public function quoteWithSettings(PricingSettings $settings, PricingRequest $request): PriceQuote
    {
        return $this->engine->quote($settings, $request);
    }

    /**
     * @throws RentalException with a user-facing French message.
     */
    public function saveAssetPricing(
        int $assetId,
        string $billingUnit,
        ?int $defaultUnitPriceCents,
        ?int $minimumAmountCents,
        ?int $minimumPersons,
        ?int $userId = null
    ): void {
        $unit = BillingUnit::tryFrom($billingUnit);
        if ($unit === null) {
            throw new RentalException("Cette unité de facturation n'existe pas.");
        }

        // "Un montant plancher OU un nombre de personnes plancher" (§6.10).
        // Storing both would need a precedence rule between them, and this
        // engine deliberately has none — refusing here is what keeps that
        // true.
        if ($minimumAmountCents !== null && $minimumPersons !== null) {
            throw new RentalException(
                'Le minimum facturable est soit un montant, soit un nombre de personnes — pas les deux.'
            );
        }

        if ($minimumPersons !== null && !$unit->isPersonBased()) {
            throw new RentalException(
                'Un minimum en nombre de personnes ne s\'applique qu\'à un tarif par personne.'
            );
        }

        foreach ([$defaultUnitPriceCents, $minimumAmountCents] as $amount) {
            if ($amount !== null && $amount < 0) {
                throw new RentalException('Un montant ne peut pas être négatif.');
            }
        }

        if ($minimumPersons !== null && $minimumPersons < 1) {
            throw new RentalException('Le minimum en personnes doit valoir au moins 1.');
        }

        $this->pricingRepository->saveAssetPricing(
            $assetId,
            $unit,
            $defaultUnitPriceCents,
            $minimumAmountCents,
            $minimumPersons
        );

        // Ids and the billing unit only: an asset's tariff is not personal
        // data, but there is no reason to copy amounts into the journal
        // either, and the fiche itself is the record of what they are.
        $this->journal->log(
            'rental',
            'rental_pricing_updated',
            'info',
            'Tarification modifiée pour un bien de location',
            ['asset_id' => $assetId, 'billing_unit' => $unit->value],
            $userId
        );
    }

    /**
     * @throws RentalException
     */
    public function addPeriod(int $assetId, string $label, string $startDate, string $endDate, bool $recursYearly): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException('Le nom de la période est obligatoire.');
        }

        if (!Support::isDate($startDate) || !Support::isDate($endDate)) {
            throw new RentalException('Les dates de la période doivent être valides.');
        }

        // A non-recurring period must not end before it starts. A RECURRING
        // one may: "20 December → 5 January" is a range that wraps the new
        // year, and rejecting it would make the Christmas holidays — the
        // busiest weeks a scout hall has — impossible to price.
        if (!$recursYearly && $endDate < $startDate) {
            throw new RentalException('La date de fin doit suivre la date de début.');
        }

        return $this->pricingRepository->createPeriod(
            $assetId,
            $label,
            $startDate,
            $endDate,
            $recursYearly,
            count($this->pricingRepository->findPeriods($assetId))
        );
    }

    public function deletePeriod(int $assetId, int $periodId): void
    {
        $this->pricingRepository->deletePeriod($assetId, $periodId);
    }

    /**
     * @throws RentalException
     */
    public function addCategory(int $assetId, string $label, bool $isDefault): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException('Le nom de la catégorie est obligatoire.');
        }

        return $this->pricingRepository->createCategory(
            $assetId,
            $label,
            $isDefault,
            count($this->pricingRepository->findCategories($assetId))
        );
    }

    public function deleteCategory(int $assetId, int $categoryId): void
    {
        $this->pricingRepository->deleteCategory($assetId, $categoryId);
    }

    /**
     * @param array<string, int> $cells Keyed by PricingSettings::gridKey().
     * @throws RentalException
     */
    public function saveGrid(int $assetId, array $cells): void
    {
        foreach ($cells as $priceCents) {
            if ($priceCents < 0) {
                throw new RentalException('Un tarif ne peut pas être négatif.');
            }
        }

        // Only cells whose period and category still exist are kept: a stale
        // form posting a deleted season's id would otherwise reinsert a row
        // the foreign key then rejects, failing the whole save.
        $validPeriodIds = array_map(fn($p) => $p->id, $this->pricingRepository->findPeriods($assetId));
        $validCategoryIds = array_map(fn($c) => $c->id, $this->pricingRepository->findCategories($assetId));

        $kept = [];
        foreach ($cells as $key => $priceCents) {
            [$periodId, $categoryId] = array_map('intval', explode(':', (string) $key));
            if ($periodId !== 0 && !in_array($periodId, $validPeriodIds, true)) {
                continue;
            }
            if ($categoryId !== 0 && !in_array($categoryId, $validCategoryIds, true)) {
                continue;
            }
            $kept[PricingSettings::gridKey($periodId ?: null, $categoryId ?: null)] = $priceCents;
        }

        $this->pricingRepository->replaceGrid($assetId, $kept);
    }

    /**
     * @throws RentalException
     */
    public function addFee(int $assetId, string $label, string $nature, int $amountCents, ?string $meterUnit): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException('Le libellé du frais est obligatoire.');
        }

        if (!in_array($nature, RentalFee::natures(), true)) {
            throw new RentalException("Cette nature de frais n'existe pas.");
        }

        if ($amountCents < 0) {
            throw new RentalException('Un montant ne peut pas être négatif.');
        }

        $meterUnit = $meterUnit !== null ? trim($meterUnit) : '';
        if ($nature === RentalFee::NATURE_METER && $meterUnit === '') {
            // Without a unit, a meter reading is a bare number: 400 what?
            throw new RentalException('Un frais au compteur doit préciser son unité (kWh, m³…).');
        }

        return $this->pricingRepository->createFee(
            $assetId,
            $label,
            $nature,
            $amountCents,
            $nature === RentalFee::NATURE_METER ? $meterUnit : null,
            count($this->pricingRepository->findFees($assetId))
        );
    }

    public function deleteFee(int $assetId, int $feeId): void
    {
        $this->pricingRepository->deleteFee($assetId, $feeId);
    }

    /**
     * Parses a French amount ("2,50", "2.50", "1 234,56") into cents.
     *
     * Deliberately not `(int) round((float) $value * 100)` from a raw string:
     * a comma decimal separator is what a Belgian operator actually types,
     * and `(float) "2,50"` is 2.0 — a tariff silently entered at a fifth of
     * its value, with nothing to notice it by.
     *
     * @return int|null Null for an empty input, meaning "not set".
     * @throws RentalException when the input is present but not a number.
     */
    public static function parseAmountToCents(?string $value): ?int
    {
        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        $normalized = str_replace([' ', "\u{202f}", "\u{a0}", ','], ['', '', '', '.'], $value);

        if (!is_numeric($normalized)) {
            throw new RentalException('Le montant « ' . $value . ' » n\'est pas un nombre valide.');
        }

        return (int) round(((float) $normalized) * 100);
    }
}
