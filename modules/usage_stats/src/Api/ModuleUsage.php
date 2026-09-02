<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Api;

/**
 * How much one module was opened, over the window
 * {@see ModuleUsageInterface::WINDOW_MONTHS} names.
 *
 * The whole value object, and it is deliberately this thin: an id and a
 * count. No page, no route, no audience, no month — the question the
 * project needs answered is « quels modules servent dans les unités », not
 * « combien de fois telle unité a ouvert son calendrier ».
 */
final class ModuleUsage
{
    public function __construct(
        public readonly string $moduleId,
        public readonly int $views
    ) {
    }
}
