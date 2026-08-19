<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Security\Role;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;

class CalendarEventService
{
    public function __construct(
        private CalendarEventRepository $eventRepository,
        private CalendarService $calendarService,
        private CalendarNotificationService $notificationService,
        private ?CalendarRetroAutoCreateService $retroAutoCreateService = null
    ) {
    }

    /**
     * Calendars a chief-role viewer may create/edit events into: every
     * section calendar (chiefs have blanket access to every section, same
     * model as StaffsController — role_min: chief at the route is the
     * actual gate, not per-chief section ownership) plus supplementary
     * calendars whose visibility they qualify to view.
     *
     * @return Calendar[]
     */
    public function getEditableCalendarsForChief(Role $viewerRole): array
    {
        $sectionCalendars = $this->calendarService->getSectionCalendars();
        $supplementary = array_filter(
            $this->calendarService->getSupplementaryCalendars(),
            fn(Calendar $c) => $this->calendarService->isVisibleToRole($c, $viewerRole)
        );

        return array_values([...$sectionCalendars, ...$supplementary]);
    }

    /**
     * @throws CalendarException
     */
    public function createEvent(
        int $calendarId,
        string $title,
        string $startDate,
        ?string $endDate,
        ?string $startTime,
        ?string $endTime,
        ?string $location,
        ?string $description,
        ?int $createdBy,
        bool $autoCreateRetro = false,
        ?Role $viewerRole = null
    ): CalendarEvent {
        $title = trim($title);
        $this->validateEventFields($title, $startDate, $endDate);
        if ($this->calendarService->findById($calendarId) === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        $this->assertCalendarEditable($calendarId, $viewerRole);

        $id = $this->eventRepository->create(
            $calendarId,
            $title,
            $startDate,
            $this->emptyToNull($endDate),
            $this->emptyToNull($startTime),
            $this->emptyToNull($endTime),
            $this->emptyToNull($location),
            $this->emptyToNull($description),
            $createdBy,
            $autoCreateRetro
        );
        $event = $this->eventRepository->findById($id);
        \assert($event !== null);
        $this->notificationService->syncReminderForEvent($event);
        $this->notificationService->syncActivityReminderForEvent($event);
        $this->notificationService->dispatchEventPublished($event, $createdBy);
        $this->retroAutoCreateService?->syncAutoCreateForEvent($event);
        return $event;
    }

    /**
     * @throws CalendarException
     */
    public function updateEvent(
        int $id,
        int $calendarId,
        string $title,
        string $startDate,
        ?string $endDate,
        ?string $startTime,
        ?string $endTime,
        ?string $location,
        ?string $description,
        bool $autoCreateRetro = false,
        ?int $updatedBy = null,
        ?Role $viewerRole = null
    ): CalendarEvent {
        $existing = $this->eventRepository->findById($id);
        if ($existing === null) {
            throw new CalendarException('Évènement introuvable.');
        }

        $title = trim($title);
        $this->validateEventFields($title, $startDate, $endDate);
        if ($this->calendarService->findById($calendarId) === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        // Both ends of the move: the calendar the event currently lives in
        // (so it can't be dragged OUT of one the caller may not touch) and
        // the calendar it is being moved INTO.
        $this->assertCalendarEditable($existing->calendarId, $viewerRole);
        $this->assertCalendarEditable($calendarId, $viewerRole);

        $this->eventRepository->update(
            $id,
            $calendarId,
            $title,
            $startDate,
            $this->emptyToNull($endDate),
            $this->emptyToNull($startTime),
            $this->emptyToNull($endTime),
            $this->emptyToNull($location),
            $this->emptyToNull($description),
            $autoCreateRetro
        );
        $updated = $this->eventRepository->findById($id);
        \assert($updated !== null);
        $this->notificationService->syncReminderForEvent($updated);
        $this->notificationService->syncActivityReminderForEvent($updated);
        $this->notificationService->dispatchEventChanged($updated, $updatedBy);
        $this->retroAutoCreateService?->syncAutoCreateForEvent($updated);
        return $updated;
    }

    /**
     * @throws CalendarException
     */
    public function deleteEvent(int $id, ?Role $viewerRole = null): void
    {
        $event = $this->eventRepository->findById($id);
        if ($event === null) {
            throw new CalendarException('Évènement introuvable.');
        }
        $this->assertCalendarEditable($event->calendarId, $viewerRole);
        $this->notificationService->cancelReminderForEvent($id);
        $this->notificationService->cancelActivityReminderForEvent($id);
        $this->retroAutoCreateService?->cancelAutoCreateForEvent($id);
        $this->eventRepository->delete($id);
    }

    /**
     * Re-checks that $calendarId really is one this caller may write to.
     *
     * getEditableCalendarsForChief() already computes that set for the
     * picker, but the write paths only verified the calendar EXISTED — and
     * calendar_id/event_id arrive in the request body. A chief could
     * therefore post the id of an admin-only supplementary calendar (one
     * deliberately excluded from their editable set) and create, move or
     * delete events in it. role_min: chief on the route is the floor, not
     * the per-calendar boundary.
     *
     * $viewerRole null means there is no user to narrow against — a system
     * caller such as Modules\SosStaff\Service\CalendarSyncService, which
     * maintains its own calendar on the unit's behalf rather than acting
     * for a session. Only request-driven callers pass a role.
     *
     * @throws CalendarException
     */
    private function assertCalendarEditable(int $calendarId, ?Role $viewerRole): void
    {
        if ($viewerRole === null) {
            return;
        }

        foreach ($this->getEditableCalendarsForChief($viewerRole) as $calendar) {
            if ($calendar->id === $calendarId) {
                return;
            }
        }

        throw new CalendarException('Calendrier introuvable.');
    }

    private function validateEventFields(string $title, string $startDate, ?string $endDate): void
    {
        if ($title === '') {
            throw new CalendarException('Le titre est obligatoire.');
        }
        if (!$this->isValidDate($startDate)) {
            throw new CalendarException('Date de début invalide.');
        }
        $endDate = $this->emptyToNull($endDate);
        if ($endDate !== null) {
            if (!$this->isValidDate($endDate)) {
                throw new CalendarException('Date de fin invalide.');
            }
            if ($endDate < $startDate) {
                throw new CalendarException('La date de fin doit être postérieure à la date de début.');
            }
        }
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
