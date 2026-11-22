<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Runs a single scheduled redirect transition (module spec §3/§4). The
 * payload is what Service\OnCallService::saveMonth() scheduled — `date`,
 * `member_id` (null = default number), `previous_member_id`, `scout_year_id`
 * — but only `previous_member_id` is still used, and only to word the « fin
 * de garde » notice.
 *
 * **The destination is not the payload's** (issue #1260). `member_id` and
 * `scout_year_id` were frozen when the month was saved; if the planning or
 * the roster changed since without the month being saved again, applying
 * them put the line where it no longer belonged until the hourly check
 * corrected it, and a payload written before #1102 can carry a preview year.
 * The transition asks the same function the hourly check and the dashboard
 * ask — RedirectReconciler::expectedDestination() — for the planning and
 * the roster AS THEY ARE when it runs, in the year the SOS pages work in.
 *
 * A fresh set of services is built from TaskContext on every run
 * (SosTaskServices). A failure arms the reconciler's quick retries
 * (ReconcileRedirectHandler, issue #1102) before it is re-thrown.
 */
class ApplyRedirectHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        $services = SosTaskServices::build($context);

        $previousMemberId = isset($payload['previous_member_id'])
            ? (int) $payload['previous_member_id']
            : null;
        $scoutYearId = SosTaskServices::operationalYearId($context);
        $memberId = $services->reconciler->expectedDestination($scoutYearId)['member_id'];

        try {
            $services->redirectService->apply($memberId, $previousMemberId, $scoutYearId);
        } catch (\Modules\SosStaff\Service\SosException $e) {
            // The transition stays `failed` in « Actions planifiées », as it
            // must; what changes (issue #1102) is that the line is no longer
            // left wherever it was until the next handover. The reconciler
            // tries again in 5, 15 and 60 minutes, then the hourly check
            // takes over.
            ReconcileRedirectHandler::scheduleRetry(
                \Core\Scheduler\SchedulerService::forPdo($context->connection->getPdo())
            );
            throw $e;
        }
    }
}
