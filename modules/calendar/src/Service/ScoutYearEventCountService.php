<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Modules\Calendar\Api\ScoutYearEventCountProvider;
use Modules\Calendar\Repository\CalendarEventRepository;

/**
 * The module's implementation of its own Api\ScoutYearEventCountProvider
 * (ARCHITECTURE.md §7.5) — wired nullable into Core\ScoutYear\
 * ScoutYearTransitionService by the composition root, only when this
 * module is enabled. A thin pass-through: the count is a plain read with
 * no service-layer logic in front of it.
 */
class ScoutYearEventCountService implements ScoutYearEventCountProvider
{
    public function __construct(private CalendarEventRepository $repository)
    {
    }

    public function countEventsBetween(string $fromDate, string $toDate): int
    {
        return $this->repository->countStartingBetween($fromDate, $toDate);
    }
}
