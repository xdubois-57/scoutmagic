<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Notification\NotificationService;
use Core\Scheduler\SchedulerService;
use Core\Service\DateInput;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;

/**
 * Calendar notifications: (a) the pre-existing multi-day event reminder
 * email to a section's staff (Configuration > Calendrier > Notifications
 * — asking them to declare the event in Desk), and (b) the notification-
 * centre/push types declared in module.json ("calendar.event_published",
 * "calendar.event_changed", "calendar.event_reminder"), dispatched via
 * Core\Notification\NotificationService::dispatch() to the accounts
 * personally linked to the activity's section (Service\EventAudienceResolver,
 * issue #1348). Reuses the generic scheduler mechanism (Core\Scheduler) rather
 * than a bespoke cron/queue for both.
 *
 * Only section calendars are eligible for the Desk-declaration email —
 * a supplementary calendar (e.g. "Animateurs") has no "organizing
 * section" whose staff could be reminded to declare it in Desk. The
 * notification-centre types have no such restriction — they fire for
 * every calendar.
 *
 * $notificationService/$audience are optional/nullable —
 * without them, the notification-centre dispatch methods are no-ops,
 * matching the codebase's established degrade-gracefully pattern for
 * narrow unit-test construction.
 */
class CalendarNotificationService
{
    public const MODULE_ID = 'calendar';
    public const TASK_KEY = 'multiday_event_reminder';
    public const REMINDER_TASK_KEY = 'event_reminder';

    private const ENABLED_KEY = 'notify_multiday_events_enabled';
    private const DAYS_BEFORE_KEY = 'notify_multiday_events_days_before';
    /**
     * When the "your activity is tomorrow" reminder goes out, the evening
     * before. A fixed hour, not a setting: the calendar page configures
     * exactly one notification — the multi-day reminder — and a second
     * configurable one that no page ever exposed only produced a stray
     * editable row on Configuration > Réglages (see the module's own
     * help topic, "Les rappels"). 18:00 is the value that setting shipped
     * with, kept as-is so no installation's reminders move.
     */
    private const REMINDER_HOUR = '18:00';

    public function __construct(
        private SchedulerService $schedulerService,
        private SettingService $settingService,
        private CalendarService $calendarService,
        private CalendarEventRepository $eventRepository,
        private ?NotificationService $notificationService = null,
        /**
         * Who an activity is announced to (issue #1348) — the same answer
         * the evening-before reminder resolves. Optional so the many narrow
         * constructions keep working; without it the announcements are
         * no-ops, never a wider audience.
         */
        private ?EventAudienceResolver $audience = null
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
        $eventStart = DateInput::requireFromStorage($event->startDate, 'calendar_events.start_date');
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

    /**
     * Notification centre + push: "a new activity was added" — to the
     * people the calendar itself is visible to (see recipientIdsFor()).
     * dispatch() re-checks role_min per recipient, and never pushes to
     * the acting chief who created the event (the row still appears in
     * their own centre).
     */
    public function dispatchEventPublished(CalendarEvent $event, ?int $actorUserAccountId): void
    {
        $this->dispatchEventNotification('calendar.event_published', $event, 'Nouvelle activité', $actorUserAccountId);
    }

    /**
     * « Activité modifiée » for $after, the event as stored once the edit
     * is saved — only when the edit changed something a family acts on
     * (see isVisibleChange()) and the event, as it now stands, is not past
     * (dispatchEventNotification()). The two rules compose into the moves
     * between past and future (issue #1477):
     *
     *  - moved from the past into the future: its date changed and it is
     *    now upcoming, so it is announced — a family needs to hear of it;
     *  - moved from the future into the past: it is now over, so nothing
     *    goes out, as for any edit of a past event.
     */
    public function dispatchEventChanged(
        CalendarEvent $before,
        CalendarEvent $after,
        ?int $actorUserAccountId
    ): void {
        if (!$this->isVisibleChange($before, $after)) {
            return;
        }
        $this->dispatchEventNotification('calendar.event_changed', $after, 'Activité modifiée', $actorUserAccountId);
    }

    /**
     * Whether an edit changed what a family sees of the activity and plans
     * around: when (start and end date, start and end time), where
     * (location), what it is called (title) and whose activity it is (the
     * calendar, i.e. the section). These are exactly the fields the
     * notification itself or the calendar grid show at a glance.
     *
     * Deliberately NOT counted (issue #1477): the description — a free
     * text that is reworded, completed or corrected long after the event
     * is announced, where a push for every typo fix trains families to
     * ignore the channel — and the automatic-retrospective checkbox
     * (auto_create_retro), a switch nobody outside the staff ever sees.
     *
     * A missing end date means "the same day", so an end date added equal
     * to the start date (or removed while it was equal) is no change.
     * Both sides are rows read back from the database, so the dates and
     * times are compared in the one format the column returns.
     */
    public function isVisibleChange(CalendarEvent $before, CalendarEvent $after): bool
    {
        return $before->calendarId !== $after->calendarId
            || $before->title !== $after->title
            || $before->startDate !== $after->startDate
            || ($before->endDate ?? $before->startDate) !== ($after->endDate ?? $after->startDate)
            || $before->startTime !== $after->startTime
            || $before->endTime !== $after->endTime
            || $before->location !== $after->location;
    }

    /**
     * Whether the event is entirely over: its last day (end date, else its
     * start date) is before today. The same notion of "upcoming" the module
     * already uses everywhere — CalendarEventRepository::findUpcoming() and
     * searchUpcomingEvents() keep `COALESCE(end_date, start_date) >= today`
     * — on the application clock (Core\Config\AppClock, Brussels). It is
     * a matter of days, not of hours, so an event that ends today is still
     * announced even after its end time: an all-day event has no time to
     * compare, and a correction made the same evening is still news to the
     * families who were there.
     */
    public function isPast(CalendarEvent $event): bool
    {
        $lastDay = DateInput::requireFromStorage(
            $event->endDate ?? $event->startDate,
            'calendar_events.end_date'
        )->format('Y-m-d');

        return $lastDay < (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function dispatchEventNotification(
        string $typeId,
        CalendarEvent $event,
        string $title,
        ?int $actorUserAccountId
    ): void
    {
        if ($this->notificationService === null || $this->audience === null) {
            return;
        }
        // Nothing is announced about an activity that is already over: a
        // leader recording a past meeting after the fact, or correcting a
        // finished one, is bookkeeping, not news (issue #1477). The two
        // scheduled reminders skip the past the same way, and the import
        // announces nothing at all.
        if ($this->isPast($event)) {
            return;
        }

        $recipients = array_map(
            static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
            $this->audience->accountIdsFor($this->calendarService->findById($event->calendarId), $event)
        );
        if ($recipients === []) {
            return;
        }

        // The event's own title alone is frequently ambiguous — several
        // sections often reuse the exact same generic title ("Réunion
        // normale") — so every calendar notification names which calendar
        // it's about, via CalendarService::labelsByCalendarId() (the
        // single source of truth for "what do we call this calendar",
        // same label the calendar picker and event bars themselves show).
        $calendarLabel = $this->calendarService->labelsByCalendarId()[$event->calendarId] ?? 'Calendrier';

        $this->notificationService->dispatch($typeId, $recipients, [
            'title' => $title,
            'body' => $calendarLabel . ' — ' . $event->title,
            'url' => '/calendar',
        ], $actorUserAccountId);
    }

    /**
     * Schedules the "your activity is tomorrow" reminder (module.json's
     * "calendar.event_reminder" type) for the day before the event, at
     * REMINDER_HOUR — replacing any previously scheduled reminder
     * for this event, so re-saving an event never leaves a stale
     * duplicate behind (same idempotent-reference pattern as
     * syncReminderForEvent() above). A reminder already in the past (the
     * event starts tomorrow or sooner) is skipped rather than
     * back-dated.
     */
    public function syncActivityReminderForEvent(CalendarEvent $event): void
    {
        $this->cancelActivityReminderForEvent($event->id);

        if ($this->notificationService === null) {
            return;
        }

        $now = new \DateTimeImmutable();
        $eventStart = DateInput::requireFromStorage($event->startDate, 'calendar_events.start_date');
        [$hour, $minute] = array_map('intval', explode(':', self::REMINDER_HOUR));
        $runAt = $eventStart->modify('-1 day')->setTime($hour, $minute);

        if ($runAt <= $now) {
            return;
        }

        $this->schedulerService->schedule(
            self::MODULE_ID,
            self::REMINDER_TASK_KEY,
            $runAt,
            ['event_id' => $event->id],
            $this->reminderReferenceFor($event->id)
        );
    }

    public function cancelActivityReminderForEvent(int $eventId): void
    {
        $existing = $this->schedulerService->find(
            self::MODULE_ID,
            self::REMINDER_TASK_KEY,
            $this->reminderReferenceFor($eventId)
        );
        if ($existing !== null && $existing['status'] === 'pending') {
            $this->schedulerService->cancel((int) $existing['id']);
        }
    }

    private function reminderReferenceFor(int $eventId): string
    {
        return 'event-reminder-' . $eventId;
    }
}
