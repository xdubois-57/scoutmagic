<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * One imported mail-merge Excel file (mass_mail_audiences) — the file
 * itself is deleted right after parsing, this row and its
 * mass_mail_audience_rows are all that remains.
 */
final class Audience
{
    /**
     * @param string[] $columns Ordered column headers of the imported sheet — the merge variables.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $sourceFilename,
        public readonly string $sheetName,
        public readonly array $columns,
        public readonly int $rowCount,
        public readonly string $createdAt,
        public readonly ?int $createdBy
    ) {
    }
}
