<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Pdf;

use Modules\OfficialDocuments\Pdf\OverlayPdf;
use Modules\OfficialDocuments\Pdf\TextField;
use PHPUnit\Framework\TestCase;

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
}
