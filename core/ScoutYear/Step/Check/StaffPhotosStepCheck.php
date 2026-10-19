<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « staff_photos », human: which sections checked in the target year
 * (chantier « Année scoute » §2.2, the Staff d'U included) have no group
 * photo OF THAT YEAR — last year's, which the Staffs page keeps showing,
 * does not count (SectionPhotoRepository::findSectionIdsWithoutPhotoForYear()).
 * Missing photos are one point to check naming the sections, never a
 * block, and every section photographed is not the step done.
 *
 * Before the target year's import there are no sections to look at: no
 * point, no figure, the waiting message.
 *
 * $details: section_count, with_photo_count, missing (list of section
 * labels); absent before the import.
 */
final class StaffPhotosStepCheck implements StepCheckProvider
{
    public function __construct(
        private SectionService $sections,
        private SectionPhotoRepository $sectionPhotoRepository
    ) {
    }

    public function stepKey(): string
    {
        return 'staff_photos';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('staffs', 'Ouvrir Staffs', '/chefs/staffs');
        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(
                primaryAction: $action,
                actions: [$action],
                pendingMessage: $context->waitingForImportMessage()
            );
        }

        $labels = [];
        foreach (CheckText::checkedSections($this->sections, $context->targetId(), withUnitStaff: true) as $section) {
            $labels[$section->sectionId] = $section->label;
        }
        $missing = array_map(
            static fn(int $id): string => $labels[$id],
            $this->sectionPhotoRepository->findSectionIdsWithoutPhotoForYear(array_keys($labels), $context->targetId())
        );

        return new StepCheck(
            pointsToCheck: $missing !== []
                ? [implode(', ', $missing) . " : pas de photo {$context->targetLabel()}"]
                : [],
            primaryAction: $action,
            actions: [$action],
            details: [
                'section_count' => count($labels),
                'with_photo_count' => count($labels) - count($missing),
                'missing' => $missing,
            ]
        );
    }
}
