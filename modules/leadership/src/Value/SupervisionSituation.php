<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Value;

/**
 * What one section's staffing looks like against the ONE ratio, as
 * Service\SupervisionCalculator computed it.
 *
 * Facts only. `satisfied` says whether the ratio is met, not whether the
 * section is in order — the module does not know about exemptions,
 * derogations or an animateur borrowed from next door, so the template
 * renders this as a sentence rather than as a verdict, and there is
 * deliberately no colour, score or percentage anywhere on it.
 */
final class SupervisionSituation
{
    public function __construct(
        public readonly int $headcount,
        public readonly int $animatorCount,
        public readonly int $brevetCount,
        /** Animateurs of this section whose Desk level the site could not resolve. */
        public readonly int $unknownLevelCount,
        public readonly int $requiredAnimators,
        public readonly int $requiredBrevets,
        public readonly bool $satisfied,
        /**
         * True only when the ratio is unmet AND mapping the unrecognised
         * levels could make it met. Never true on the met side: an
         * unrecognised level can only raise the brevet count, so a met
         * threshold is met whatever those values turn out to be.
         */
        public readonly bool $mayBeIncomplete,
    ) {
    }
}
