<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\AppClock;
use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Repository\LeadershipRepository;

/**
 * What this module has to say about the unit's current state
 * (`Core\Attention\AttentionPointProvider`).
 *
 * Two things, and both are ones that only become visible the day they
 * become expensive:
 *
 * - **A section no longer supervised in sufficient numbers.** An
 *   animateur leaving can break the ONE ratio, and today the site only
 *   says so on a page nobody opens between camps. Knowing it the day it
 *   happens rather than the evening before a camp has real value —
 *   `SupervisionCalculator` already answers the question, this only
 *   surfaces its answer.
 * - **An intendant whose free registration window is running out.** The
 *   federation bills a registered steward for the whole year once the
 *   free window has passed, so this is a deadline: the point carries a
 *   due date and sorts itself to the top of the page.
 *
 * That second one is the point the chantier put under « Cotisations ».
 * It is here instead, deliberately: the rule, its constants and the
 * countdown all live in this module (`LeadershipRules::
 * STEWARD_FREE_DAYS`, `StewardService`), and a second implementation in
 * `fees` would be the same reasoning in two places, one season away from
 * disagreeing with itself. The reader still sees a point about being
 * billed; only the chip above it says « Encadrement ».
 */
class LeadershipAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private LeadershipRepository $repository,
        private TrainingService $trainingService,
        private FormationLevelResolver $resolver,
        private StewardService $stewardService
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Encadrement';
    }

    public function collect(int $scoutYearId): array
    {
        $staff = $this->repository->findStaffFunctions($scoutYearId);

        return array_merge(
            $this->understaffedSections($staff, $scoutYearId),
            $this->stewardsRunningOut($staff, $scoutYearId)
        );
    }

    /**
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     * @return AttentionPoint[]
     */
    private function understaffedSections(array $staff, int $scoutYearId): array
    {
        $points = [];

        foreach ($this->trainingService->sectionSituations($staff, $scoutYearId, $this->resolver) as $entry) {
            $situation = $entry['situation'];

            // A section with nobody in it is not understaffed, it is
            // empty — and the import report is what says so.
            if ($situation->headcount === 0 || $situation->satisfied) {
                continue;
            }

            $why = $situation->animatorCount . ' animateur' . ($situation->animatorCount > 1 ? 's' : '')
                . ' pour ' . $situation->headcount . ' animé' . ($situation->headcount > 1 ? 's' : '')
                . ', là où la norme ONE en demande ' . $situation->requiredAnimators
                . ' dont ' . $situation->requiredBrevets . ' breveté' . ($situation->requiredBrevets > 1 ? 's' : '') . '.';

            if ($situation->mayBeIncomplete) {
                $why .= ' Le calcul peut être incomplet : certains niveaux de formation encodés dans Desk '
                    . "ne sont pas encore reconnus par le site.";
            }

            $points[] = new AttentionPoint(
                title: 'Les ' . $entry['section_name'] . ' ne sont plus encadrés en nombre suffisant',
                why: $why,
                actionLabel: "Voir l'encadrement",
                actionUrl: '/admin/leadership/training',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }

    /**
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     * @return AttentionPoint[]
     */
    private function stewardsRunningOut(array $staff, int $scoutYearId): array
    {
        $today = AppClock::now();

        // Between June and August the free window does not apply at all,
        // so there is no countdown to run out — the module's own page
        // states this, and a point that contradicted it would be worse
        // than no point.
        if ($this->stewardService->isSummerRegime($today)) {
            return [];
        }

        $points = [];
        foreach ($this->stewardService->registrations($staff, $scoutYearId, $today) as $line) {
            if ($line->days === null || $line->days < LeadershipRules::STEWARD_WARNING_DAYS) {
                continue;
            }

            $remaining = LeadershipRules::STEWARD_FREE_DAYS - $line->days;

            $points[] = new AttentionPoint(
                title: $line->fullName . ' est intendant depuis ' . $line->days . ' jours',
                why: 'Passé ' . LeadershipRules::STEWARD_FREE_DAYS . ' jours, un intendant encodé dans Desk '
                    . "devient facturable par la fédération pour l'année entière. Le retirer de Desk avant "
                    . 'cette échéance est la seule façon de l\'éviter.',
                actionLabel: 'Voir les intendants',
                actionUrl: '/admin/leadership/stewards',
                dueDate: $today->modify('+' . max(0, $remaining) . ' days'),
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }
}
