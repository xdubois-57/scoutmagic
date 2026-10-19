<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * One animé who changes branch in the target year and has no destination
 * section chosen yet on the Passage page —
 * Api\ScoutYearRegistrationReviewProvider::passagesWithoutDestination().
 *
 * The same people the Passage page lists without a destination, and the
 * same people Api\ScoutYearPreparationProvider::countUnassignedPassages()
 * counts when the target year is the one Passage targets.
 */
final class PassageWithoutDestination
{
    public function __construct(
        public readonly int $memberId,
        /** The name, decrypted, as the year they leave their branch in knows it. */
        public readonly string $name,
        /** Their section in that year, null when they have none. */
        public readonly ?int $currentSectionId,
        /** « Louveteau 3e année » and the like, the Passage page's own label. */
        public readonly string $branchYearLabel,
        /** How many sections of the arrival branch they could be sent to. */
        public readonly int $destinationOptionCount
    ) {
    }
}
