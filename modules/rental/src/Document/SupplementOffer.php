<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Modules\Rental\Stay\Settlement;

/**
 * What a supplementary invoice would bill, now (#980, chantier decision 2.8).
 *
 * A settlement validated after the invoice went out keeps a different
 * amount from the one the renter was billed: the supplement carries only
 * that difference, against the last billing document sent — the invoice,
 * or a supplement before this one — and always names the invoice it
 * completes, which itself never changes.
 *
 * Worked out by `RentalDocumentService::supplementOffer()` from the stored
 * documents and settlements, never kept: the page offers it, and the
 * generation asks again under the booking's lock.
 */
final class SupplementOffer
{
    public function __construct(
        /** The invoice the renter holds — the one every supplement refers to. */
        public readonly RentalDocument $originalInvoice,
        /** The validated settlement the supplement brings the billing to. */
        public readonly Settlement $settlement,
        /** What the last billing document sent billed. */
        public readonly int $alreadyBilledCents,
        /** The settlement that document was based on, null when it billed the agreed total. */
        public readonly ?Settlement $billedSettlement = null
    ) {
    }

    /** Positive: the renter owes more. Negative: in the renter's favour. */
    public function differenceCents(): int
    {
        return $this->settlement->keptCents() - $this->alreadyBilledCents;
    }

    public function favoursTheRenter(): bool
    {
        return $this->differenceCents() < 0;
    }
}
