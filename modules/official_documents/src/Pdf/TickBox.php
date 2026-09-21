<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * One of the little squares an official form prints, and where the site
 * draws the cross a parent would draw with a pen.
 *
 * **The TOP-LEFT corner and the side, not a baseline.** Everything else in
 * this module is positioned on the printed baseline because everything else
 * sits on a printed dotted line; a box does not sit on a line, it is a
 * square, and giving it a baseline would mean re-deriving its top from a
 * cap height that changes with every box size the federation uses. The
 * health sheet already uses two of them — 1.35 mm for the twelve conditions
 * and 2.2 mm for the OUI/NON and swimming answers — which is exactly the
 * reason this is measured rather than assumed.
 *
 * The values are read off a 300 dpi rendering of the template AFTER FPDI
 * has flattened it, which matters: had these squares been form widgets
 * rather than drawn content, they would not survive the import and a cross
 * drawn here would float on a blank page. They do survive — measured, not
 * hoped for.
 */
final class TickBox
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $size,
        /**
         * Which page of the template prints this box. The health sheet runs
         * to two, and « page 1 » is not a safe default to hard-code
         * anywhere — see `OverlayPdf::startPage()`.
         */
        public readonly int $page = 1
    ) {
    }
}
