<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\CountersignedContract;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Tests\RelocatedTfpdfFontCache;

final class CountersignedContractTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACopiedFontCacheStillEmbedsTheCurrentInstallationsFont(): void
    {
        $name = 'Ayşe Gül — Bartosz Wiśniewski';
        $bytes = RelocatedTfpdfFontCache::render(static function () use ($name): string {
            $pdf = new CountersignedContract();
            $pdf->AddPage();
            $pdf->SetFont('DejaVuSans', '', 12);
            $pdf->Text(10, 10, $name);

            return $pdf->render();
        });

        $this->assertSame($name, trim((new Parser())->parseContent($bytes)->getText()));
    }
}
