<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

use setasign\Fpdi\Tfpdf\Fpdi;

/**
 * The engine both official documents are produced with: the federation's own
 * PDF imported as the background, and the site's values written on top of it
 * at fixed millimetre coordinates.
 *
 * **tFPDF and not FPDF**, which is the whole reason this class extends
 * `setasign\Fpdi\Tfpdf\Fpdi` rather than the plain `Fpdi` the attestations
 * module splits pages with. FPDF's core fonts write cp1252 only, so a member
 * called « Bartosz Wiśniewski » or « Ayşe Gül » would come out mangled — or
 * take the generation down — on a document their parents are asked to sign.
 * tFPDF writes UTF-8 through an embedded TrueType subset; the font is
 * DejaVu Sans, which `setasign/tfpdf` ships in its own `font/unifont/`
 * directory, so there is nothing to vendor and nothing to configure.
 *
 * Two properties worth stating because they are easy to lose:
 *
 * - **Nothing is written to disk.** `render()` returns the bytes; the
 *   caller answers them. tFPDF may cache the font metrics beside its own
 *   TTF when that directory happens to be writable, which is upstream's
 *   behaviour and not this document — measured at 9 ms either way, so
 *   nothing here depends on it.
 * - **There is no HTML in sight.** A value written here goes onto a PDF
 *   canvas, and tFPDF escapes the characters a PDF string cares about
 *   itself. Running `htmlspecialchars()` over it first — the rule that
 *   governs `Core\Pdf\DocumentPdfService`, which is a dompdf engine —
 *   would print « L&#039;Hoëst » on the form.
 */
final class OverlayPdf extends Fpdi
{
    /** The one family this module writes in. */
    public const FONT_FAMILY = 'DejaVuSans';

    /**
     * How small `writeText()` may go before it gives up and reports that the
     * value does not fit. Below this the line is there but nobody reads it,
     * which is worse than knowing it overflowed.
     */
    public const MIN_FONT_SIZE = 6.0;

    private const SHRINK_STEP = 0.25;

    /** How far a tick's strokes reach beyond the square they cross. */
    private const TICK_OVERHANG = 0.35;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');

        // Every page is the template's page, cut to its own size below —
        // nothing here ever flows, so an automatic break could only ever
        // add a blank page nobody asked for.
        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->SetCompression(true);
        $this->AddFont(self::FONT_FAMILY, '', 'DejaVuSans.ttf', true);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
    }

    /**
     * Open a template and answer how many pages it has.
     *
     * @throws \setasign\Fpdi\PdfParser\PdfParserException when the file is
     *   not a PDF this build of FPDI can read — which in practice means a
     *   template somebody replaced without converting it (see
     *   `modules/official_documents/templates/README.md`).
     */
    public function openTemplate(string $path): int
    {
        return $this->setSourceFile($path);
    }

    /**
     * Start one page of the output, on the corresponding page of the
     * template, at the template's own size.
     *
     * Deliberately not assuming A4 even though both templates are: the size
     * comes from the imported page, so a federation form that arrives one
     * day in another format is drawn at ITS size rather than squeezed into
     * the one this class was written against.
     */
    public function startPage(int $pageNumber): void
    {
        $template = $this->importPage($pageNumber);
        $size = $this->getTemplateSize($template);

        $this->AddPage('P', [$size['width'], $size['height']]);
        $this->useTemplate($template);
    }

    /**
     * Write one value at its declared place, shrinking the type until it
     * fits the room the form leaves for it.
     *
     * Returns false when even `MIN_FONT_SIZE` is too large — the value is
     * still written, because a form with a cramped line on it is more use
     * than a form with a blank one, and the caller is told so it can say on
     * the web page that this entry will not fit. Silence here is the one
     * outcome that would be wrong.
     */
    public function writeText(string $value, TextField $field): bool
    {
        $value = self::sanitise($value);
        if ($value === '') {
            return true;
        }

        $size = $field->fontSize;
        $this->SetFont(self::FONT_FAMILY, '', $size);

        while ($this->GetStringWidth($value) > $field->width && $size > self::MIN_FONT_SIZE) {
            $size = max(self::MIN_FONT_SIZE, $size - self::SHRINK_STEP);
            $this->SetFont(self::FONT_FAMILY, '', $size);
        }

        $this->Text($field->x, $field->baselineY, $value);

        return $this->GetStringWidth($value) <= $field->width;
    }

    /**
     * Fit a free-text answer onto the printed lines the form gives it, and
     * say whether it all got there.
     *
     * The health sheet asks for allergies, treatments and « toute
     * information utile » on two or three dotted lines and nothing more, so
     * a parent who writes a paragraph is writing more than the federation's
     * form holds. This wraps on word boundaries at each line's own width —
     * the first of them is often a stub, 11 mm on « Mentionnez toute
     * information utile » — and reports what did not fit.
     *
     * **It never truncates in silence.** The caller is told, and the screen
     * tells the parent, which is the chantier's rule: discovering on paper
     * that half a treatment is missing is the one outcome worth building
     * against.
     *
     * A single word longer than its whole line is put on that line anyway
     * rather than dropped or looped on — `writeText()` then shrinks it, and
     * the overflow is reported either way.
     *
     * @param list<TextField> $lines the form's own lines, in reading order
     * @return array{lines: list<string>, overflow: bool} one string per
     *         line (padded with empties), and whether anything was left
     */
    public function wrapInto(string $value, array $lines): array
    {
        $value = self::sanitise($value);
        $filled = array_fill(0, count($lines), '');
        if ($value === '') {
            return ['lines' => $filled, 'overflow' => false];
        }
        if ($lines === []) {
            // An answer the layout gave no line to. Reported rather than
            // dropped: silence here is a family's treatment missing from a
            // document with nothing anywhere to say so.
            return ['lines' => $filled, 'overflow' => true];
        }

        $words = explode(' ', $value);
        $next = 0;
        foreach ($lines as $index => $line) {
            $this->SetFont(self::FONT_FAMILY, '', $line->fontSize);
            $current = '';
            while ($next < count($words)) {
                $candidate = $current === '' ? $words[$next] : $current . ' ' . $words[$next];
                if ($current !== '' && $this->GetStringWidth($candidate) > $line->width) {
                    break;
                }
                $current = $candidate;
                $next++;
                // A first word that is already too wide has been taken, so
                // the loop always advances; it stops here rather than
                // cramming a second word beside it.
                if ($this->GetStringWidth($current) > $line->width) {
                    break;
                }
            }
            $filled[$index] = $current;
        }

        return ['lines' => $filled, 'overflow' => $next < count($words)];
    }

    /**
     * Draw the cross a parent would draw, in a square the form printed.
     *
     * Two strokes rather than a glyph: the two box sizes on the health
     * sheet are 1.35 mm and 2.2 mm, and a « ✗ » sized to sit inside either
     * of them would depend on font metrics for something that is two lines.
     *
     * Deliberately drawn slightly wider than the square. A cross confined
     * inside a 1.35 mm box is a smudge on paper; overhanging it reads as a
     * mark somebody made, which is exactly what it is.
     */
    public function tick(TickBox $box): void
    {
        $left = $box->x - self::TICK_OVERHANG;
        $top = $box->y - self::TICK_OVERHANG;
        $right = $box->x + $box->size + self::TICK_OVERHANG;
        $bottom = $box->y + $box->size + self::TICK_OVERHANG;

        $this->SetLineWidth(0.35);
        $this->Line($left, $top, $right, $bottom);
        $this->Line($left, $bottom, $right, $top);
    }

    /**
     * Cross out one of the mentions the form printed.
     */
    public function strikeThrough(StrikeZone $zone): void
    {
        $this->SetLineWidth(0.4);
        $this->Line($zone->x, $zone->y, $zone->right(), $zone->y);
    }

    /**
     * The finished document, as bytes.
     *
     * `'S'` is tFPDF's "give it back as a string" destination: nothing is
     * sent to the browser from in here and nothing touches the filesystem,
     * so the controller stays the only thing that decides what happens to
     * these bytes.
     */
    public function render(): string
    {
        return (string) $this->Output('S');
    }

    /**
     * What may reach the canvas.
     *
     * A member controls their own name and a parent types the place; neither
     * has any business carrying a line break, a tab or a control character
     * onto a one-line printed field. They are collapsed to spaces rather
     * than refused — the point is a legible document, not a validation
     * error on somebody's own name — and the string is otherwise handed over
     * untouched, because this is not an HTML context (see the class
     * docblock).
     */
    private static function sanitise(string $value): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', (string) $collapsed) ?? '');
    }
}
