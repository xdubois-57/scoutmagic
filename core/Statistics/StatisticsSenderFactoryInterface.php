<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Scheduler\TaskContext;

/**
 * Lets Core\Statistics\Task\SendStatisticsHandler be tested against a
 * sender that never touches the network or the filesystem.
 *
 * The handler is built by SchedulerRunner with a bare `new`, so its only
 * injection point is a nullable constructor parameter defaulting to the
 * real factory — the same shape as Core\Maintenance\BackupServiceInterface
 * on FullResetHandler, and for the same reason.
 */
interface StatisticsSenderFactoryInterface
{
    public function create(TaskContext $context): StatisticsSender;
}
