<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * One printed mention the form itself asks to cross out — « barrer les
 * mentions inutiles ».
 *
 * A stroke through words the form already printed, never a value written
 * over them: the federation's document says « père - mère - tuteur -
 * répondant » and expects three of the four struck, so the site does with a
 * line exactly what a parent would do with a pen.
 *
 * Same units and same origin as `TextField`: millimetres from the top-left
 * corner. `$y` is where the stroke crosses, which for a struck word is
 * roughly a third of the cap height above its baseline.
 */
final class StrikeZone
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width
    ) {
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }
}
