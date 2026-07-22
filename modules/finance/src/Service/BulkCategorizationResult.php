<?php

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
