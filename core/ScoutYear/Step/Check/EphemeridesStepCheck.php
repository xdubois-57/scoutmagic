<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Calendar\Api\ScoutYearEventCountProvider;

/**
 * Step « ephemerides », human: the number of calendar events inside the
 * target year, for information. Never a point to check on its own — only
 * a SECTION with no event is one, and the count per section comes
 * with the calendar module's contract. Never the step done either.
 *
 * $eventCount is null exactly when the calendar module is off, and the
 * step is then not on the page.
 */
final class EphemeridesStepCheck implements StepCheckProvider
{
    public function __construct(private ?ScoutYearEventCountProvider $eventCount)
    {
    }

    public function stepKey(): string
    {
        return 'ephemerides';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('calendar', 'Aller au calendrier', '/chefs/calendar');

        $details = [];
        if ($this->eventCount !== null) {
            $details['event_count'] = $this->eventCount->countEventsBetween(
                (string) $context->targetYear['start_date'],
                (string) $context->targetYear['end_date']
            );
        }

        return new StepCheck(primaryAction: $action, actions: [$action], details: $details);
    }
}
