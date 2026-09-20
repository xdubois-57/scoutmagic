<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Pdf;

use Modules\OfficialDocuments\Pdf\OverlayPdf;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Pdf\TextField;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * The engine's one decision: how much of a free-text answer fits on the
 * lines the federation's form prints for it.
 *
 * Testable as strings although it needs real font metrics, which is why
 * `wrapInto()` answers the lines rather than drawing them: what comes back
 * is what would be printed, measured by the same DejaVu Sans that prints
 * it. Asserting on rendered PDF bytes instead would be a test that agrees
 * with whatever the code did.
 *
 * **The property that matters is the overflow flag, not the wrapping.**
 * Nothing here is cut in silence — the chantier's rule — and the flag is
 * how the screen knows to tell the parent before they print rather than
 * after.
 */
final class OverlayPdfTest extends TestCase
{
    /** Two lines of the width the health sheet's continuations have. */
    private static function twoLines(): array
    {
        return [new TextField(21.3, 100.0, 165.9), new TextField(21.3, 106.4, 165.9)];
    }

    public function testAnEmptyAnswerFillsNoLineAndOverflowsNothing(): void
    {
        $result = (new OverlayPdf())->wrapInto('', self::twoLines());

        $this->assertSame(['', ''], $result['lines']);
        $this->assertFalse($result['overflow']);
    }

    public function testAShortAnswerStaysOnTheFirstLine(): void
    {
        $result = (new OverlayPdf())->wrapInto('Arachides, pollen.', self::twoLines());

        $this->assertSame('Arachides, pollen.', $result['lines'][0]);
        $this->assertSame('', $result['lines'][1]);
        $this->assertFalse($result['overflow']);
    }

    /**
     * A long answer runs onto the next printed line, breaking between
     * words — never inside one.
     */
    public function testALongAnswerRunsOntoTheNextPrintedLine(): void
    {
        $text = 'Allergie aux arachides et aux fruits à coque, au pollen de graminées, '
            . 'aux acariens ainsi qu\'aux piqûres de guêpe et de frelon asiatique.';

        $result = (new OverlayPdf())->wrapInto($text, self::twoLines());

        $this->assertNotSame('', $result['lines'][1], 'Rien n\'a débordé sur la deuxième ligne.');
        $this->assertFalse($result['overflow']);
        // Word for word, nothing lost and nothing invented between the two.
        $this->assertSame($text, trim($result['lines'][0] . ' ' . $result['lines'][1]));
    }

    /**
     * Each line is measured at its OWN width. The health sheet's first
     * continuation is often a stub — 11 mm on « Mentionnez toute
     * information utile » — and a wrap that used one width for all of them
     * would print over the label.
     */
    public function testEachLineIsMeasuredAtItsOwnWidth(): void
    {
        $lines = [new TextField(176.0, 26.6, 11.2), new TextField(21.3, 33.0, 165.9)];

        $result = (new OverlayPdf())->wrapInto('Porte des lunettes en permanence', $lines);

        $this->assertSame('Porte', $result['lines'][0]);
        $this->assertSame('des lunettes en permanence', $result['lines'][1]);
        $this->assertFalse($result['overflow']);
    }

    /**
     * **What the whole thing is for.** More than the form holds is reported,
     * and what fits is still written: a parent gets their document AND the
     * warning, rather than a truncated document and no idea.
     */
    public function testAnAnswerLongerThanTheFormIsReportedRatherThanCutInSilence(): void
    {
        $text = str_repeat('Traitement quotidien à administrer avec précaution, ', 12);

        $result = (new OverlayPdf())->wrapInto($text, self::twoLines());

        $this->assertTrue($result['overflow']);
        $this->assertNotSame('', $result['lines'][0]);
        $this->assertNotSame('', $result['lines'][1]);
    }

    /**
     * A single word wider than its whole line is put on that line rather
     * than dropped — and rather than looping forever, which is what a
     * naive greedy wrap does here.
     */
    public function testASingleWordWiderThanTheLineIsStillWritten(): void
    {
        $word = str_repeat('Antidisestablishmentarianisme', 4);

        $result = (new OverlayPdf())->wrapInto($word . ' suite', self::twoLines());

        $this->assertSame($word, $result['lines'][0]);
        $this->assertSame('suite', $result['lines'][1]);
        $this->assertFalse($result['overflow']);
    }

    /**
     * Line breaks a textarea produces are collapsed to spaces: these lines
     * are printed dotted rules on somebody else's form, and a newline
     * written onto one is an invisible gap.
     */
    public function testLineBreaksFromATextareaBecomeSpaces(): void
    {
        $result = (new OverlayPdf())->wrapInto("Arachides\r\nPollen\tAcariens", self::twoLines());

        $this->assertSame('Arachides Pollen Acariens', $result['lines'][0]);
    }

    /**
     * An answer with no line at all overflows rather than disappearing —
     * the case a layout with a forgotten continuation would produce.
     */
    public function testAnAnswerWithNowhereToGoOverflows(): void
    {
        $result = (new OverlayPdf())->wrapInto('Quelque chose', []);

        $this->assertSame([], $result['lines']);
        $this->assertTrue($result['overflow']);
    }

    // ---------------------------------------------------------------
    // What a single-line value does when it does not fit
    // ---------------------------------------------------------------

    /**
     * Whatever is written on one page, read back out of it.
     */
    private static function textDrawnBy(callable $write): string
    {
        $pdf = new OverlayPdf();
        $pdf->openTemplate(TemplateLibrary::shipped()->path(TemplateLibrary::HEALTH_SHEET));
        $pdf->startPage(1);
        $write($pdf);

        return (new Parser())->parseContent($pdf->render())->getPages()[0]->getText();
    }

    /**
     * **A value never runs past its own line.**
     *
     * The type is shrunk first, and when even the smallest size does not
     * fit, what fits is written and the rest is reported. The line this
     * uses is the health sheet's own « Remarque » in the left-hand column
     * of the emergency-contact table: it ends at 101 mm, the printed frame
     * is at 102.4, and the right-hand contact's answer starts at 122.3.
     *
     * An earlier version drew the whole string on the grounds that a
     * cramped line beats a blank one. At six point, an eighty-five
     * character note reaches 133.5 mm — through the frame and through the
     * second contact's cell, so the two people a first-aider would ring
     * overprint each other.
     */
    public function testAValueTooLongForItsLineIsCutRatherThanDrawnPastIt(): void
    {
        $field = new TextField(40.0, 145.2, 61.0);
        $note = 'Joignable uniquement en journee, de preference apres quatorze heures, '
            . 'sinon appeler le grandpere qui habite a cote';

        $fits = null;
        $text = self::textDrawnBy(static function (OverlayPdf $pdf) use ($field, $note, &$fits): void {
            $fits = $pdf->writeText($note, $field);
        });

        $this->assertFalse($fits, 'Le débordement doit être signalé, jamais avalé.');
        $this->assertStringContainsString('Joignable', $text, 'Ce qui tient doit être écrit.');
        $this->assertStringNotContainsString(
            'habite a cote',
            $text,
            'La fin de la remarque est imprimée au-delà de sa ligne, dans la cellule du contact 2.'
        );
    }

    /**
     * A value that fits once shrunk is written whole: the cut above is the
     * last resort, not the ordinary path.
     */
    public function testAValueThatFitsOnceShrunkIsWrittenWhole(): void
    {
        $field = new TextField(40.0, 145.2, 61.0);
        $value = 'Joignable en journee seulement';

        $fits = null;
        $text = self::textDrawnBy(static function (OverlayPdf $pdf) use ($field, $value, &$fits): void {
            $fits = $pdf->writeText($value, $field);
        });

        $this->assertTrue($fits);
        $this->assertStringContainsString($value, $text);
    }

    /**
     * The cut is on a character, because this path serves values the form
     * gives one line to — an e-mail address has no space to break on at
     * all, and dropping it entirely would be worse than printing the part
     * that fits.
     */
    public function testAValueWithNoSpaceToBreakOnIsStillPartlyWritten(): void
    {
        $field = new TextField(32.9, 138.4, 20.0);
        $email = 'prenom.nom.de.famille.tres.longue@une-adresse-interminable.example.be';

        $fits = null;
        $text = self::textDrawnBy(static function (OverlayPdf $pdf) use ($field, $email, &$fits): void {
            $fits = $pdf->writeText($email, $field);
        });

        $this->assertFalse($fits);
        $this->assertStringContainsString('prenom', $text);
        $this->assertStringNotContainsString('example.be', $text);
    }
}
