<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Task;

use Core\Config\ScoutYearService;
use Core\Import\MemberYearRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\ScoutYear\ScoutYearResolver;
use Modules\SosStaff\Service\RedirectReconciler;

/**
 * The SOS line checked against the planning, every hour, and in quick
 * retries after a failure (issue #1102).
 *
 * A scheduled transition that failed used to stay failed: the line rang
 * for the previous holder until the next handover or until somebody
 * pressed « Resynchroniser » on a page nobody had a reason to open. And
 * nothing at all noticed a line moved by something other than this site.
 * RedirectReconciler reads where the line really is and corrects it.
 *
 * Two chains under one task key:
 *
 *  - `hourly`, seeded by bootstrap() and re-armed by every run of it;
 *  - `retry`, armed by a failure — of a scheduled transition
 *    (ApplyRedirectHandler) or of a check — with `attempt` in its payload:
 *    5, 15 then 60 minutes later, then the hourly chain carries on alone.
 *
 * A failure is journaled, recorded for the urgent attention point and
 * alerted to the chefs d'unité inside RedirectService::apply(), once per
 * outage; the attention point stays up until the line reads back right.
 */
final class ReconcileRedirectHandler implements TaskHandlerInterface
{
    public const MODULE_ID = 'sos_staff';
    public const TASK_KEY = 'reconcile_redirect';
    public const REFERENCE_HOURLY = 'hourly';
    public const REFERENCE_RETRY = 'retry';
    public const INTERVAL_SECONDS = 3600;
    /** Delay before retry n (1-based): 5, 15, 60 minutes. */
    public const RETRY_DELAYS = [300, 900, 3600];

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $scheduler = SchedulerService::forPdo($pdo);
        $attempt = isset($payload['attempt']) ? (int) $payload['attempt'] : 0;

        try {
            // The year the site is ON — never a preview, which only ever
            // lives in somebody's session (ARCHITECTURE.md, scout years).
            $scoutYearId = (new ScoutYearResolver(
                new ScoutYearService($pdo),
                $context->settings,
                new MemberYearRepository($pdo)
            ))->getAuthorizationYear()->id;

            $result = SosTaskServices::build($context)->reconciler->reconcile(
                $scoutYearId,
                $attempt > 0 ? 'retry' : 'hourly'
            );

            if (in_array($result['status'], [RedirectReconciler::FAILED, RedirectReconciler::UNREADABLE], true)) {
                self::scheduleRetry($scheduler, $attempt + 1);
            }
        } finally {
            // Unconditionally, and only for the hourly chain: a retry is
            // not a link of it (see PurgeCampaignFilesHandler for why
            // this is rearm and not bootstrap()).
            if ($attempt === 0) {
                $scheduler->rearmAfter(self::MODULE_ID, self::TASK_KEY, self::REFERENCE_HOURLY, self::INTERVAL_SECONDS);
            }
        }
    }

    /**
     * Arms retry number $attempt, unless it is past the last one or a
     * retry is already pending (rearm()'s guard: two failures in a row do
     * not start two chains).
     */
    public static function scheduleRetry(SchedulerService $scheduler, int $attempt = 1): void
    {
        if ($attempt < 1 || $attempt > count(self::RETRY_DELAYS)) {
            return;
        }

        $scheduler->rearmAfter(
            self::MODULE_ID,
            self::TASK_KEY,
            self::REFERENCE_RETRY,
            self::RETRY_DELAYS[$attempt - 1],
            ['attempt' => $attempt]
        );
    }

    /**
     * Called from the composition root, so on every request — see
     * SchedulerService::seed() for why that must not be rearm().
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->seedAfter(self::MODULE_ID, self::TASK_KEY, self::REFERENCE_HOURLY, self::INTERVAL_SECONDS);
    }
}
