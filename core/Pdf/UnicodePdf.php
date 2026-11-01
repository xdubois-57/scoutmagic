<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Pdf;

use setasign\Fpdi\Tfpdf\Fpdi;

/** UTF-8 PDF overlays whose cached font metrics survive relocation. */
abstract class UnicodePdf extends Fpdi
{
    protected function addUnicodeFont(string $family, string $file): void
    {
        parent::AddFont($family, '', $file, true);

        // tFPDF caches an absolute ttffile path in its metrics. A copied
        // installation can reuse those metrics (same font size) while the
        // embedded path still names the build machine. Resolve the font
        // exactly as AddFont does, without rewriting a read-only vendor/.
        $fontFile = $this->fontpath . 'unifont/' . $file;
        if (defined('_SYSTEM_TTFONTS') && is_file(_SYSTEM_TTFONTS . $file)) {
            $fontFile = _SYSTEM_TTFONTS . $file;
        }

        $fontKey = strtolower($family);
        $this->fonts[$fontKey]['ttffile'] = $fontFile;
        $this->FontFiles[$fontKey]['ttffile'] = $fontFile;
    }
}
