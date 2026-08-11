<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

final class BulkCategorizationResult
{
    public function __construct(
        public readonly int $categorizedByRules,
        public readonly int $categorizedByAi,
        public readonly int $stillUncategorized
    ) {
    }
}
