<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * Why a reading was refused, in terms a treasurer can act on: which line,
 * what was expected, what the document said.
 *
 * A refusal is never a bare "invalid file". The whole value of the
 * arithmetic check is that it names the row it failed on — that is what
 * turns "the site will not read my invoice" into "line COT_FAM of section
 * SV025L1 says 124,00 where 31,00 × 5 is 155,00".
 */
final class InvoiceProblem
{
    public const NO_TEXT_LAYER = 'no_text_layer';
    public const NO_LINE_FOUND = 'no_line_found';
    public const LINE_ARITHMETIC = 'line_arithmetic';
    public const NAME_COUNT = 'name_count';
    public const TOTAL_MISSING = 'total_missing';
    public const TOTAL_MISMATCH = 'total_mismatch';

    /** The PDF library itself failed on the file (#1327). */
    public const EXTRACTION_FAILED = 'extraction_failed';

    /** A row that looks financial — a tariff reference, two amounts — and was not read (#1327). */
    public const UNRECOGNISED_LINE = 'unrecognised_line';

    /** A quantity with a fractional part, `2,50`: never truncated, refused (#1327). */
    public const FRACTIONAL_QUANTITY = 'fractional_quantity';
    public const AMBIGUOUS_REPETITION = 'ambiguous_repetition';

    /**
     * The kinds that mean « this document could not be READ », as opposed
     * to « it was read and its arithmetic does not close ». They need
     * different answers — a format to support, a document to query — and
     * the import screen says which (#1327).
     */
    private const FORMAT_KINDS = [
        self::NO_TEXT_LAYER,
        self::EXTRACTION_FAILED,
        self::NO_LINE_FOUND,
        self::UNRECOGNISED_LINE,
        self::FRACTIONAL_QUANTITY,
        self::AMBIGUOUS_REPETITION,
        self::TOTAL_MISSING,
    ];

    public function __construct(
        public readonly string $kind,
        /** French, already written for a reader — this reaches a screen verbatim. */
        public readonly string $message,
        public readonly ?string $reference = null,
        public readonly ?string $sectionCode = null,
        public readonly ?int $expectedCents = null,
        public readonly ?int $foundCents = null
    ) {
    }

    public function isFormatProblem(): bool
    {
        return in_array($this->kind, self::FORMAT_KINDS, true);
    }

    /** Where the reading stopped: `extraction`, `recognition` or `arithmetic`. */
    public function stage(): string
    {
        return match ($this->kind) {
            self::NO_TEXT_LAYER, self::EXTRACTION_FAILED => 'extraction',
            self::LINE_ARITHMETIC, self::NAME_COUNT, self::TOTAL_MISMATCH => 'arithmetic',
            default => 'recognition',
        };
    }
}
