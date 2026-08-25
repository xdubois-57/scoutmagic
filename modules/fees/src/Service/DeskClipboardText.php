<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Service\TextNormalizerService;
use Modules\Fees\HouseholdCategoryLabel;
use Modules\Fees\Value\HouseholdReview;
use Modules\Fees\Value\HouseholdReviewMember;

/**
 * The block « Copier pour Desk » puts in the clipboard.
 *
 * Deliberately stupid: plain text, one line per person, the address on top.
 * The site cannot write to Desk and will not pretend to, so the useful
 * thing is a treasurer having the whole household under their eyes in the
 * other browser tab rather than scrolling back and forth between two
 * screens.
 *
 * Built server-side rather than assembled in JavaScript for the ordinary
 * reason (design.md §7.5): the names are already decrypted here, and a
 * second assembly in the browser is a second place for the wording to
 * drift.
 */
final class DeskClipboardText
{
    public static function forHousehold(HouseholdReview $review): string
    {
        $lines = [
            $review->addressLabel,
            $review->deskSize . ' membre(s) dans Desk — tarif attendu : ' . HouseholdCategoryLabel::for($review->expectedCategory),
        ];

        foreach ($review->members as $member) {
            $lines[] = '- ' . self::memberLine($member, $review);
        }

        return implode("\n", $lines);
    }

    private static function memberLine(HouseholdReviewMember $member, HouseholdReview $review): string
    {
        $name = trim(
            TextNormalizerService::normalizeName($member->firstName)
            . ' ' . TextNormalizerService::normalizeName($member->lastName)
        );
        $encoded = $member->encodedFeeCategoryLabel ?? 'aucun tarif';

        if (!$member->comparable) {
            return $name . ' : ' . $encoded . ' (hors tarif de foyer, non comparé)';
        }

        if ($member->matches($review->expectedCategory)) {
            return $name . ' : ' . $encoded . ' (conforme)';
        }

        return $name . ' : ' . $encoded . ' → ' . HouseholdCategoryLabel::for($review->expectedCategory);
    }
}
