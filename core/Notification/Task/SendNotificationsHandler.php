<?php

declare(strict_types=1);

namespace Core\Notification\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Fan-out for Core\Notification\NotificationService::dispatch()'s push
 * work — never sent synchronously within the request that triggered a
 * notification (module spec: "200 members × 2 devices is 400 HTTPS
 * calls"). $payload['notification_ids'] is the batch dispatch() computed
 * for one (quiet-hours-adjusted) target time; a time budget bounds how
 * many get attempted this run — NotificationService::sendPushForNotifications()
 * itself batches the actual network round trip via Minishlink\WebPush's
 * flush(), the "curl_multi in batches of ~20" the module spec asks for.
 * Any ids left over when the budget runs out are rescheduled immediately
 * (delay 0) for the scheduler's next tick rather than lost.
 */
class SendNotificationsHandler implements TaskHandlerInterface
{
    private const TIME_BUDGET_SECONDS = 20;

    public function handle(array $payload, TaskContext $context): void
    {
        // VAPID keys not provisioned yet (e.g. cron running before the
        // site has ever been reached over HTTP, where they're self-
        // healed) — same degrade-silently precedent as every other
        // handler using TaskContext::$notifications.
        if ($context->notifications === null) {
            return;
        }

        $notificationIds = array_map('intval', (array) ($payload['notification_ids'] ?? []));
        if ($notificationIds === []) {
            return;
        }

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $attempted = $context->notifications->sendPushForNotifications(
            $notificationIds,
            static fn(): bool => microtime(true) < $deadline
        );

        $remaining = array_values(array_diff($notificationIds, $attempted));
        if ($remaining === []) {
            return;
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('core', 'send_notifications', 0, ['notification_ids' => $remaining]);
    }
}
