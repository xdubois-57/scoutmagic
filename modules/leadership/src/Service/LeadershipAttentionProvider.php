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
 * One thing, and it is one that only becomes visible the day it becomes
 * expensive: **an intendant whose free registration window is running
 * out.** The federation bills a registered steward for the whole year
 * once the free window has passed, so this is a deadline: the point
 * carries a due date and sorts itself to the top of the page.
 *
 * That is the point the chantier put under « Cotisations ». It is here
 * instead, deliberately: the rule, its constants and the countdown all
 * live in this module (`LeadershipRules::STEWARD_FREE_DAYS`,
 * `StewardService`), and a second implementation in `fees` would be the
 * same reasoning in two places, one season away from disagreeing with
 * itself. The reader still sees a point about being billed; only the chip
 * above it says « Encadrement ».
 *
 * **The ONE ratio is deliberately not a second point.** A section short of
 * animateurs used to be reported here as well; it no longer is.
 * `SupervisionCalculator` still computes the standard and
 * `/admin/leadership/training` still states it to whoever opens that page,
 * which is where that question is asked — this provider does not repeat it.
 */
class LeadershipAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private LeadershipRepository $repository,
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

        return $this->stewardsRunningOut($staff, $scoutYearId);
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
