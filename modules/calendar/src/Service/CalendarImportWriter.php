<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Modules\Calendar\Api\CalendarImportWriterInterface;
use Modules\Calendar\Api\ImportedEvent;
use Modules\Calendar\Api\SelectableCalendar;
use Modules\Calendar\Repository\Calendar;

/**
 * Api\CalendarImportWriterInterface implementation: the account's rights
 * from AccountCalendarRights, the writable set and the write itself from
 * CalendarEventService — the same set and the same check as the chief
 * calendar page, so an import can reach no calendar that page would not
 * let the same person write to.
 */
final class CalendarImportWriter implements CalendarImportWriterInterface
{
    public function __construct(
        private CalendarEventService $events,
        private CalendarService $calendars,
        private AccountCalendarRights $rights
    ) {
    }

    public function writableCalendars(int $userAccountId): array
    {
        $editable = array_map(static fn (Calendar $c): int => $c->id, $this->editable($userAccountId));

        return array_values(array_filter(
            $this->calendars->selectableCalendars(),
            static fn (SelectableCalendar $c): bool => in_array($c->id, $editable, true)
        ));
    }

    public function canWriteTo(int $userAccountId, int $calendarId): bool
    {
        foreach ($this->editable($userAccountId) as $calendar) {
            if ($calendar->id === $calendarId) {
                return true;
            }
        }

        return false;
    }

    public function createImportedEvent(int $userAccountId, int $calendarId, ImportedEvent $event): int
    {
        $rights = $this->rights->of($userAccountId);
        if ($rights === null) {
            throw new CalendarException('Calendrier introuvable.');
        }

        return $this->events->createImportedEvent(
            $calendarId,
            $event->title,
            $event->startDate,
            $event->endDate,
            $event->startTime,
            $event->endTime,
            $event->location,
            $event->description,
            $userAccountId,
            $rights['role'],
            $rights['sections']
        )->id;
    }

    /**
     * @return Calendar[]
     */
    private function editable(int $userAccountId): array
    {
        $rights = $this->rights->of($userAccountId);

        return $rights === null ? [] : $this->events->getEditableCalendars($rights['role'], $rights['sections']);
    }
}
