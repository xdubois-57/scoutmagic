<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « staff_photos », human: how many sections of the target year have
 * a group photo OF THAT YEAR — last year's, which the Staffs page keeps
 * showing, does not count. Missing photos are a point to check, never
 * a block, and every section photographed is not the step done.
 *
 * Before the target year's import there is nothing to count: no point,
 * no figure, the waiting message. The list of the sections still
 * missing comes with the core's read contract.
 */
final class StaffPhotosStepCheck implements StepCheckProvider
{
    public function __construct(
        private ScoutYearResolver $resolver,
        private SectionPhotoRepository $sectionPhotoRepository
    ) {
    }

    public function stepKey(): string
    {
        return 'staff_photos';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('staffs', 'Aller aux staffs et badges', '/chefs/staffs');
        $sections = $context->hasSuccessfulImport() ? $this->resolver->countSections($context->targetId()) : 0;

        if ($sections === 0) {
            return new StepCheck(
                primaryAction: $action,
                actions: [$action],
                pendingMessage: $context->waitingForImportMessage()
            );
        }

        $withPhoto = min($sections, $this->sectionPhotoRepository->countSectionsWithPhotoForYear($context->targetId()));
        $missing = $sections - $withPhoto;

        return new StepCheck(
            pointsToCheck: $missing > 0
                ? [$missing === 1
                    ? "1 section sans photo de groupe de {$context->targetLabel()}"
                    : "{$missing} sections sans photo de groupe de {$context->targetLabel()}"]
                : [],
            primaryAction: $action,
            actions: [$action],
            details: ['section_count' => $sections, 'with_photo_count' => $withPhoto]
        );
    }
}
