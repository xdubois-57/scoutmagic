<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Modules\Calendar\Api\CalendarImportWriterInterface;
use Modules\Calendar\Api\ImportedEvent;
use Modules\Calendar\Api\SelectableCalendar;

/**
 * The calendar module's write contract, in memory: which calendars each
 * account may write to, and what was written.
 */
final class FakeCalendarImportWriter implements CalendarImportWriterInterface
{
    /** @var list<array{account: int, calendar: int, event: ImportedEvent}> */
    public array $created = [];

    /**
     * @param array<int, list<SelectableCalendar>> $writable account id => calendars
     */
    public function __construct(public array $writable = [])
    {
    }

    public function writableCalendars(int $userAccountId): array
    {
        return $this->writable[$userAccountId] ?? [];
    }

    public function canWriteTo(int $userAccountId, int $calendarId): bool
    {
        foreach ($this->writableCalendars($userAccountId) as $calendar) {
            if ($calendar->id === $calendarId) {
                return true;
            }
        }

        return false;
    }

    public function createImportedEvent(int $userAccountId, int $calendarId, ImportedEvent $event): int
    {
        if (!$this->canWriteTo($userAccountId, $calendarId)) {
            throw new \RuntimeException('Calendrier introuvable.');
        }
        $this->created[] = ['account' => $userAccountId, 'calendar' => $calendarId, 'event' => $event];

        return 1000 + count($this->created);
    }
}
