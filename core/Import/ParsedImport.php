<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class ParsedImport
{
    /**
     * @param ParsedMember[] $members
     */
    public function __construct(
        public readonly array $members,
        public readonly int $lineCount
    ) {
    }
}
