<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\HouseholdFeeCategory;
use Modules\Fees\FeeTariff;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Value\HouseholdTariff;

/**
 * Five applicable tariffs, their Desk mapping and their per-person amounts.
 *
 * Explicit mappings win over the shared wording classifier. The core still
 * models only three household sizes: categoryForFeeCategoryId() exposes
 * that projection, while tariffForFeeCategoryId() preserves IAmA and guests.
 * Recognising a guest wording is not a decision about guest eligibility.
 */
class HouseholdTariffService
{
    /** @var array<int, FeeTariff>|null fee_categories.id => household category */
    private ?array $tariffByFeeCategoryId = null;

    /** @var array<string, HouseholdTariff>|null */
    private ?array $tariffs = null;

    /** @var array<string, list<string>>|null household category value => Desk codes */
    private ?array $deskCodesByCategory = null;

    public function __construct(
        private HouseholdTariffRepository $repository,
        private FeeCategoryRepository $feeCategories
    ) {
    }

    /**
     * Which household tariff a Desk fee category is, or null when it is
     * none of the three.
     */
    public function categoryForFeeCategoryId(?int $feeCategoryId): ?HouseholdFeeCategory
    {
        $tariff = $this->tariffForFeeCategoryId($feeCategoryId);

        return $tariff === null ? null : HouseholdFeeCategory::tryFrom($tariff->value);
    }

    public function tariffForFeeCategoryId(?int $feeCategoryId): ?FeeTariff
    {
        if ($feeCategoryId === null) {
            return null;
        }

        return $this->mapping()[$feeCategoryId] ?? null;
    }

    /**
     * The Desk codes (`fee_categories.desk_code`) a household tariff really
     * stands for — read through the SAME mapping the calculation uses
     * (`explicit override ?? heuristic`), so what the screen calls « attendu »
     * is what the comparison compared against, never a second opinion.
     *
     * Empty when no Desk category is mapped to it: the caller says so rather
     * than showing a code nobody has (#1326). More than one when the
     * heuristic recognises several codes as the same tariff and nobody chose.
     *
     * @return list<string>
     */
    public function deskCodesFor(FeeTariff|HouseholdFeeCategory $category): array
    {
        if ($this->deskCodesByCategory === null) {
            $codeById = [];
            foreach ($this->feeCategories->findAll() as $feeCategory) {
                $codeById[$feeCategory['id']] = $feeCategory['desk_code'];
            }

            $byCategory = [];
            foreach ($this->mapping() as $feeCategoryId => $mapped) {
                if (isset($codeById[$feeCategoryId])) {
                    $byCategory[$mapped->value][] = $codeById[$feeCategoryId];
                }
            }
            foreach ($byCategory as &$codes) {
                sort($codes);
            }
            unset($codes);

            $this->deskCodesByCategory = $byCategory;
        }

        return $this->deskCodesByCategory[$category->value] ?? [];
    }

    /** What one person on this tariff costs, in cents, or null when nobody entered it. */
    public function amountCentsFor(FeeTariff|HouseholdFeeCategory $category): ?int
    {
        return $this->all()[$category->value]?->amountCents;
    }

    /**
     * The signed difference, in cents, between what a member SHOULD be on
     * and what they are on — positive when the unit is under-declaring
     * (the amount that will come back in the regularisation invoice),
     * negative when it is over-declaring.
     *
     * Null as soon as either side has no amount: a discrepancy shown
     * without a figure is honest, a discrepancy shown as 0,00 € is not.
     */
    public function differenceCents(
        FeeTariff|HouseholdFeeCategory $expected,
        FeeTariff|HouseholdFeeCategory|null $encoded
    ): ?int {
        if ($encoded === null) {
            return null;
        }

        $expectedAmount = $this->amountCentsFor($expected);
        $encodedAmount = $this->amountCentsFor($encoded);
        if ($expectedAmount === null || $encodedAmount === null) {
            return null;
        }

        return $expectedAmount - $encodedAmount;
    }

    /**
     * The barème as the panel renders it: one entry per household category,
     * always all five, carrying the stored override and amount plus the
     * fee category the heuristic would pick when nothing was overridden.
     *
     * @return array<
     *     string,
     *     array{
     *         category: FeeTariff,
     *         fee_category_id: ?int,
     *         amount_cents: ?int,
     *         suggested_fee_category_id: ?int
     *     }
     * >
     */
    public function panel(): array
    {
        $stored = $this->all();
        $suggested = $this->heuristicMapping();

        $panel = [];
        foreach (FeeTariff::cases() as $category) {
            $suggestedId = array_search($category, $suggested, true);
            $panel[$category->value] = [
                'category' => $category,
                'fee_category_id' => $stored[$category->value]?->feeCategoryId,
                'amount_cents' => $stored[$category->value]?->amountCents,
                'suggested_fee_category_id' => $suggestedId === false ? null : $suggestedId,
            ];
        }

        return $panel;
    }

    public function save(
        FeeTariff|HouseholdFeeCategory $category,
        ?int $feeCategoryId,
        ?int $amountCents
    ): void {
        $this->repository->save($category, $feeCategoryId, $amountCents);
        $this->tariffs = null;
        $this->tariffByFeeCategoryId = null;
        $this->deskCodesByCategory = null;
    }

    /** True once at least one of the five tariffs carries an amount. */
    public function hasAnyAmount(): bool
    {
        foreach ($this->all() as $tariff) {
            if ($tariff?->amountCents !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * True once the barème has been saved at all, even with every amount
     * left empty — the panel always saves its five lines together, and an
     * empty one is a choice (« Laissé vide, un écart s'affiche sans
     * montant »), not a blank to fill.
     */
    public function wasEverSaved(): bool
    {
        if ($this->tariffs === null) {
            $this->tariffs = $this->repository->findAll();
        }

        return $this->tariffs !== [];
    }

    /** A saved NULL amount is still an explicit choice, unlike a missing row. */
    public function wasSaved(FeeTariff $category): bool
    {
        return $this->all()[$category->value] !== null;
    }

    /** @param callable(): void $writes */
    public function transactionally(callable $writes): void
    {
        try {
            $this->repository->transactionally($writes);
        } finally {
            $this->tariffs = null;
            $this->tariffByFeeCategoryId = null;
            $this->deskCodesByCategory = null;
        }
    }

    /** @return array<string, ?HouseholdTariff> always the five keys */
    private function all(): array
    {
        if ($this->tariffs === null) {
            $this->tariffs = $this->repository->findAll();
        }

        $complete = [];
        foreach (FeeTariff::cases() as $category) {
            $complete[$category->value] = $this->tariffs[$category->value] ?? null;
        }

        return $complete;
    }

    /** @return array<int, FeeTariff> */
    private function mapping(): array
    {
        if ($this->tariffByFeeCategoryId !== null) {
            return $this->tariffByFeeCategoryId;
        }

        $mapping = $this->heuristicMapping();

        // An explicit override wins, and also REMOVES whatever the
        // heuristic had claimed for that category: a unit saying "couple is
        // this code" is also saying it is not the one guessed for it.
        foreach ($this->all() as $tariff) {
            if ($tariff?->feeCategoryId === null) {
                continue;
            }
            foreach ($mapping as $feeCategoryId => $category) {
                if ($category === $tariff->category) {
                    unset($mapping[$feeCategoryId]);
                }
            }
            $mapping[$tariff->feeCategoryId] = $tariff->category;
        }

        return $this->tariffByFeeCategoryId = $mapping;
    }

    /** @return array<int, FeeTariff> */
    private function heuristicMapping(): array
    {
        $mapping = [];
        foreach ($this->feeCategories->findAll() as $feeCategory) {
            $category = FeeCategoryClassifier::classifyTariff($feeCategory['desk_code'], $feeCategory['label']);
            if ($category !== null) {
                $mapping[$feeCategory['id']] = $category;
            }
        }

        return $mapping;
    }
}
