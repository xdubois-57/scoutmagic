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
     * section calendar plus supplementary calendars whose visibility they
     * qualify to view.
     *
     * This is the SEEING set, and it is deliberately not narrowed by which
     * sections the account staffs: the whole point of the chief calendar is
     * that an animateur of the Baladins sees what the rest of the unit is
     * doing. getEditableCalendars() below is the writing set.
     *
     * @return Calendar[]
     */
    public function getViewableCalendars(Role $viewerRole): array
    {
        $sectionCalendars = $this->calendarService->getSectionCalendars();
        $supplementary = array_filter(
            $this->calendarService->getSupplementaryCalendars(),
            fn(Calendar $c) => $this->calendarService->isVisibleToRole($c, $viewerRole)
        );

        return array_values([...$sectionCalendars, ...$supplementary]);
    }

    /**
     * Calendars this viewer may create/edit/delete events in: a subset of
     * what they can see.
     *
     * A conjunction of two independent conditions:
     *
     *  - the SECTION: a section calendar needs an animateur of that section
     *    — the blanket "any chief writes in any section" this page used to
     *    grant is exactly what IT-01's counterpart removed for section
     *    documents. A supplementary calendar carries no section and passes
     *    this half unconditionally.
     *  - the ROLE: `calendar_calendars.edit_role_min`, the unit's own
     *    answer to "who may modify the events here". It defaults to
     *    'chief', which is what every calendar behaved like before the
     *    column existed, and can be raised to 'admin' so a calendar stays
     *    VISIBLE to the animateurs while only the chefs d'unité write in
     *    it — a combination the single `visibility` column could not
     *    express.
     *
     * $staffedSectionIds is resolved by the CONTROLLER (Core\Member\
     * SectionStaffAuthorizationService, ARCHITECTURE.md §8.33) and passed
     * in: a Service never reads the session (ARCHITECTURE.md §13), and this
     * one must stay usable by a caller that has no session at all. An empty
     * array therefore means "no section calendars", never "all of them" —
     * a caller that forgets the argument is denied, not granted.
     *
     * @param int[] $staffedSectionIds
     * @return Calendar[]
     */
    public function getEditableCalendars(Role $viewerRole, array $staffedSectionIds): array
    {
        return array_values(array_filter(
            $this->getViewableCalendars($viewerRole),
            fn(Calendar $c) => $this->staffsThisCalendar($c, $staffedSectionIds)
                && $viewerRole->hasAccess(Role::fromString($c->editRoleMin))
        ));
    }

    /**
     * The section half of the conjunction. A supplementary calendar has no
     * section, so it passes here and its edit_role_min is the whole rule.
     *
     * @param int[] $staffedSectionIds
     */
    private function staffsThisCalendar(Calendar $calendar, array $staffedSectionIds): bool
    {
        return $calendar->sectionId === null || in_array($calendar->sectionId, $staffedSectionIds, true);
    }

    /**
     * @param int[] $staffedSectionIds
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
        ?Role $viewerRole = null,
        array $staffedSectionIds = []
    ): CalendarEvent {
        $title = trim($title);
        $this->validateEventFields($title, $startDate, $endDate);
        if ($this->calendarService->findById($calendarId) === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        $this->assertCalendarEditable($calendarId, $viewerRole, $staffedSectionIds);

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
     * @param int[] $staffedSectionIds
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
        ?Role $viewerRole = null,
        array $staffedSectionIds = []
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
        $this->assertCalendarEditable($existing->calendarId, $viewerRole, $staffedSectionIds);
        $this->assertCalendarEditable($calendarId, $viewerRole, $staffedSectionIds);

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
     * @param int[] $staffedSectionIds
     * @throws CalendarException
     */
    public function deleteEvent(int $id, ?Role $viewerRole = null, array $staffedSectionIds = []): void
    {
        $event = $this->eventRepository->findById($id);
        if ($event === null) {
            throw new CalendarException('Évènement introuvable.');
        }
        $this->assertCalendarEditable($event->calendarId, $viewerRole, $staffedSectionIds);
        $this->notificationService->cancelReminderForEvent($id);
        $this->notificationService->cancelActivityReminderForEvent($id);
        $this->retroAutoCreateService?->cancelAutoCreateForEvent($id);
        $this->eventRepository->delete($id);
    }

    /**
     * Re-checks that $calendarId really is one this caller may write to.
     *
     * getEditableCalendars() already computes that set for the form's
     * calendar picker, but the write paths only verified the calendar
     * EXISTED — and calendar_id/event_id arrive in the request body. A
     * chief could therefore post the id of an admin-only supplementary
     * calendar, or (since this narrowing landed) of a section they do not
     * staff, and create, move or delete events in it. role_min: chief on
     * the route is the floor, not the per-calendar boundary.
     *
     * $viewerRole null means there is no user to narrow against — a system
     * caller such as Modules\SosStaff\Service\CalendarSyncService, which
     * maintains its own calendar on the unit's behalf rather than acting
     * for a session. Only request-driven callers pass a role, and the
     * short-circuit below is what keeps the SOS on-call rota publishing;
     * $staffedSectionIds is meaningless for such a caller and ignored.
     *
     * @param int[] $staffedSectionIds
     * @throws CalendarException
     */
    private function assertCalendarEditable(int $calendarId, ?Role $viewerRole, array $staffedSectionIds): void
    {
        if ($viewerRole === null) {
            return;
        }

        foreach ($this->getEditableCalendars($viewerRole, $staffedSectionIds) as $calendar) {
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
