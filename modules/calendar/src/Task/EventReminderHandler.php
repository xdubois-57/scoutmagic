<?php

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Calendar\Repository\CalendarEventRepository;

/**
 * Sends the "your activity is tomorrow" notification-centre/push
 * reminder (module.json's "calendar.event_reminder" type) — payload is
 * exactly what Service\CalendarNotificationService::
 * syncActivityReminderForEvent() scheduled: `event_id`. Re-resolves the
 * event fresh at send time rather than trusting anything computed at
 * scheduling time, since the reminder can be scheduled weeks in advance.
 *
 * A fresh set of services is built from TaskContext on every run — task
 * handlers have no persistent DI container (see docs/module-development.md).
 */
class EventReminderHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        if ($context->notifications === null) {
            return;
        }

        $pdo = $context->connection->getPdo();

        $eventId = isset($payload['event_id']) ? (int) $payload['event_id'] : 0;
        $eventRepository = new CalendarEventRepository($pdo);
        $event = $eventRepository->findById($eventId);
        if ($event === null) {
            return; // deleted since scheduling
        }

        $recipients = array_map(
            static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
            $context->userAccounts->findAllIds()
        );
        if ($recipients === []) {
            return;
        }

        $context->notifications->dispatch('calendar.event_reminder', $recipients, [
            'title' => 'Rappel d\'activité',
            'body' => "L'activité « {$event->title} » a lieu demain.",
            'url' => '/calendar',
        ]);
    }
}
