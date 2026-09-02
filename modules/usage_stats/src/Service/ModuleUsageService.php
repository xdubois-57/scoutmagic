<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Service;

use Modules\UsageStats\Api\ModuleUsage;
use Modules\UsageStats\Api\ModuleUsageInterface;
use Modules\UsageStats\Month;
use Modules\UsageStats\Repository\PageViewRepository;

/**
 * The module's published capability (Api\ModuleUsageInterface): the
 * per-module aggregate the daily usage report carries, and that the
 * support package's `statistics.json` therefore carries too — one builder,
 * both paths, which is what keeps them from diverging at the first change.
 */
class ModuleUsageService implements ModuleUsageInterface
{
    /**
     * @param ?\DateTimeImmutable $now injectable only so a test can name a
     *        month out loud; production always leaves it null.
     */
    public function __construct(
        private PageViewRepository $pageViews,
        private ?\DateTimeImmutable $now = null
    ) {
    }

    /**
     * @return list<ModuleUsage>
     */
    public function aggregatedByModule(): array
    {
        $to = Month::current($this->now);
        $from = Month::shift($to, -(self::WINDOW_MONTHS - 1));

        $usage = [];
        foreach ($this->pageViews->viewsPerModule($from, $to) as $moduleId => $views) {
            // `core` is a module id in the counters — every route the
            // application itself declares is filed under it — but it is
            // not a module, and a receiver aggregating « adoption des
            // modules » would have to special-case it on every row.
            if ($moduleId === PageViewRecorder::CORE_MODULE_ID || $views <= 0) {
                continue;
            }
            $usage[] = new ModuleUsage($moduleId, $views);
        }

        usort($usage, static fn(ModuleUsage $a, ModuleUsage $b): int => strcmp($a->moduleId, $b->moduleId));

        return $usage;
    }
}
