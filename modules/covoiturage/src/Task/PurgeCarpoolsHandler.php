<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Task;

use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Service\CarpoolBoard;

/**
 * Deletes every carpool whose last date is further back than the retention
 * (`covoiturage_retention_days`, 30 by default) — its offers, requests,
 * names and phone numbers with it, by cascade.
 *
 * **Display and retention are one thing (D9).** The list folds the past
 * away and shows it only as far back as this task keeps it: what the list
 * no longer shows has no reason left to exist, and this page must never
 * become a register of the families' journeys.
 *
 * Re-arms itself daily through rearm(), never schedule() — the guard that
 * keeps one chain alive (Tests\Architecture\RecurringTasksRearmTest).
 */
class PurgeCarpoolsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_carpools';
    public const REFERENCE = 'daily';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $days = max(
            1,
            (int) ($context->settings->get(
                CarpoolBoard::RETENTION_SETTING,
                'covoiturage',
                (string) CarpoolBoard::DEFAULT_RETENTION_DAYS
            ) ?? CarpoolBoard::DEFAULT_RETENTION_DAYS)
        );
        $cutoff = (new \DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');

        $deleted = (new CarpoolRepository($pdo))->deleteEndedBefore($cutoff);
        if ($deleted > 0) {
            // A count and nothing else: which outing it was is exactly what
            // this task exists to forget.
            $context->journal->log(
                'covoiturage',
                'carpools_purged',
                'info',
                sprintf('%d covoiturage(s) effacé(s), %d jours après leur dernière date.', $deleted, $days),
                ['count' => $deleted, 'retention_days' => $days]
            );
        }

        SchedulerService::forPdo($pdo)->rearm(
            'covoiturage',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('tomorrow 03:40')
        );
    }
}
