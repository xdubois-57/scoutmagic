<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification\Task;

use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Notification\NotificationMailer;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\View\TwigFactory;

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

        // Built here rather than injected, so `public/index.php` and
        // `public/cron.php` cannot end up with different answers — the
        // same reason Modules\Calendar's reminder handler builds its own
        // (§8.17's create_backup lesson). Core templates only: a
        // notification's email body is core's, whatever module declared
        // the type.
        // The renderer is built here too, and deliberately with the CORE
        // registry only: a notification's e-mail body is core's, whatever
        // module declared the type, so there is no module manifest to
        // aggregate on this path. A customisation of it is still honoured
        // — that lives in the database, not in a manifest.
        $mailer = new NotificationMailer(
            $context->mailService,
            new EmailTemplateRenderer(
                TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates'),
                new EmailTemplateRegistry(),
                new EmailTemplateOverrideRepository($context->connection->getPdo()),
                $context->journal
            ),
            (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
            (string) ($context->settings->get('base_url') ?? '')
        );

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
