<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;

/**
 * Scheduled by Service\CalendarRetroAutoCreateService::syncAutoCreateForEvent()
 * (reference "event-{id}") for an event whose auto_create_retro flag is
 * set. Re-checks everything fresh at run time rather than trusting
 * anything true when it was scheduled, since a task can be scheduled
 * weeks in advance:
 *   1. the retro module must still be enabled right now — encoded by the
 *      capability resolving at all (TaskCapabilities re-checks enablement
 *      on every resolve);
 *   2. the event must still exist;
 *   3. at most one board per event — enforced by the retro module itself
 *      inside createBoardForEvent(), where the rule belongs.
 *
 * The split of responsibilities (ARCHITECTURE.md §7.5): the flag and this
 * task's scheduling are the calendar's — it knows when a board should
 * come to exist. What a board IS — title format, defaults, token,
 * auto-close and its scheduling, journal — is the retro module's, behind
 * Modules\Retro\Api\RetroBoardCreationInterface. This handler used to
 * reach into the retro module's BoardRepository and re-implement all of
 * that by hand.
 */
class AutoCreateRetroHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $eventId = (int) ($payload['event_id'] ?? 0);
        if ($eventId <= 0) {
            return;
        }

        $retroBoards = $context->getOptional(\Modules\Retro\Api\RetroBoardCreationInterface::class);
        if ($retroBoards === null) {
            return; // retro disabled since scheduling — quiet degradation
        }

        $pdo = $context->connection->getPdo();

        $eventRepository = new CalendarEventRepository($pdo);
        $event = $eventRepository->findById($eventId);
        if ($event === null) {
            return; // deleted since scheduling
        }

        $calendarRepository = new CalendarRepository($pdo, $context->encryption);
        $calendar = $calendarRepository->findById($event->calendarId);

        $retroBoards->createBoardForEvent(
            $eventId,
            $event->title,
            $this->resolveCalendarName($calendar, $pdo),
            $event->startDate,
            $event->endDate
        );
    }

    /**
     * Section calendars never have their own `name` column set — the
     * display label is the section's own name, resolved via a direct join
     * rather than constructing a full Core\Member\SectionService here.
     * Mirrors Service\CalendarService::labelsByCalendarId()'s fallback
     * chain (section name → desk_code → 'Section'; own name → 'Calendrier'
     * for a supplementary calendar).
     */
    private function resolveCalendarName(?Calendar $calendar, \PDO $pdo): string
    {
        if ($calendar === null) {
            return 'Calendrier';
        }
        if ($calendar->sectionId === null) {
            return $calendar->name ?? 'Calendrier';
        }

        $stmt = $pdo->prepare('SELECT name, desk_code FROM sections WHERE id = ?');
        $stmt->execute([$calendar->sectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'Section';
        }

        return $row['name'] !== null && $row['name'] !== '' ? (string) $row['name'] : (string) $row['desk_code'];
    }
}
