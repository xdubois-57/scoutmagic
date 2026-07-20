<?php

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
        private CalendarNotificationService $notificationService
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
        ?int $createdBy
    ): CalendarEvent {
        $title = trim($title);
        $this->validateEventFields($title, $startDate, $endDate);
        if ($this->calendarService->findById($calendarId) === null) {
            throw new CalendarException('Calendrier introuvable.');
        }

        $id = $this->eventRepository->create(
            $calendarId,
            $title,
            $startDate,
            $this->emptyToNull($endDate),
            $this->emptyToNull($startTime),
            $this->emptyToNull($endTime),
            $this->emptyToNull($location),
            $this->emptyToNull($description),
            $createdBy
        );
        $event = $this->eventRepository->findById($id);
        \assert($event !== null);
        $this->notificationService->syncReminderForEvent($event);
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
        ?string $description
    ): CalendarEvent {
        if ($this->eventRepository->findById($id) === null) {
            throw new CalendarException('Évènement introuvable.');
        }

        $title = trim($title);
        $this->validateEventFields($title, $startDate, $endDate);
        if ($this->calendarService->findById($calendarId) === null) {
            throw new CalendarException('Calendrier introuvable.');
        }

        $this->eventRepository->update(
            $id,
            $calendarId,
            $title,
            $startDate,
            $this->emptyToNull($endDate),
            $this->emptyToNull($startTime),
            $this->emptyToNull($endTime),
            $this->emptyToNull($location),
            $this->emptyToNull($description)
        );
        $updated = $this->eventRepository->findById($id);
        \assert($updated !== null);
        $this->notificationService->syncReminderForEvent($updated);
        return $updated;
    }

    /**
     * @throws CalendarException
     */
    public function deleteEvent(int $id): void
    {
        if ($this->eventRepository->findById($id) === null) {
            throw new CalendarException('Évènement introuvable.');
        }
        $this->notificationService->cancelReminderForEvent($id);
        $this->eventRepository->delete($id);
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
