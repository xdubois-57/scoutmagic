<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\Module\SectionResponsableProvider;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « desk_staff », human: once the target year is imported, each
 * section checked (chantier « Année scoute » §2.2 — the Staff d'U left
 * out, it has no responsable to designate) with its number of animateurs
 * and its responsable for THAT year (§2.4).
 *
 * The responsable comes from the core hook SectionResponsableProvider,
 * which the trombinoscope module implements — core never reads that
 * module's flags. Without it (module off, $responsables null) only « at
 * least one animateur » is checked, and the step says the responsable
 * cannot be verified.
 *
 * Points to check: a section with animés and no animateur; with the hook,
 * a section with animateurs and no responsable. Before the import: the
 * waiting message, and nothing else.
 *
 * $details: sections (list of {label, chief_count, anime_count,
 * responsable: ?{name, url}}), responsable_checked, imported_at.
 */
final class DeskStaffStepCheck implements StepCheckProvider
{
    public function __construct(
        private SectionService $sections,
        private ?SectionResponsableProvider $responsables
    ) {
    }

    public function stepKey(): string
    {
        return 'desk_staff';
    }

    public function check(StepContext $context): StepCheck
    {
        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(pendingMessage: $context->waitingForImportMessage());
        }

        $sections = CheckText::checkedSections($this->sections, $context->targetId(), withUnitStaff: false);
        $responsables = $this->responsables?->getResponsables(
            array_map(static fn($section): int => $section->sectionId, $sections),
            $context->targetId()
        );

        $rows = [];
        $points = [];
        foreach ($sections as $section) {
            $responsable = $responsables[$section->sectionId] ?? null;
            if ($section->chiefCount === 0) {
                if ($section->animeCount > 0) {
                    $points[] = "{$section->label} : aucun animateur";
                }
            } elseif ($responsables !== null && $responsable === null) {
                $points[] = "{$section->label} : aucun responsable";
            }

            $rows[] = [
                'label' => $section->label,
                'chief_count' => $section->chiefCount,
                'anime_count' => $section->animeCount,
                'responsable' => $responsable === null ? null : [
                    'name' => $responsable->getDisplayNameFull($section->sectionId),
                    'url' => CheckText::memberUrl($responsable->memberYearId),
                ],
            ];
        }

        return new StepCheck(
            pointsToCheck: $points,
            details: [
                'sections' => $rows,
                'responsable_checked' => $responsables !== null,
                'imported_at' => $context->latestTargetImport?->importedAt,
            ]
        );
    }
}
