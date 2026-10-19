<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Calendar\Api\ScoutYearEventCountProvider;

/**
 * Step « ephemerides », human: the calendar events inside the target
 * year's dates, counted per section checked (chantier « Année scoute »
 * §2.2, the Staff d'U left out: it has no section calendar). Only a
 * section with NO event is a point to check — the number never says
 * whether a year is complete, and it is never the step done.
 *
 * Which sections are checked is the target year's import, so the
 * per-section count waits for it; the total of the year is shown in the
 * meantime, for information.
 *
 * $eventCount is null exactly when the calendar module is off, and the
 * step is then not on the page.
 *
 * $details: event_count; sections (list of {label, event_count}) after
 * the import.
 */
final class EphemeridesStepCheck implements StepCheckProvider
{
    public function __construct(
        private ?ScoutYearEventCountProvider $eventCount,
        private SectionService $sections
    ) {
    }

    public function stepKey(): string
    {
        return 'ephemerides';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('calendar', 'Ouvrir le calendrier', '/chefs/calendar');
        if ($this->eventCount === null) {
            return new StepCheck(primaryAction: $action, actions: [$action]);
        }

        $from = (string) $context->targetYear['start_date'];
        $to = (string) $context->targetYear['end_date'];
        $details = ['event_count' => $this->eventCount->countEventsBetween($from, $to)];

        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(
                primaryAction: $action,
                actions: [$action],
                pendingMessage: $context->waitingForImportMessage(),
                details: $details
            );
        }

        $bySection = $this->eventCount->countEventsBySectionBetween($from, $to);
        $rows = [];
        $points = [];
        foreach (CheckText::checkedSections($this->sections, $context->targetId(), withUnitStaff: false) as $section) {
            $count = $bySection[$section->sectionId] ?? 0;
            if ($count === 0) {
                $points[] = "{$section->label} : aucun événement";
            }
            $rows[] = ['label' => $section->label, 'event_count' => $count];
        }

        return new StepCheck(
            pointsToCheck: $points,
            primaryAction: $action,
            actions: [$action],
            details: $details + ['sections' => $rows]
        );
    }
}
