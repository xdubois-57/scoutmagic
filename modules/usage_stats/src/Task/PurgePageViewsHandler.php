<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\UsageStats\Repository\PageViewRepository;
use Modules\UsageStats\Retention;

/**
 * Retention for the usage counters (`usage_stats`/`purge_page_views`,
 * daily, self-rescheduling — same shape as
 * Modules\SupportDashboard\Task\PurgeInstallationsHandler).
 *
 * Past three scout years (Modules\UsageStats\Retention) a month's counters
 * go. Writing the duration down is the point: a table nobody purges is a
 * table kept for ever, and « for ever » is a retention decision made by
 * omission rather than by anyone.
 *
 * Nothing is anonymised on the way out because nothing was ever nominative
 * on the way in — a deleted row is a count of page openings and no more.
 */
class PurgePageViewsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_page_views';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $cutoff = Retention::cutoffMonth(new \DateTimeImmutable('now'));
            $deleted = (new PageViewRepository($context->connection->getPdo()))->deleteMonthsBefore($cutoff);

            if ($deleted > 0) {
                $context->journal->log(
                    'usage_stats',
                    'usage_page_views_purged',
                    'info',
                    $deleted . ' compteur(s) de fréquentation supprimé(s), antérieurs à ' . $cutoff . '.',
                    ['count' => $deleted, 'cutoff_month' => $cutoff]
                );
            }
        } finally {
            // Rescheduled whatever happened: a purge that threw must not be
            // a purge that stops.
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter('usage_stats', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
