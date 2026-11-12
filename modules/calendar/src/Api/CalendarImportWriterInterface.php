<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public WRITE contract for the data migration module (ARCHITECTURE.md
 * §7.5, specifications.md §49): copy an event from another site's
 * calendar into one of this site's, **without the side effects of a
 * chief creating it** — no « Nouvelle activité » notification, no
 * reminder for an event already past, no retrospective board created
 * automatically.
 *
 * The one exception to CalendarDirectoryInterface's « read-only by
 * design »: a one-off copy of history is not something virtual events
 * can stand for, since the events must become real, editable rows.
 *
 * Every method takes the account the import runs for, and answers with
 * THAT account's rights as they are now, not as they were when the import
 * started: the calendars it may write to are the ones the chief calendar
 * page would offer it (CalendarEventService::getEditableCalendars()), and
 * they are checked again at every write.
 */
interface CalendarImportWriterInterface
{
    /**
     * The calendars this account may write to, for a picker.
     *
     * @return list<SelectableCalendar>
     */
    public function writableCalendars(int $userAccountId): array;

    /** Whether this account may write to this calendar right now. */
    public function canWriteTo(int $userAccountId, int $calendarId): bool;

    /**
     * Creates the event, re-checking the account's right on the calendar,
     * and returns its id. Future events get the reminders any event gets;
     * past ones none; nobody is notified.
     *
     * @throws \RuntimeException when the account may not write there, or
     *         the event is not one this calendar can hold
     */
    public function createImportedEvent(int $userAccountId, int $calendarId, ImportedEvent $event): int;
}
