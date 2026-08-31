<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Modules\Leadership\FormationStep;
use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Value\SupervisionSituation;

/**
 * States what the ONE subsidy rules ask of a section's staff, and whether
 * that is the case today. It states; it does not judge.
 *
 * There is no score, no percentage, no colour and no verdict here on
 * purpose. The page this feeds says "the ONE asks for N animateurs of whom
 * M brevetés for this headcount; that is / is not the case today" and
 * stops, because the module knows the ratio and nothing else: it does not
 * know about a co-animateur from another section, an exemption, a
 * derogation, or what the unit has agreed with its own ONE contact. A green
 * tick here would be read as compliance, and this module is in no position
 * to certify compliance.
 *
 * **The ONE recognises the BACV, and nothing else here does the deciding.**
 * The count asks each step `countsForOneRatio()` rather than comparing
 * against a case: the Woodbadge is internal to scouting and carries no ONE
 * recognition, and a brevet whose kind nobody recorded (the legacy box)
 * *might* be a BACV — which a figure with regulatory weight may not read
 * as *is*. Both were counted here before they had boxes of their own, so
 * this number can go **down** on the day a unit updates, and the page says
 * why (Service\TrainingService::unspecifiedBrevetCount()); a ratio that
 * was wrong is worse than a ratio that fell.
 *
 * **The one asymmetry that makes the numbers trustworthy.** A level the
 * site cannot resolve (FormationStep::UNKNOWN) is never counted as a
 * brevet, so the brevet count is a *floor*: the real number is that or
 * higher, never lower. The legacy box sits on the same side of that
 * asymmetry — not counted, so never inflating the figure — with the
 * difference that it is named in its own sentence rather than in the
 * unrecognised list, because it *is* recognised: what is missing is which
 * brevet it is. Which means:
 *
 * - Threshold met → met for certain, whatever the unrecognised values turn
 *   out to be. Nothing to warn about, and warning anyway would train
 *   readers to ignore the warning.
 * - Threshold not met → possibly only because of what the site could not
 *   read. That, and only that, is when the situation says the count may be
 *   incomplete and points at the values to map.
 *
 * The unmet side is narrowed once more, to the cases where mapping the
 * unrecognised values could actually change the answer. A section short of
 * *animateurs* is short of them whatever their levels say, and a section
 * that would still miss the brevet threshold with every unknown counted as
 * a brevet is missing it for certain too. Warning in either case would be
 * the same crying-wolf as warning on the met side.
 */
final class SupervisionCalculator
{
    /**
     * @param list<FormationStep> $animatorSteps one entry per animateur of
     *        the section — the resolved step of each, unknowns included.
     */
    public function evaluate(int $headcount, array $animatorSteps): SupervisionSituation
    {
        $required = LeadershipRules::oneRequirementFor($headcount);

        $brevets = 0;
        $unknown = 0;
        foreach ($animatorSteps as $step) {
            if ($step->countsForOneRatio()) {
                $brevets++;
            } elseif ($step === FormationStep::UNKNOWN) {
                $unknown++;
            }
        }

        $animators = count($animatorSteps);
        $enoughAnimators = $animators >= $required['animators'];
        $satisfied = $enoughAnimators && $brevets >= $required['brevets'];

        // Could mapping the unrecognised values flip this answer? Only when
        // the animateur half already holds (no level changes a headcount)
        // and the brevet half would be reached if every unknown turned out
        // to be one.
        $couldFlip = !$satisfied
            && $unknown > 0
            && $enoughAnimators
            && ($brevets + $unknown) >= $required['brevets'];

        return new SupervisionSituation(
            headcount: $headcount,
            animatorCount: $animators,
            brevetCount: $brevets,
            unknownLevelCount: $unknown,
            requiredAnimators: $required['animators'],
            requiredBrevets: $required['brevets'],
            satisfied: $satisfied,
            mayBeIncomplete: $couldFlip,
        );
    }
}
