<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Member\HouseholdFeeCategory;
use Core\Service\TextNormalizerService;
use Modules\Fees\FeeTariff;

/**
 * Recognises five federal tariffs from imported Desk codes and labels.
 * classify() preserves Core's three-category household contract;
 * classifyTariff() includes local IAmA and guests for the fees module.
 * Unknown wording and federal-only IAmA remain unknown rather than guessed.
 * Explicit per-row mappings in the scale override this heuristic.
 * Folding uses the shared TextNormalizerService representation.
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
        'guest' => ['invite'],
        'iama' => ['iama', 'iam local'],
        'family' => ['famille', 'familiale', 'familial'],
        'couple' => ['couple'],
        'normal' => ['normale', 'normal', 'individuelle', 'individuel'],
    ];

    public static function classify(string $deskCode, string $label): ?HouseholdFeeCategory
    {
        $tariff = self::classifyTariff($deskCode, $label);

        return $tariff === null ? null : HouseholdFeeCategory::tryFrom($tariff->value);
    }

    public static function classifyTariff(string $deskCode, string $label): ?FeeTariff
    {
        $haystack = TextNormalizerService::fold($deskCode . ' ' . $label);
        if (
            str_contains($haystack, 'iama') && str_contains($haystack, 'federal')
            && !str_contains($haystack, 'unite') && !str_contains($haystack, 'clan')
            && !str_contains($haystack, 'local')
        ) {
            return null;
        }
        $reference = match (TextNormalizerService::fold($deskCode)) {
            'cot norm' => FeeTariff::NORMAL,
            'cot couple' => FeeTariff::COUPLE,
            'cot fam' => FeeTariff::FAMILY,
            'cot iam local' => FeeTariff::IAMA,
            'cot invite' => FeeTariff::GUEST,
            default => null,
        };
        if ($reference !== null) {
            return $reference;
        }

        foreach (self::NEEDLES as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return FeeTariff::from($category);
                }
            }
        }

        return null;
    }
}
