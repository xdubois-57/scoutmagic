<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\SupportDashboardService;

/**
 * Retention for the receiver's installation records
 * (`support_dashboard`/`purge_installations`, daily, self-rescheduling —
 * same shape as `PurgeRateLimitsHandler`).
 *
 * Past `support_retention_months` with no accepted report, the record goes
 * **in its entirety**: installation id, instance URL, last payload,
 * reception metadata, and the credential hash. Nothing is kept "just in
 * case" — a receiver holding the identity of installations that stopped
 * existing half a year ago is holding it for no reason anyone could state.
 *
 * **Finalised monthly aggregates are never touched** (ARCHITECTURE.md
 * §8.51). An aggregate is a count of distinct installations for a month
 * that has ended; rewriting history because a contributor later disappeared
 * would make the series mean nothing.
 *
 * One consequence is accepted rather than worked around: an installation
 * silent for longer than the retention window that starts reporting again
 * is a **new registration**, because its id is no longer known and
 * trust-on-first-use applies again (ARCHITECTURE.md §8.49). It reappears as
 * itself, with its own history restarted — which is the honest description
 * of what the receiver actually knows about it.
 */
class PurgeInstallationsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_installations';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $pdo = $context->connection->getPdo();
            $installations = new SupportInstallationRepository($pdo);
            $months = (new SupportDashboardService($installations, $context->settings))->retentionMonths();

            $cutoff = (new \DateTimeImmutable('-' . $months . ' months'))->format('Y-m-d H:i:s');
            $expired = $installations->findIdsLastReceivedBefore($cutoff);

            foreach ($expired as $id) {
                $installations->delete($id);
            }

            if ($expired !== []) {
                // Count only: which installations were dropped is not
                // something a journal entry needs to carry forward.
                $context->journal->log(
                    'support_dashboard',
                    'support_installations_purged',
                    'info',
                    count($expired) . ' installation(s) supprimée(s) après ' . $months . ' mois sans rapport.',
                    ['count' => count($expired), 'retention_months' => $months]
                );
            }
        } finally {
            // Rescheduled whatever happened, exactly like the other daily
            // tasks: a purge that threw must not be a purge that stops.
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->scheduleAfter('support_dashboard', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
        }
    }
}
