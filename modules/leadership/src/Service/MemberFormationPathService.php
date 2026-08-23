<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Core\Module\FormationPathProvider;
use Core\Module\FormationPathView;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;

/**
 * Draws one member's own training path for their member page — the module's
 * only member-facing surface, and the only one that is not admin-gated.
 *
 * It shows the member what Desk holds about them and what the next step
 * would be. It shows nothing else: no obligation, no CQA, no extrait, no
 * comparison with anybody, and no encouragement. The rest of this module is
 * a tool for the unit team; this is a person reading their own file.
 */
class MemberFormationPathService implements FormationPathProvider
{
    public function __construct(
        private LeadershipRepository $repository,
        private FormationLevelMappingRepository $mappingRepository,
        private FormationLevelResolver $resolver
    ) {
    }

    public function getFormationPath(int $memberId, int $scoutYearId): ?FormationPathView
    {
        $rawValue = $this->repository->findFormationLevelForMember($memberId, $scoutYearId);
        $resolver = $this->resolver->withMapping($this->mappingRepository->findAll());
        $step = $resolver->resolve($rawValue);

        $steps = [];
        foreach (FormationStep::path() as $milestone) {
            $steps[] = [
                'label' => $milestone->label(),
                // UNKNOWN reaches nothing: a value the site cannot read must
                // not light up milestones it might not have earned.
                'reached' => $step !== FormationStep::UNKNOWN && $milestone->rank() <= $step->rank(),
                'current' => $milestone === $step,
            ];
        }

        return new FormationPathView(
            steps: $steps,
            currentLabel: $step->description(),
            nextLabel: $step->next()?->label(),
            isRecognised: $step !== FormationStep::UNKNOWN,
        );
    }
}
