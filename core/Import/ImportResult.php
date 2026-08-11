<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class ImportResult
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly int $memberCount,
        public readonly int $lineCount,
        public readonly int $newFunctionsCount,
        public readonly array $warnings
    ) {
    }
}
