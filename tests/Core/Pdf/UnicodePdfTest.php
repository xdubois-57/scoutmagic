<?php

declare(strict_types=1);

namespace Tests\Core\Pdf;

use Core\Pdf\UnicodePdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Tests\RelocatedTfpdfFontCache;

final class UnicodePdfTest extends TestCase
{
    #[DataProvider('systemFontAvailability')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRelocationRespectsSystemFontsAndTheirFallback(bool $available): void
    {
        $directory = sys_get_temp_dir() . '/scoutmagic-system-fonts-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $systemFont = $directory . '/DejaVuSans.ttf';
        $cleanup = static function () use ($systemFont, $directory): void {
            if (is_file($systemFont)) {
                unlink($systemFont);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        };
        register_shutdown_function($cleanup);

        try {
            if ($available) {
                copy(dirname((new \ReflectionClass(\tFPDF::class))->getFileName())
                    . '/font/unifont/DejaVuSans.ttf', $systemFont);
            }
            define('_SYSTEM_TTFONTS', $directory . '/');
            $selectedFont = null;
            $text = 'Ayşe Gül — Wiśniewski';
            $bytes = RelocatedTfpdfFontCache::render(static function () use (&$selectedFont, $text): string {
                $pdf = new class extends UnicodePdf {
                    public function __construct()
                    {
                        parent::__construct();
                        $this->addUnicodeFont('DejaVuSans', 'DejaVuSans.ttf');
                    }

                    public function selectedFont(): string
                    {
                        return $this->fonts['dejavusans']['ttffile'];
                    }
                };
                $selectedFont = $pdf->selectedFont();
                $pdf->AddPage();
                $pdf->SetFont('DejaVuSans', '', 12);
                $pdf->Text(10, 10, $text);

                return (string) $pdf->Output('S');
            });

            $this->assertSame([
                $available ? $systemFont : FPDF_FONTPATH . 'unifont/DejaVuSans.ttf',
                $text,
            ], [
                $selectedFont,
                trim((new Parser())->parseContent($bytes)->getText()),
            ]);
        } finally {
            $cleanup();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function systemFontAvailability(): iterable
    {
        yield 'custom system font' => [true];
        yield 'missing system font uses the packaged font' => [false];
    }
}
