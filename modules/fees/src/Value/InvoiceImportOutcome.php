<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Modules\Fees\Invoice\InvoiceProblem;

/**
 * What an import attempt came to. Two of the four outcomes are failures,
 * and they are deliberately different failures, because the answer a
 * treasurer needs is not the same one:
 *
 * - `REFUSED` — **the file is at fault**. The arithmetic does not close,
 *   and the problems say on which line. Nothing was stored.
 * - `STALE_ROSTER` — **the file is fine and the site is behind**. A
 *   section the invoice bills does not exist here, which means Desk has
 *   moved on and this installation has not re-imported. The answer is one
 *   button: import Desk. There is deliberately no screen offering to map
 *   an unknown code by hand — an unknown code is not a piece of data
 *   somebody forgot to enter, it is an out-of-date roster.
 * - `ALREADY_IMPORTED` — the same document number is already there.
 *   Importing twice is something a treasurer should be able to just try.
 * - `IMPORTED` — stored.
 */
final class InvoiceImportOutcome
{
    public const IMPORTED = 'imported';
    public const REFUSED = 'refused';
    public const STALE_ROSTER = 'stale_roster';
    public const ALREADY_IMPORTED = 'already_imported';

    /**
     * @param InvoiceProblem[] $problems
     * @param string[] $unknownSectionCodes
     */
    private function __construct(
        public readonly string $status,
        public readonly ?int $invoiceId = null,
        public readonly array $problems = [],
        public readonly array $unknownSectionCodes = [],
        public readonly int $ignoredRowCount = 0,
        public readonly ?string $documentNumber = null,
        public readonly ?string $issueDate = null,
        public readonly ?int $totalCents = null
    ) {
    }

    public static function imported(int $invoiceId, string $documentNumber, int $ignoredRowCount): self
    {
        return new self(self::IMPORTED, $invoiceId, [], [], $ignoredRowCount, $documentNumber);
    }

    /** @param InvoiceProblem[] $problems */
    public static function refused(array $problems, int $ignoredRowCount): self
    {
        return new self(self::REFUSED, null, $problems, [], $ignoredRowCount);
    }

    /** @param string[] $unknownSectionCodes */
    public static function staleRoster(
        array $unknownSectionCodes,
        int $ignoredRowCount,
        ?string $documentNumber,
        ?string $issueDate,
        int $totalCents
    ): self {
        return new self(self::STALE_ROSTER, null, [], $unknownSectionCodes, $ignoredRowCount, $documentNumber, $issueDate, $totalCents);
    }

    public static function alreadyImported(int $invoiceId, string $documentNumber): self
    {
        return new self(self::ALREADY_IMPORTED, $invoiceId, [], [], 0, $documentNumber);
    }

    public function isStored(): bool
    {
        return $this->status === self::IMPORTED;
    }
}
