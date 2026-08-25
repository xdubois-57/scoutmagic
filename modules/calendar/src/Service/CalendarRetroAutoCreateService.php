<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Scheduler\SchedulerService;
use Core\Service\DateInput;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Retro\Api\RetroEventLinkLookupInterface;

/**
 * Schedules Task\AutoCreateRetroHandler to run at an event's own start
 * time when its auto_create_retro flag is set — same schedule/cancel-on-
 * save pattern as CalendarNotificationService::syncReminderForEvent(), one
 * task per event, reference stable across edits so re-saving never leaves
 * a stale duplicate behind. Optional dependency on the retro module
 * (Api\RetroEventLinkLookupInterface, implemented by
 * Modules\Retro\Service\BoardService) purely to skip scheduling when a
 * board is already linked — retro being entirely absent behaves the same
 * as "nothing linked yet".
 */
class CalendarRetroAutoCreateService
{
    public const MODULE_ID = 'calendar';
    public const TASK_KEY = 'auto_create_retro';

    public function __construct(
        private SchedulerService $schedulerService,
        private ?RetroEventLinkLookupInterface $retroEventLinkLookup = null
    ) {
    }

    /**
     * Call after creating or updating an event — (re)computes whether an
     * auto-create task is due and reschedules it, cancelling any
     * previously scheduled one first.
     */
    public function syncAutoCreateForEvent(CalendarEvent $event): void
    {
        $this->cancelAutoCreateForEvent($event->id);

        if (!$event->autoCreateRetro) {
            return;
        }
        if ($this->retroEventLinkLookup !== null && $this->retroEventLinkLookup->hasLinkedBoard($event->id)) {
            return;
        }

        $startAt = DateInput::requireFromStorage(
            $event->startTime !== null
                ? $event->startDate . ' ' . $event->startTime
                : $event->startDate,
            'calendar_events.start_date'
        );

        $now = new \DateTimeImmutable();
        $runAt = $startAt > $now ? $startAt : $now->modify('+5 minutes');

        $this->schedulerService->schedule(
            self::MODULE_ID,
            self::TASK_KEY,
            $runAt,
            ['event_id' => $event->id],
            $this->referenceFor($event->id)
        );
    }

    /**
     * Call after deleting an event (or before, order doesn't matter — only
     * the event id is needed) to cancel any pending auto-create task.
     */
    public function cancelAutoCreateForEvent(int $eventId): void
    {
        $existing = $this->schedulerService->find(self::MODULE_ID, self::TASK_KEY, $this->referenceFor($eventId));
        if ($existing !== null && $existing['status'] === 'pending') {
            $this->schedulerService->cancel((int) $existing['id']);
        }
    }

    private function referenceFor(int $eventId): string
    {
        return 'event-' . $eventId;
    }
}
