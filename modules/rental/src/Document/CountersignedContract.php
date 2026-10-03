<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use setasign\Fpdi\Tfpdf\Fpdi;

/**
 * The contract signed by both parties (#708, IT-16): the renter's copy as
 * they sent it, and one page added at the end on which the unit signs.
 *
 * **A page added, never a signature placed on a page.** On a scan or a
 * photo nothing here can know where the space for the unit's signature is,
 * and a signature dropped at a guessed position over somebody's text would
 * be worse than none. The added page names who countersigned and when, and
 * ties itself to the copy it closes — the booking's reference and the date
 * that copy arrived.
 *
 * **tFPDF, for the same reason as `OverlayPdf`**: FPDF's core fonts write
 * cp1252 only, and a manager called « Wiśniewski » countersigning must not
 * come out mangled on the one page that says who they are. DejaVu Sans
 * ships with `setasign/tfpdf`.
 *
 * Writes nothing that outlives it: the images it places go through a
 * temporary file FPDF insists on, removed before the method returns.
 */
final class CountersignedContract extends Fpdi
{
    private const FONT_FAMILY = 'DejaVuSans';

    /** The margin around a photographed copy and around the added page. */
    private const MARGIN = 15.0;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');

        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->SetCompression(true);
        $this->AddFont(self::FONT_FAMILY, '', 'DejaVuSans.ttf', true);
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Every page of the renter's PDF, each at its own size.
     *
     * @throws \setasign\Fpdi\PdfParser\PdfParserException when this build
     *   of FPDI cannot read the file — the caller decides what to try next
     */
    public function addPdfPages(string $path): int
    {
        $count = $this->setSourceFile($path);
        for ($page = 1; $page <= $count; $page++) {
            $template = $this->importPage($page);
            $size = $this->getTemplateSize($template);
            $this->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $this->useTemplate($template);
        }

        return $count;
    }

    /**
     * A photographed or scanned page, fitted on an A4 page — turned
     * landscape when the photo is.
     *
     * @param string $jpeg the image as JPEG bytes (`SignatureImage` and the
     *   caller re-encode whatever arrived)
     */
    public function addImagePage(string $jpeg): void
    {
        $info = getimagesizefromstring($jpeg);
        if ($info === false) {
            throw new \InvalidArgumentException('Not an image.');
        }

        $landscape = $info[0] > $info[1];
        $this->AddPage($landscape ? 'L' : 'P');
        $this->placeImage(
            $jpeg,
            'jpg',
            self::MARGIN,
            self::MARGIN,
            $this->GetPageWidth() - 2 * self::MARGIN,
            $this->GetPageHeight() - 2 * self::MARGIN,
            $info[0],
            $info[1]
        );
    }

    /**
     * The page the unit signs on.
     *
     * @param list<string> $lines what the page says above the signature
     * @param string $signaturePng the manager's own signature
     * @param list<string> $footer what ties the page to the copy it closes
     */
    public function addCountersignaturePage(string $title, array $lines, string $signaturePng, array $footer): void
    {
        $this->AddPage('P');
        $width = $this->GetPageWidth() - 2 * self::MARGIN;

        $this->SetXY(self::MARGIN, self::MARGIN + 10);
        $this->SetFont(self::FONT_FAMILY, '', 16);
        $this->MultiCell($width, 8, $title);
        $this->Ln(4);

        $this->SetFont(self::FONT_FAMILY, '', 11);
        foreach ($lines as $line) {
            $this->SetX(self::MARGIN);
            $this->MultiCell($width, 6, $line);
        }
        $this->Ln(6);

        $info = getimagesizefromstring($signaturePng);
        if ($info !== false) {
            $this->placeImage($signaturePng, 'png', self::MARGIN, $this->GetY(), 90, 35, $info[0], $info[1]);
            $this->SetY($this->GetY() + 40);
        }

        $this->SetFont(self::FONT_FAMILY, '', 9);
        $this->SetTextColor(90, 90, 90);
        foreach ($footer as $line) {
            $this->SetX(self::MARGIN);
            $this->MultiCell($width, 5, $line);
        }
        $this->SetTextColor(0, 0, 0);
    }

    public function render(): string
    {
        return (string) $this->Output('S');
    }

    /**
     * An image inside a box, at its own proportions, from the box's
     * top-left corner — through a temporary file, which is the only way
     * FPDF reads one.
     */
    private function placeImage(
        string $bytes,
        string $type,
        float $x,
        float $y,
        float $boxWidth,
        float $boxHeight,
        int $pixelWidth,
        int $pixelHeight
    ): void {
        $scale = min($boxWidth / max(1, $pixelWidth), $boxHeight / max(1, $pixelHeight));
        $path = tempnam(sys_get_temp_dir(), 'rental-sign-');
        if ($path === false) {
            throw new \RuntimeException('No temporary file for an image.');
        }

        try {
            file_put_contents($path, $bytes);
            $this->Image($path, $x, $y, $pixelWidth * $scale, $pixelHeight * $scale, $type);
        } finally {
            @unlink($path);
        }
    }
}
