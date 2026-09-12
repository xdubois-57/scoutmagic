<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification\Task;

use Core\Notification\NotificationMailerFactory;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Fan-out for the email half of Core\Notification\NotificationService::
 * dispatch(), the exact shape of its push sibling
 * (SendNotificationsHandler) and for the same reason: a mailing to a
 * section is hundreds of SMTP round trips, none of which belong in the
 * request that triggered the notification.
 *
 * The time budget is smaller than push's. Push flushes one batched
 * curl_multi at the end, so its 20 s covers mostly queueing; here every
 * message is its own connection to a mail transport that may be a remote
 * SMTP server, so the budget has to leave room for the slowest few rather
 * than assume the average. Whatever is left over is rescheduled
 * immediately (delay 0) for the next scheduler tick, never dropped.
 */
class SendNotificationEmailsHandler implements TaskHandlerInterface
{
    private const TIME_BUDGET_SECONDS = 15;

    public function handle(array $payload, TaskContext $context): void
    {
        // No NotificationService on this context (e.g. cron running before
        // the site has ever been reached over HTTP) — the same
        // degrade-silently precedent every other handler using
        // TaskContext::$notifications follows. The rows keep their NULL
        // email_sent_at, so a later run still sends them.
        if ($context->notifications === null) {
            return;
        }

        $notificationIds = array_map('intval', (array) ($payload['notification_ids'] ?? []));
        if ($notificationIds === []) {
            return;
        }

        // Asked for rather than built, and the reason it was built here
        // survives the change: `public/index.php` and `public/cron.php`
        // must not end up with different answers (§8.17's create_backup
        // lesson). What guarantees that is ONE construction site, not
        // this particular one — and an immediate e-mail (issue #296)
        // needs a mailer where no handler is running, so the site moved
        // into Core\Notification\NotificationMailerFactory, which that
        // path and this one both ask. Its docblock carries the rest,
        // including why the renderer knows the core registry only.
        $mailer = (new NotificationMailerFactory(
            $context->mailService,
            $context->connection->getPdo(),
            $context->settings,
            $context->journal
        ))->create();

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $attempted = $context->notifications->sendEmailsForNotifications(
            $notificationIds,
            static fn (): bool => microtime(true) < $deadline,
            $mailer
        );

        $remaining = array_values(array_diff($notificationIds, $attempted));
        if ($remaining === []) {
            return;
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('core', 'send_notification_emails', 0, ['notification_ids' => $remaining]);
    }
}
