<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/**
 * One stored invoice line, with the member ids of the people it billed.
 *
 * `$unmatchedPeopleCount` is how many of its names the site could not tie
 * to a member. The names themselves are not here and never were — the
 * table holds foreign keys only, and whoever needs a name opens the PDF.
 */
final class StoredInvoiceLine
{
    /** @param int[] $memberIds */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly string $descriptor,
        public readonly ?string $sectionCode,
        public readonly ?int $sectionId,
        public readonly int $unitPriceCents,
        public readonly int $quantity,
        public readonly int $amountCents,
        public readonly string $nature,
        public readonly array $memberIds,
        public readonly int $unmatchedPeopleCount
    ) {
    }
}
