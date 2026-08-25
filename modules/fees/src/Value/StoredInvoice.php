<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/** One invoice as the site kept it, without its lines. */
final class StoredInvoice
{
    public function __construct(
        public readonly int $id,
        public readonly int $scoutYearId,
        public readonly string $documentNumber,
        public readonly ?string $issueDate,
        public readonly int $totalCents,
        public readonly ?string $structuredCommunication,
        public readonly ?string $templateNumber,
        public readonly int $ignoredRowCount,
        public readonly ?int $snapshotId,
        public readonly \DateTimeImmutable $importedAt,
        public readonly ?int $financeFileId
    ) {
    }
}
