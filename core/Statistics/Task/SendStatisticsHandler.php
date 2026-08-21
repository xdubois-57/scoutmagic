<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Statistics\StatisticsSenderFactoryInterface;
use Core\Statistics\StatisticsServiceFactory;

/**
 * The daily usage-statistics report (`core`/`send_statistics`, reference
 * `daily`) — ARCHITECTURE.md §8.47.
 *
 * Self-rescheduling, like every other recurring task in this codebase
 * (Core\Scheduler has no first-class recurring concept — see
 * Core\Notification\Task\PurgeNotificationsHandler). The reschedule happens
 * in a `finally`, **whatever the outcome**, and that single detail carries
 * two rules at once: a failed report is never retried the same day (no
 * hammering a receiver that is down), and a site that skipped for weeks
 * resumes on its own the moment the reason disappears.
 *
 * There is deliberately no catch-up: a period spent disabled produces no
 * retroactive report, ever.
 */
class SendStatisticsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'send_statistics';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    /**
     * Test seam only: production always builds the real sender from the
     * task context.
     */
    public function __construct(private ?StatisticsSenderFactoryInterface $senderFactory = null)
    {
    }

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $sender = $this->senderFactory !== null
                ? $this->senderFactory->create($context)
                : StatisticsServiceFactory::sender($context);

            $sender->send();
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
            $scheduler->scheduleAfter('core', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
        }
    }
}
