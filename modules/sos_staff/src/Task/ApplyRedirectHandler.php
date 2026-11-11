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
 * Runs a single scheduled redirect transition (module spec §3/§4) — the
 * payload is exactly what Service\OnCallService::saveMonth() scheduled:
 * `date`, `member_id` (null = default number), `previous_member_id`, and
 * `scout_year_id` (stored at scheduling time rather than re-derived here,
 * so it can never drift from what was actually being viewed/saved).
 *
 * A fresh set of services is built from TaskContext on every run
 * (SosTaskServices). A failure arms the reconciler's quick retries
 * (ReconcileRedirectHandler, issue #1102) before it is re-thrown.
 */
class ApplyRedirectHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        $redirectService = SosTaskServices::build($context)->redirectService;

        $memberId = isset($payload['member_id']) ? (int) $payload['member_id'] : null;
        $previousMemberId = isset($payload['previous_member_id'])
            ? (int) $payload['previous_member_id']
            : null;
        $scoutYearId = (int) ($payload['scout_year_id'] ?? 0);

        try {
            $redirectService->apply($memberId, $previousMemberId, $scoutYearId);
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
