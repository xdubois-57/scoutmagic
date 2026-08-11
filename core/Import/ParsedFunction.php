<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class ParsedFunction
{
    public function __construct(
        public readonly string $functionCode,
        public readonly ?string $branchCode,
        public readonly ?string $sectionCode,
        public readonly ?string $sectionName,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $mandateEnd,
        public readonly bool $isMainFunction
    ) {
    }
}
