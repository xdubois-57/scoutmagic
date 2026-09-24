<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Api;

/**
 * What this module publishes to the rest of the application
 * (ARCHITECTURE.md §7.5): whether a Desk fee category means one of the
 * three household tariffs, and nothing else. No amount crosses this
 * interface — what a cotisation costs is the unit's business and stays on
 * the unit's screens.
 *
 * It exists because the answer is genuinely this module's to give and
 * core's to need. A Desk "Tarif" nothing recognises is invisible: the
 * import creates the category like any other, the member carrying it is
 * simply left out of the household comparison, and no screen ever says so.
 * `Core\Import\DeskMappingGapService` is what says so — and it cannot ask
 * the question itself, because naming `Modules\Fees` from core is what the
 * `Api\` contract forbids, and `Core\Statistics\StatisticsPayloadBuilder`
 * already carries that refusal in writing.
 *
 * Consumed as a NULLABLE dependency, like
 * `Modules\UsageStats\Api\ModuleUsageInterface`. Absent means the
 * cotisations module is off, and the honest consequence is no fee gap at
 * all rather than every category reported as unrecognised: without this
 * module there is no barème for a tariff to be missing from.
 */
interface HouseholdTariffRecognitionInterface
{
    /**
     * Whether the wording alone — the heuristic, before any human could
     * have had a say — reads as one of the three household tariffs.
     *
     * This is the question to ask about a value the site is meeting for
     * the FIRST time, during an import: the row has just been created, so
     * no explicit mapping can exist for it yet, and asking the settled
     * question below would answer "unrecognised" for every brand new
     * category including the three ordinary ones.
     */
    public function recognisesWording(string $deskCode, string $label): bool;

    /**
     * The settled answer for the categories already stored: those that
     * neither the heuristic nor an explicit mapping claims.
     *
     * Not the complement of the method above. A unit that maps "couple is
     * this code" by hand also takes that claim away from whatever the
     * heuristic had guessed for couple, so a category can read as
     * recognised on its wording and still end up claimed by nobody.
     *
     * @return list<int> `fee_categories.id`, ascending
     */
    public function unmappedFeeCategoryIds(): array;
}
