<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Member\HouseholdFeeCategory;
use Core\Service\TextNormalizerService;

/**
 * Recognises which of the three household tariffs a Desk fee category is,
 * from its own wording.
 *
 * `fee_categories` rows are created verbatim from the CSV's "Tarif" column
 * (`Core\Import\MappingResolver`), so their spelling is the federation's and
 * differs between units and between exports: "Tarif normal",
 * "N_N_COTISATION NORMALE", "Cotisation famille". A heuristic on the folded
 * text recognises the usual ones, which is what keeps a unit from having to
 * configure anything before the screen works — and what it does NOT
 * recognise it says so about, rather than guessing: `null` means "not one of
 * the three household tariffs", which is the right answer for "Tarif
 * animateur", "Tarif réduit" or an iAM membership.
 *
 * A unit whose wording this misses overrides it explicitly, one row per
 * category, on the screen's own barème panel (`fees_household_tariffs`) —
 * the same heuristic-plus-hand-mapping shape as
 * `Modules\Leadership\Service\FormationLevelResolver`.
 *
 * Folding goes through `Core\Service\TextNormalizerService::fold()`, this
 * codebase's one case- and accent-insensitive comparison form (§8.0): a
 * second one written here would disagree with it on some host eventually.
 */
final class FeeCategoryClassifier
{
    /**
     * Order matters: "famille" is checked before "normale" so a hypothetical
     * "cotisation famille normale" reads as a family tariff rather than a
     * normal one. Every needle is already folded — lowercase, unaccented.
     *
     * @var array<string, string[]>
     */
    private const NEEDLES = [
        'family' => ['famille', 'familiale', 'familial'],
        'couple' => ['couple'],
        'normal' => ['normale', 'normal', 'individuelle', 'individuel'],
    ];

    public static function classify(string $deskCode, string $label): ?HouseholdFeeCategory
    {
        $haystack = TextNormalizerService::fold($deskCode . ' ' . $label);

        foreach (self::NEEDLES as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return HouseholdFeeCategory::from($category);
                }
            }
        }

        return null;
    }
}
