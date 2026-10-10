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
     * @param list<DeskCsvRow> $rows every data line as read, Tiers or not —
     *        what {@see DeskImportValidator} checks before anything is
     *        written. Empty for an import built by hand in a test.
     * @param int $headerCount how many cells the header line carried
     */
    public function __construct(
        public readonly array $members,
        public readonly int $lineCount,
        public readonly array $rows = [],
        public readonly int $headerCount = 0
    ) {
    }
}
