<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Value\HouseholdTariff;

/**
 * The barème, resolved: which Desk fee category means each household
 * tariff, and what one person on it costs.
 *
 * The mapping is `explicit override ?? heuristic`, in that order, and the
 * heuristic is what makes the screen work on the day the module is switched
 * on. A category the classifier does not recognise and nobody mapped is not
 * a household tariff at all — "Tarif animateur", "Tarif réduit", an iAM
 * membership — and a member carrying one is simply outside the comparison
 * rather than reported as wrong.
 */
class HouseholdTariffService
{
    /** @var array<int, HouseholdFeeCategory>|null fee_categories.id => household category */
    private ?array $categoryByFeeCategoryId = null;

    /** @var array<string, HouseholdTariff>|null */
    private ?array $tariffs = null;

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
        if ($feeCategoryId === null) {
            return null;
        }

        return $this->mapping()[$feeCategoryId] ?? null;
    }

    /** What one person on this tariff costs, in cents, or null when nobody entered it. */
    public function amountCentsFor(HouseholdFeeCategory $category): ?int
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
    public function differenceCents(HouseholdFeeCategory $expected, ?HouseholdFeeCategory $encoded): ?int
    {
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
     * always all three, carrying the stored override and amount plus the
     * fee category the heuristic would pick when nothing was overridden.
     *
     * @return array<string, array{category: HouseholdFeeCategory, fee_category_id: ?int, amount_cents: ?int, suggested_fee_category_id: ?int}>
     */
    public function panel(): array
    {
        $stored = $this->all();
        $suggested = $this->heuristicMapping();

        $panel = [];
        foreach (HouseholdFeeCategory::cases() as $category) {
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

    public function save(HouseholdFeeCategory $category, ?int $feeCategoryId, ?int $amountCents): void
    {
        $this->repository->save($category, $feeCategoryId, $amountCents);
        $this->tariffs = null;
        $this->categoryByFeeCategoryId = null;
    }

    /** True once at least one of the three tariffs carries an amount. */
    public function hasAnyAmount(): bool
    {
        foreach ($this->all() as $tariff) {
            if ($tariff?->amountCents !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, ?HouseholdTariff> always the three keys */
    private function all(): array
    {
        if ($this->tariffs === null) {
            $this->tariffs = $this->repository->findAll();
        }

        $complete = [];
        foreach (HouseholdFeeCategory::cases() as $category) {
            $complete[$category->value] = $this->tariffs[$category->value] ?? null;
        }

        return $complete;
    }

    /** @return array<int, HouseholdFeeCategory> */
    private function mapping(): array
    {
        if ($this->categoryByFeeCategoryId !== null) {
            return $this->categoryByFeeCategoryId;
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

        return $this->categoryByFeeCategoryId = $mapping;
    }

    /** @return array<int, HouseholdFeeCategory> */
    private function heuristicMapping(): array
    {
        $mapping = [];
        foreach ($this->feeCategories->findAll() as $feeCategory) {
            $category = FeeCategoryClassifier::classify($feeCategory['desk_code'], $feeCategory['label']);
            if ($category !== null) {
                $mapping[$feeCategory['id']] = $category;
            }
        }

        return $mapping;
    }
}
