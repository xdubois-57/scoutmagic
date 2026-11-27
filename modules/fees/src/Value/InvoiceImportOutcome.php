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
 * - `REFUSED` — the reading is incomplete, its arithmetic does not close,
 *   or identical rows cannot be interpreted without names. The problems
 *   explain which case and where. Nothing was stored.
 * - `STALE_ROSTER` — **the file is fine and the site is behind**. A
 *   section the invoice bills does not exist here, which means Desk has
 *   moved on and this installation has not re-imported. The answer is one
 *   button: import Desk. There is deliberately no screen offering to map
 *   an unknown code by hand — an unknown code is not a piece of data
 *   somebody forgot to enter, it is an out-of-date roster.
 * - `ALREADY_IMPORTED` — the same document number is already there, in
 *   the year aimed at or in another one (issue #1468: a document number
 *   identifies one document whatever year is on screen). Importing twice
 *   is something a treasurer should be able to just try.
 * - `YEAR_TO_CONFIRM` — the document is fine, but its issue date falls in
 *   another scout year than the one it would be filed in (issue #1468).
 *   Not a refusal: a regularisation is legitimately issued after its
 *   season ends. Nothing is stored until the treasurer confirms the year.
 * - `IN_PROGRESS` — another import of the same document is under way
 *   right now (#1548 review). Nothing stored; retrying in a moment finds
 *   the first one and says ALREADY_IMPORTED.
 * - `IMPORTED` — stored.
 */
final class InvoiceImportOutcome
{
    public const IMPORTED = 'imported';
    public const REFUSED = 'refused';
    public const STALE_ROSTER = 'stale_roster';
    public const ALREADY_IMPORTED = 'already_imported';
    public const YEAR_TO_CONFIRM = 'year_to_confirm';
    public const IN_PROGRESS = 'in_progress';

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
        public readonly ?int $totalCents = null,
        /** The scout year the document already sits in, when it is not the one aimed at. */
        public readonly ?string $existingYearLabel = null,
        /** The scout year its issue date falls in — YEAR_TO_CONFIRM only. */
        public readonly ?string $issueYearLabel = null,
        /** The scout year it would be filed in, and its id — YEAR_TO_CONFIRM only. */
        public readonly ?string $targetYearLabel = null,
        public readonly ?int $targetYearId = null,
        /** Imported without a readable issue date: the year could not be checked. */
        public readonly bool $yearUnchecked = false
    ) {
    }

    public static function imported(
        int $invoiceId,
        string $documentNumber,
        int $ignoredRowCount,
        bool $yearUnchecked = false
    ): self {
        return new self(
            self::IMPORTED,
            $invoiceId,
            [],
            [],
            $ignoredRowCount,
            $documentNumber,
            yearUnchecked: $yearUnchecked
        );
    }

    /**
     * The document's issue date falls in $issueYearLabel, not in the year
     * it would be filed in: asked, never refused.
     */
    public static function yearToConfirm(
        string $documentNumber,
        string $issueDate,
        string $issueYearLabel,
        string $targetYearLabel,
        int $targetYearId
    ): self {
        return new self(
            self::YEAR_TO_CONFIRM,
            documentNumber: $documentNumber,
            issueDate: $issueDate,
            issueYearLabel: $issueYearLabel,
            targetYearLabel: $targetYearLabel,
            targetYearId: $targetYearId
        );
    }

    /** Another import of this very document is under way: retry in a moment. */
    public static function importInProgress(string $documentNumber): self
    {
        return new self(self::IN_PROGRESS, documentNumber: $documentNumber);
    }

    /** The same document, already filed in ANOTHER scout year. */
    public static function alreadyImportedInAnotherYear(int $invoiceId, string $documentNumber, string $yearLabel): self
    {
        return new self(
            self::ALREADY_IMPORTED,
            $invoiceId,
            documentNumber: $documentNumber,
            existingYearLabel: $yearLabel
        );
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
        return new self(
            self::STALE_ROSTER,
            null,
            [],
            $unknownSectionCodes,
            $ignoredRowCount,
            $documentNumber,
            $issueDate,
            $totalCents
        );
    }

    public static function alreadyImported(int $invoiceId, string $documentNumber): self
    {
        return new self(self::ALREADY_IMPORTED, $invoiceId, [], [], 0, $documentNumber);
    }

    /**
     * A refusal because the document could not be READ, rather than one
     * whose arithmetic does not close (#1327): the screen says which, since
     * the first is a format to report and the second a document to query.
     */
    public function isUnreadable(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->isFormatProblem()) {
                return true;
            }
        }

        return false;
    }

    public function isStored(): bool
    {
        return $this->status === self::IMPORTED;
    }

    public function isAmbiguous(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->kind === InvoiceProblem::AMBIGUOUS_REPETITION) {
                return true;
            }
        }

        return false;
    }
}
