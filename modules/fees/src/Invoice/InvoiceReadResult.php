<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * Accepted, or refused with reasons — never "read with warnings".
 *
 * A reading either falls on the centime or it does not, and a document
 * that does not is not a document this site can check anything against.
 * Returning a result rather than throwing is what lets the import screen
 * name the offending line instead of showing an error page.
 */
final class InvoiceReadResult
{
    /** @param InvoiceProblem[] $problems */
    private function __construct(
        public readonly ?ParsedInvoice $invoice,
        public readonly array $problems,
        public readonly int $ignoredRowCount
    ) {
    }

    public static function accepted(ParsedInvoice $invoice): self
    {
        return new self($invoice, [], $invoice->ignoredRowCount);
    }

    /** @param InvoiceProblem[] $problems */
    public static function refused(array $problems, int $ignoredRowCount = 0): self
    {
        return new self(null, $problems, $ignoredRowCount);
    }

    public function isAccepted(): bool
    {
        return $this->invoice !== null;
    }
}
