<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PricePeriod;
use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Pricing\RenterCategory;

/**
 * Loads and stores one asset's pricing configuration — the four blocks of
 * module spec §6.10.
 *
 * `load()` assembles a `Pricing\PricingSettings`, the immutable value object
 * `Pricing\RentalPricingEngine` consumes. That indirection is the point: the
 * engine never touches a database, so the public page, the configuration
 * simulator, the agreed price and the contract are provably the same code
 * path rather than four that are meant to agree.
 *
 * No personal data lives in any of these tables — a season, a renter
 * category and a fee are all configuration written by a chief.
 */
class RentalPricingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The complete pricing configuration for one asset, in four queries.
     *
     * Four rather than one join: the grid is sparse and the three lists are
     * independent, so a single joined query would multiply rows and then
     * need de-duplicating in PHP for no gain at these sizes (a handful of
     * seasons, a handful of categories, a handful of fees).
     */
    public function load(int $assetId): PricingSettings
    {
        $stmt = $this->pdo->prepare(
            'SELECT billing_unit, default_unit_price_cents, minimum_amount_cents, minimum_persons
             FROM rental_assets WHERE id = ?'
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return new PricingSettings(BillingUnit::FLAT_STAY);
        }

        return new PricingSettings(
            billingUnit: BillingUnit::tryFrom((string) $row['billing_unit']) ?? BillingUnit::FLAT_STAY,
            periods: $this->findPeriods($assetId),
            categories: $this->findCategories($assetId),
            priceGridCents: $this->findGrid($assetId),
            defaultUnitPriceCents: $row['default_unit_price_cents'] !== null
                ? (int) $row['default_unit_price_cents']
                : null,
            minimumAmountCents: $row['minimum_amount_cents'] !== null ? (int) $row['minimum_amount_cents'] : null,
            minimumPersons: $row['minimum_persons'] !== null ? (int) $row['minimum_persons'] : null,
            fees: $this->findFees($assetId)
        );
    }

    /**
     * Writes the asset-level pricing block. Its own section, saved on its
     * own (§6.4) — it never touches the periods, categories, grid or fees,
     * each of which has its own form.
     */
    public function saveAssetPricing(
        int $assetId,
        BillingUnit $billingUnit,
        ?int $defaultUnitPriceCents,
        ?int $minimumAmountCents,
        ?int $minimumPersons
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_assets
             SET billing_unit = ?, default_unit_price_cents = ?,
                 minimum_amount_cents = ?, minimum_persons = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $billingUnit->value,
            $defaultUnitPriceCents,
            $minimumAmountCents,
            $minimumPersons,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $assetId,
        ]);
    }

    // ── Periods ─────────────────────────────────────────────────────────

    /**
     * @return PricePeriod[]
     */
    public function findPeriods(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_price_periods WHERE asset_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(
            fn(array $row) => new PricePeriod(
                id: (int) $row['id'],
                label: (string) $row['label'],
                startDate: (string) $row['start_date'],
                endDate: (string) $row['end_date'],
                recursYearly: (bool) $row['recurs_yearly']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function createPeriod(
        int $assetId,
        string $label,
        string $startDate,
        string $endDate,
        bool $recursYearly,
        int $sortOrder = 0
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_price_periods (asset_id, label, start_date, end_date, recurs_yearly, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $label,
            $startDate,
            $endDate,
            $recursYearly ? 1 : 0,
            $sortOrder,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Deletes a period. Its grid cells go with it via `ON DELETE CASCADE` —
     * a price for a season that no longer exists is not a price, and leaving
     * the row would resurrect it if the ids were ever reused.
     */
    public function deletePeriod(int $assetId, int $periodId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_price_periods WHERE id = ? AND asset_id = ?');
        $stmt->execute([$periodId, $assetId]);
    }

    // ── Renter categories ───────────────────────────────────────────────

    /**
     * @return RenterCategory[]
     */
    public function findCategories(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_renter_categories WHERE asset_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(
            fn(array $row) => new RenterCategory(
                id: (int) $row['id'],
                label: (string) $row['label'],
                isDefault: (bool) $row['is_default']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function createCategory(int $assetId, string $label, bool $isDefault = false, int $sortOrder = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_renter_categories (asset_id, label, is_default, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $label,
            $isDefault ? 1 : 0,
            $sortOrder,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteCategory(int $assetId, int $categoryId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_renter_categories WHERE id = ? AND asset_id = ?');
        $stmt->execute([$categoryId, $assetId]);
    }

    // ── The grid ────────────────────────────────────────────────────────

    /**
     * @return array<string, int> Keyed by PricingSettings::gridKey().
     */
    public function findGrid(int $assetId): array
    {
        $stmt = $this->pdo->prepare('SELECT period_id, category_id, unit_price_cents FROM rental_price_grid WHERE '
            . 'asset_id = ?');
        $stmt->execute([$assetId]);

        $grid = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = PricingSettings::gridKey(
                $row['period_id'] !== null ? (int) $row['period_id'] : null,
                $row['category_id'] !== null ? (int) $row['category_id'] : null
            );
            $grid[$key] = (int) $row['unit_price_cents'];
        }

        return $grid;
    }

    /**
     * Replaces the whole grid for an asset in one transaction.
     *
     * Delete-then-insert rather than a per-cell upsert: the configuration
     * screen posts the entire grid, and a cell the operator cleared has to
     * disappear rather than keep its old price. Doing it row by row would
     * leave a half-updated grid if anything failed midway — which, for a
     * price list, is worse than not saving at all.
     *
     * @param array<string, int> $cells Keyed by PricingSettings::gridKey().
     */
    public function replaceGrid(int $assetId, array $cells): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $delete = $this->pdo->prepare('DELETE FROM rental_price_grid WHERE asset_id = ?');
            $delete->execute([$assetId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO rental_price_grid (asset_id, period_id, category_id, unit_price_cents, updated_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($cells as $key => $priceCents) {
                [$periodId, $categoryId] = array_map('intval', explode(':', (string) $key));
                $insert->execute([
                    $assetId,
                    $periodId !== 0 ? $periodId : null,
                    $categoryId !== 0 ? $categoryId : null,
                    $priceCents,
                    $now,
                ]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            // Only rolls back the transaction it opened itself — a caller
            // that already had one running owns it, and rolling theirs back
            // from here would discard work this method never saw.
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ── Fees ────────────────────────────────────────────────────────────

    /**
     * @return RentalFee[]
     */
    public function findFees(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_fees WHERE asset_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(
            fn(array $row) => new RentalFee(
                id: (int) $row['id'],
                label: (string) $row['label'],
                nature: (string) $row['nature'],
                amountCents: (int) $row['amount_cents'],
                meterUnit: $row['meter_unit'] !== null ? (string) $row['meter_unit'] : null,
                sortOrder: (int) $row['sort_order']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function createFee(
        int $assetId,
        string $label,
        string $nature,
        int $amountCents,
        ?string $meterUnit = null,
        int $sortOrder = 0
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_fees (asset_id, label, nature, amount_cents, meter_unit, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $label,
            $nature,
            $amountCents,
            $meterUnit,
            $sortOrder,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteFee(int $assetId, int $feeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_fees WHERE id = ? AND asset_id = ?');
        $stmt->execute([$feeId, $assetId]);
    }
}
