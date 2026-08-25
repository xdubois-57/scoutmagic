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
}
