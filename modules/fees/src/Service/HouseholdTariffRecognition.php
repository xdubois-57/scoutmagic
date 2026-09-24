<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Modules\Fees\Api\HouseholdTariffRecognitionInterface;

/**
 * The module's published recognition capability
 * ({@see HouseholdTariffRecognitionInterface}), which is this module's two
 * existing answers put behind one narrow door rather than a third opinion
 * about what a tariff is.
 *
 * `recognisesWording()` is {@see FeeCategoryClassifier} exactly, and
 * `unmappedFeeCategoryIds()` is {@see HouseholdTariffService}'s own
 * mapping — override included — read in the negative. Neither re-derives
 * anything, on purpose: a second heuristic living here would disagree with
 * the screen's one the first time somebody edited only one of them, and
 * the disagreement would show up as a gap reported for a tariff the unit
 * can see mapped.
 */
class HouseholdTariffRecognition implements HouseholdTariffRecognitionInterface
{
    public function __construct(
        private HouseholdTariffService $tariffs,
        private FeeCategoryRepository $feeCategories
    ) {
    }

    public function recognisesWording(string $deskCode, string $label): bool
    {
        return FeeCategoryClassifier::classify($deskCode, $label) !== null;
    }

    /**
     * @return list<int>
     */
    public function unmappedFeeCategoryIds(): array
    {
        $unmapped = [];

        foreach ($this->feeCategories->findAll() as $feeCategory) {
            if ($this->tariffs->categoryForFeeCategoryId($feeCategory['id']) === null) {
                $unmapped[] = $feeCategory['id'];
            }
        }

        sort($unmapped);

        return $unmapped;
    }
}
