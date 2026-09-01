<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Scheduler\TaskContext;

/**
 * "Tell whoever asked for this maintenance operation how it went."
 *
 * One behaviour with several call sites: a background maintenance task
 * (restoring a backup, resetting the settings) finishes, well or badly,
 * and the one person who pressed the button has to hear about it. Every
 * such notice is the same shape — a declared type, one recipient, and a
 * link back to Configuration > Maintenance, where the outcome is visible.
 *
 * It is a **declared type through NotificationService::dispatch()**, never
 * the type-less notify(). That is the whole point of this class existing:
 * backup CREATION already dispatched declared types while the two
 * operations that undo one used notify(), so their notices carried no
 * type, consulted no preference, and left the administrator no row on
 * /notifications/preferences to switch off. Six call sites, two copies of
 * the same helper — which is what this replaces.
 *
 * **Why a static taking the TaskContext rather than an injected
 * collaborator**: a task handler is auto-resolved with `new
 * $handlerClass()` (Core\Scheduler\SchedulerRunner, Core\Scheduler\
 * TaskCapabilities), so it has no constructor to inject anything into and
 * receives every dependency it will ever have through TaskContext. That
 * is exactly the shape Core\Statistics\StatisticsServiceFactory already
 * has, for exactly the same reason.
 *
 * Nobody to tell is a no-op, not an error: an automatic run has no
 * requester at all (`update_history.requested_by`/the task payload's
 * `requested_by_user_account_id` is null, §8.16), and a scheduler built
 * without the notification stack hands over a null $context->notifications.
 */
final class RequesterNotice
{
    /** Where every maintenance outcome is visible, whatever the operation. */
    private const MAINTENANCE_URL = '/config/maintenance';

    public static function send(
        TaskContext $context,
        ?int $requestedBy,
        string $typeId,
        string $title,
        string $body
    ): void {
        if ($requestedBy === null) {
            return;
        }

        $context->notifications?->dispatch(
            $typeId,
            [['userAccountId' => $requestedBy, 'memberId' => null]],
            ['title' => $title, 'body' => $body, 'url' => self::MAINTENANCE_URL]
        );
    }
}
