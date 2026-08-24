<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\ReviewNotificationService;

/**
 * Sends the "leave a review" notification for every stay that has ended
 * and never been told about, then re-schedules itself for tomorrow.
 *
 * Self-rescheduling rather than a fixed cron entry, same as
 * Core\Maintenance\Task\AutoBackupHandler: this site runs on shared
 * hosting where a real cron may not exist, and the scheduler is driven by
 * ordinary page loads. The daily rhythm is therefore a target, not a
 * guarantee — which is exactly why "who still needs telling" is answered
 * by a column on the stay and not by today's date.
 */
class ReviewReminderHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'review_reminder';
    public const REFERENCE = 'camps_review_reminder';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $service = new ReviewNotificationService(
            new CampRepository($pdo, $context->encryption),
            new SectionService($context->connection, $context->encryption, new MemberBadgeRepository($pdo)),
            $context->userAccounts,
            $context->encryption,
            $pdo,
            $context->notifications
        );

        $sent = $service->dispatchDue(
            new \DateTimeImmutable('today'),
            (string) ($context->settings->get('base_url') ?? '')
        );

        if ($sent > 0) {
            $context->journal->log(
                'camps',
                'review_notifications_sent',
                'info',
                sprintf('Invitation à laisser un avis envoyée pour %d séjour(s).', $sent)
            );
        }

        $this->rescheduleTomorrow($pdo);
    }

    /**
     * Re-arms itself for tomorrow morning, and only if nothing already
     * is: the scheduler is driven by page loads, so two visitors can run
     * this within the same second and would otherwise queue two identical
     * tasks — and then four.
     */
    private function rescheduleTomorrow(\PDO $pdo): void
    {
        $scheduler = new SchedulerService(new \Core\Scheduler\SchedulerRepository($pdo));
        if ($scheduler->find('camps', self::TASK_KEY, self::REFERENCE) !== null) {
            return;
        }

        $scheduler->schedule(
            'camps',
            self::TASK_KEY,
            new \DateTimeImmutable('tomorrow 06:00'),
            [],
            self::REFERENCE
        );
    }
}
