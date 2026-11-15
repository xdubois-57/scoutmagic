<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Security\Role;
use Core\Service\DateInput;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Presences\Api\PresenceEventCleanupInterface;

class CalendarEventService
{
    /** calendar_events.title is VARCHAR(200). */
    public const MAX_TITLE_LENGTH = 200;
    /** calendar_events.location is VARCHAR(255). */
    public const MAX_LOCATION_LENGTH = 255;

    public function __construct(
        private CalendarEventRepository $eventRepository,
        private CalendarService $calendarService,
        private CalendarNotificationService $notificationService,
        private ?CalendarRetroAutoCreateService $retroAutoCreateService = null,
        private ?PresenceEventCleanupInterface $presenceEventCleanup = null,
        private ?\PDO $pdo = null
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
     * An event copied from another site's calendar (Api\
     * CalendarImportWriterInterface): the same checks as createEvent() —
     * fields, and the calendar re-checked against the caller's editable
     * set — and none of its broadcast.
     *
     * - **No dispatchEventPublished().** A unit copying years of history
     *   would otherwise send its members one « Nouvelle activité » per
     *   event (specifications.md §49, roadmap §2).
     * - **Reminders only where createEvent() would schedule them for an
     *   event in the future.** Both sync methods already skip an event that
     *   has started or whose reminder time is past, so a past event gets
     *   none, and an upcoming one behaves like any other event from now on —
     *   the « fonctionnement normal ultérieur » the specification keeps.
     * - **No automatic retrospective**: auto_create_retro is false, so the
     *   retro scheduler is never involved.
     *
     * The times must be `HH:MM` (or `HH:MM:SS`); the importer has already
     * converted them to Brussels local time.
     *
     * @param int[] $staffedSectionIds
     * @throws CalendarException
     */
    public function createImportedEvent(
        int $calendarId,
        string $title,
        string $startDate,
        ?string $endDate,
        ?string $startTime,
        ?string $endTime,
        ?string $location,
        ?string $description,
        ?int $createdBy,
        Role $viewerRole,
        array $staffedSectionIds
    ): CalendarEvent {
        $title = trim($title);
        $this->validateEventFields($title, $startDate, $endDate);
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH || mb_strlen((string) $location) > self::MAX_LOCATION_LENGTH) {
            throw new CalendarException('Le titre ou le lieu est trop long.');
        }
        $startTime = $this->emptyToNull($startTime);
        $endTime = $startTime === null ? null : $this->emptyToNull($endTime);
        foreach ([$startTime, $endTime] as $time) {
            if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
                throw new CalendarException('Heure invalide.');
            }
        }
        if ($this->calendarService->findById($calendarId) === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        $this->assertCalendarEditable($calendarId, $viewerRole, $staffedSectionIds);

        $id = $this->eventRepository->create(
            $calendarId,
            $title,
            $startDate,
            $this->emptyToNull($endDate),
            $startTime,
            $endTime,
            $this->emptyToNull($location),
            $this->emptyToNull($description),
            $createdBy,
            false
        );
        $event = $this->eventRepository->findById($id);
        \assert($event !== null);
        $this->notificationService->syncReminderForEvent($event);
        $this->notificationService->syncActivityReminderForEvent($event);

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

        // ALL OF IT OR NONE OF IT. Deleting an event is several DELETEs
        // across two modules' tables, and the order matters: the sheet
        // goes first, the event last. Without a transaction, a failure in
        // between leaves an evening still on the calendar whose attendance
        // — states and encrypted comments — has already been destroyed,
        // and nothing can bring that back.
        //
        // `inTransaction()` guarded because PDO has no nested
        // transactions, same shape as
        // `Modules\Rental\Repository\RentalBookingRepository`. Null $pdo
        // keeps every existing call site and test working; it then behaves
        // exactly as before.
        // Non-null exactly when this call owns the transaction, so the
        // three `?->` below can neither begin one twice nor roll back
        // somebody else's.
        $pdo = $this->pdo !== null && !$this->pdo->inTransaction() ? $this->pdo : null;
        $pdo?->beginTransaction();

        try {
            $this->notificationService->cancelReminderForEvent($id);
            $this->notificationService->cancelActivityReminderForEvent($id);
            $this->retroAutoCreateService?->cancelAutoCreateForEvent($id);
            // The sheet of an evening that no longer exists: states,
            // encrypted comments and the short code it was reached by. No
            // foreign key does this — a module never constrains another
            // module's table — so it is erased here or never
            // (Modules\Presences\Api\PresenceEventCleanupInterface).
            $this->presenceEventCleanup?->forgetEvent($id);
            $this->eventRepository->delete($id);
        } catch (\Throwable $e) {
            $pdo?->rollBack();
            throw $e;
        }

        $pdo?->commit();
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
     * $viewerRole null now DENIES, like everything else about this check:
     * the one role-less "system caller" that used to short-circuit here
     * (the SOS module's calendar sync) is gone — duty periods are virtual
     * events (ARCHITECTURE.md §7.6) and nothing writes to `calendar_events`
     * without a session anymore. The parameter stays nullable only so a
     * call site that forgets it fails closed instead of failing to compile
     * into a broader grant.
     *
     * @param int[] $staffedSectionIds
     * @throws CalendarException
     */
    private function assertCalendarEditable(int $calendarId, ?Role $viewerRole, array $staffedSectionIds): void
    {
        if ($viewerRole === null) {
            throw new CalendarException('Calendrier introuvable.');
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
        return DateInput::isIso($value);
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
