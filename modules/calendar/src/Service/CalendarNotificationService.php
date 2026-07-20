<?php

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Scheduler\SchedulerService;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;

/**
 * Multi-day event reminder notifications (Configuration > Calendrier >
 * Notifications). When enabled, a reminder email is sent to a section's
 * staff a configurable number of days before one of that section's
 * multi-day events starts, asking them to declare the event in Desk (if
 * not yet done) and to send the intendants list in advance to the Staff
 * d'U. Reuses the generic scheduler mechanism (Core\Scheduler) rather than
 * a bespoke cron/queue — same precedent as the SOS Staff d'U module's
 * redirect transitions.
 *
 * Only section calendars are eligible — a supplementary calendar (e.g.
 * "Animateurs") has no "organizing section" whose staff could be
 * reminded to declare it in Desk.
 */
class CalendarNotificationService
{
    public const MODULE_ID = 'calendar';
    public const TASK_KEY = 'multiday_event_reminder';

    private const ENABLED_KEY = 'notify_multiday_events_enabled';
    private const DAYS_BEFORE_KEY = 'notify_multiday_events_days_before';

    public function __construct(
        private SchedulerService $schedulerService,
        private SettingService $settingService,
        private CalendarService $calendarService,
        private CalendarEventRepository $eventRepository
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settingService->get(self::ENABLED_KEY, 'calendar', '0') === '1';
    }

    public function getDaysBefore(): int
    {
        return (int) $this->settingService->get(self::DAYS_BEFORE_KEY, 'calendar', '14');
    }

    /**
     * @throws SettingException on an invalid value
     */
    public function setEnabled(bool $enabled): void
    {
        $this->settingService->set(self::ENABLED_KEY, $enabled ? '1' : '0', 'calendar');
        $this->resyncAll();
    }

    /**
     * @throws CalendarException on an invalid value
     */
    public function setDaysBefore(int $days): void
    {
        if ($days < 1 || $days > 365) {
            throw new CalendarException('Le délai doit être compris entre 1 et 365 jours.');
        }
        $this->settingService->set(self::DAYS_BEFORE_KEY, (string) $days, 'calendar');
        $this->resyncAll();
    }

    /**
     * Call after creating or updating an event — (re)computes whether a
     * reminder is due and reschedules it, replacing any previously
     * scheduled reminder for this event (idempotent: the reference is
     * stable per event, so re-saving an event never leaves a stale
     * duplicate behind).
     */
    public function syncReminderForEvent(CalendarEvent $event): void
    {
        $this->cancelReminderForEvent($event->id);

        if (!$this->isEnabled() || !$event->isMultiDay()) {
            return;
        }

        $calendar = $this->calendarService->findById($event->calendarId);
        if ($calendar === null || !$calendar->isSectionCalendar()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $eventStart = new \DateTimeImmutable($event->startDate);
        if ($eventStart <= $now) {
            return; // already started/passed — nothing to remind about
        }

        $idealRunAt = $eventStart->modify('-' . $this->getDaysBefore() . ' days')->setTime(8, 0);
        $runAt = $idealRunAt > $now ? $idealRunAt : $now->modify('+5 minutes');

        $this->schedulerService->schedule(
            self::MODULE_ID,
            self::TASK_KEY,
            $runAt,
            ['event_id' => $event->id, 'calendar_id' => $event->calendarId],
            $this->referenceFor($event->id)
        );
    }

    /**
     * Call after deleting an event (or before, order doesn't matter — only
     * the event id is needed) to cancel any pending reminder for it.
     */
    public function cancelReminderForEvent(int $eventId): void
    {
        $existing = $this->schedulerService->find(self::MODULE_ID, self::TASK_KEY, $this->referenceFor($eventId));
        if ($existing !== null && $existing['status'] === 'pending') {
            $this->schedulerService->cancel((int) $existing['id']);
        }
    }

    /**
     * Recompute every section calendar's future multi-day events —
     * called when the feature is toggled or the lead time changes, so
     * already-existing events pick up the new setting immediately rather
     * than only affecting events created/edited from now on.
     */
    private function resyncAll(): void
    {
        foreach ($this->calendarService->getSectionCalendars() as $calendar) {
            foreach ($this->eventRepository->findByCalendarId($calendar->id) as $event) {
                $this->syncReminderForEvent($event);
            }
        }
    }

    private function referenceFor(int $eventId): string
    {
        return 'event-' . $eventId;
    }
}
