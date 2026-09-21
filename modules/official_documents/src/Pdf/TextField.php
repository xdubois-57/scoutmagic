<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * One place on an official form where the site writes a value.
 *
 * Millimetres from the top-left corner of the page, which is what tFPDF
 * counts in and what `scripts/pdf-template-grid.php` prints over the
 * template — so a coordinate read off that grid is a coordinate that can be
 * typed straight into a layout.
 *
 * `$baselineY` is the BASELINE, not the top of a box: these values sit on
 * printed dotted lines, and a baseline is the only measurement that puts
 * them there rather than near there. `OverlayPdf::writeText()` therefore
 * draws with `Text()` rather than `Cell()`.
 */
final class TextField
{
    public function __construct(
        public readonly float $x,
        public readonly float $baselineY,
        /**
         * How much room the value has before it runs into whatever the form
         * prints next. `OverlayPdf::writeText()` shrinks the type to fit and
         * reports when even the smallest size does not — a value that ran
         * over would be illegible on a document a parent signs.
         */
        public readonly float $width,
        public readonly float $fontSize = 10.0,
        /**
         * Which page of the template this line is printed on.
         *
         * One for the parental authorization, which has a single page —
         * and deliberately not an assumption the health sheet is allowed to
         * inherit: its « maladies importantes ou opérations subies » runs
         * over three printed lines, the third of which is at the top of
         * page 2.
         */
        public readonly int $page = 1
    ) {
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }
}
