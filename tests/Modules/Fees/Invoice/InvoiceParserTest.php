<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceProblem;
use PHPUnit\Framework\TestCase;

/**
 * The six rules the parser is built on, one by one, on text — extraction
 * from a PDF is Invoice\InvoiceReader's job and its own test.
 */
class InvoiceParserTest extends TestCase
{
    private InvoiceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InvoiceParser();
    }

    /** @param string[] $lines */
    private function parse(array $lines): \Modules\Fees\Invoice\InvoiceReadResult
    {
        return $this->parser->parse(implode("\n", $lines));
    }

    /** @return string[] */
    private static function oneGoodLine(int $total = 11700): array
    {
        return [
            'COT_NORM Cotisation normale SV025B1 39,00 3 117,00',
            'DUBOIS Basile 12/04/2016 Animé',
            'PISSOORT Anouk 03/11/2015 Animé',
            'LAMBERT Noé 27/08/2016 Animé',
            'TOTAL A PAYER ' . number_format($total / 100, 2, ',', ''),
        ];
    }

    // -- 1. Recognising shapes ------------------------------------------

    public function testATariffLineIsRecognisedByItsThreeTrailingNumbers(): void
    {
        $result = $this->parse(self::oneGoodLine());

        $this->assertTrue($result->isAccepted());
        $line = $result->invoice?->lines[0];
        $this->assertNotNull($line);
        $this->assertSame('COT_NORM', $line->reference);
        $this->assertSame('SV025B1', $line->sectionCode);
        $this->assertSame(3900, $line->unitPriceCents);
        $this->assertSame(3, $line->quantity);
        $this->assertSame(11700, $line->amountCents);
    }

    public function testANominativeLineIsSplitAroundItsDate(): void
    {
        $result = $this->parse(self::oneGoodLine());

        $person = $result->invoice?->lines[0]->people[0];
        $this->assertNotNull($person);
        $this->assertSame('DUBOIS', $person->lastName);
        $this->assertSame('Basile', $person->firstName);
        $this->assertSame('2016-04-12', $person->birthDate);
        $this->assertSame('Animé', $person->functionLabel);
    }

    public function testAHeaderRowCarryingADateIsNotReadAsAPerson(): void
    {
        // "Date : 08/01/2026" carries the same marker a nominative line
        // does; reading it as somebody called "Date" would put a phantom in
        // the count.
        $result = $this->parse(array_merge(['Date : 08/01/2026'], self::oneGoodLine()));

        $this->assertTrue($result->isAccepted());
        $this->assertCount(3, $result->invoice?->lines[0]->people ?? []);
        $this->assertSame('2026-01-08', $result->invoice?->issueDate);
    }

    // -- 2. No hardcoded list of references ------------------------------

    public function testAReferenceTheSiteHasNeverSeenIsReadRatherThanRefused(): void
    {
        $result = $this->parse([
            'COT_TOTALEMENT_NOUVEAU Quelque chose de neuf SV025B1 12,00 1 12,00',
            'DUBOIS Basile 12/04/2016 Animé',
            'TOTAL A PAYER 12,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $this->assertSame('COT_TOTALEMENT_NOUVEAU', $result->invoice?->lines[0]->reference);
    }

    /** @return array<string, array{string, string[], string, string}> */
    public static function natureProvider(): array
    {
        return [
            'positive with a list is a membership fee' => [
                'COT_NORM Cotisation normale SV025B1 39,00 1 39,00',
                ['DUBOIS Basile 12/04/2016 Animé'],
                '39,00',
                InvoiceLine::NATURE_FEE,
            ],
            'negative with a list is a reduction' => [
                'RED_ANIM_BREV Réduction animateur breveté SV025L1 -10,00 1 -10,00',
                ['DUPONT Sophie 22/06/1995 Animateur'],
                '-10,00',
                InvoiceLine::NATURE_REDUCTION,
            ],
            // Both of the two below are negative: what tells them apart is
            // that this one names nobody.
            'no list at all is a global adjustment' => [
                "COT_ACOMPTE Déduction de l'acompte -10,00 1 -10,00",
                [],
                '-10,00',
                InvoiceLine::NATURE_ADJUSTMENT,
            ],
        ];
    }

    /**
     * @param string[] $people
     * @dataProvider natureProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('natureProvider')]
    public function testANatureIsReadOffTheLinesOwnShape(
        string $tariffLine,
        array $people,
        string $total,
        string $expected
    ): void {
        $result = $this->parse(array_merge([$tariffLine], $people, ['TOTAL A PAYER ' . $total]));

        $this->assertTrue($result->isAccepted());
        $this->assertSame($expected, $result->invoice?->lines[0]->nature());
    }

    // -- 3. Arithmetic, in three layers ----------------------------------

    public function testALineWhoseMultiplicationIsWrongIsRefusedByName(): void
    {
        $result = $this->parse([
            'COT_NORM Cotisation normale SV025B1 39,00 3 117,01',
            'DUBOIS Basile 12/04/2016 Animé',
            'PISSOORT Anouk 03/11/2015 Animé',
            'LAMBERT Noé 27/08/2016 Animé',
            'TOTAL A PAYER 117,01',
        ]);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(InvoiceProblem::LINE_ARITHMETIC, $result->problems[0]->kind);
        $this->assertSame('COT_NORM', $result->problems[0]->reference);
        $this->assertSame('SV025B1', $result->problems[0]->sectionCode);
        $this->assertStringContainsString('COT_NORM / SV025B1', $result->problems[0]->message);
    }

    public function testALineWithFewerNamesThanItsQuantityIsRefusedByName(): void
    {
        $result = $this->parse([
            'COT_NORM Cotisation normale SV025B1 39,00 3 117,00',
            'DUBOIS Basile 12/04/2016 Animé',
            'TOTAL A PAYER 117,00',
        ]);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(InvoiceProblem::NAME_COUNT, $result->problems[0]->kind);
        $this->assertStringContainsString('1 nom(s) listé(s)', $result->problems[0]->message);
    }

    /** One cent, and the whole document is refused — with both figures. */
    public function testATotalOffByOneCentIsRefused(): void
    {
        $result = $this->parse(self::oneGoodLine(11701));

        $this->assertFalse($result->isAccepted());
        $this->assertSame(InvoiceProblem::TOTAL_MISMATCH, $result->problems[0]->kind);
        $this->assertSame(11700, $result->problems[0]->expectedCents);
        $this->assertSame(11701, $result->problems[0]->foundCents);
    }

    public function testADocumentWithNoTotalIsRefusedRatherThanTrusted(): void
    {
        $result = $this->parse(array_slice(self::oneGoodLine(), 0, 4));

        $this->assertFalse($result->isAccepted());
        $this->assertSame(InvoiceProblem::TOTAL_MISSING, $result->problems[0]->kind);
    }

    public function testADocumentWithNoTariffLineAtAllIsRefused(): void
    {
        $result = $this->parse(['Les Scouts ASBL', 'Rien à voir ici']);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(InvoiceProblem::NO_LINE_FOUND, $result->problems[0]->kind);
    }

    // -- 4. Page-break repetition ----------------------------------------

    public function testTheSameTupleSeenTwiceIsOneLineWhoseListsAreMerged(): void
    {
        $repeated = [
            'COT_NORM Cotisation normale SV025B1 39,00 3 117,00',
            'DUBOIS Basile 12/04/2016 Animé',
            'PISSOORT Anouk 03/11/2015 Animé',
        ];
        $result = $this->parse(array_merge(
            $repeated,
            ['Page 1/2', 'Référence Description Section P.U. Qt. Montant'],
            $repeated,
            ['LAMBERT Noé 27/08/2016 Animé', 'TOTAL A PAYER 117,00']
        ));

        $this->assertTrue($result->isAccepted());
        $this->assertCount(1, $result->invoice?->lines ?? []);
        $this->assertCount(3, $result->invoice?->lines[0]->people ?? []);
        $this->assertSame(11700, $result->invoice?->recomputedTotalCents());
    }

    /**
     * Two lines that differ only by section are two lines, not a
     * repetition — the tuple carries the section for exactly this reason.
     */
    public function testTwoSectionsOnTheSameReferenceStayTwoLines(): void
    {
        $result = $this->parse([
            'COT_NORM Cotisation normale SV025B1 39,00 1 39,00',
            'DUBOIS Basile 12/04/2016 Animé',
            'COT_NORM Cotisation normale SV025L1 39,00 1 39,00',
            'MARTIN Zoé 30/05/2013 Animé',
            'TOTAL A PAYER 78,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $this->assertCount(2, $result->invoice?->lines ?? []);
    }

    // -- 5. Tolerating the unknown, and counting it ----------------------

    public function testEveryUnrecognisedRowIsCountedRatherThanFailing(): void
    {
        $result = $this->parse(array_merge(
            ['Les Scouts ASBL', 'Référence Description Section P.U. Qt. Montant'],
            self::oneGoodLine(),
            ['Page 1/1']
        ));

        $this->assertTrue($result->isAccepted());
        $this->assertSame(3, $result->invoice?->ignoredRowCount);
    }

    public function testTheIgnoredCountSurvivesARefusalToo(): void
    {
        // The import screen compares this figure against the last accepted
        // reading, so a refused document has to carry it as well.
        $result = $this->parse(array_merge(['Les Scouts ASBL'], self::oneGoodLine(99999)));

        $this->assertFalse($result->isAccepted());
        $this->assertSame(1, $result->ignoredRowCount);
    }

    // -- 6. Belgian numbers ----------------------------------------------

    public function testAThousandsSeparatorInTheTotalDoesNotBreakTheSum(): void
    {
        $result = $this->parse([
            'COT_NORM Cotisation normale SV025B1 39,00 32 1' . "\u{00A0}" . '248,00',
            ...array_map(
                static fn(int $i): string => 'NOM' . $i . ' Prenom' . $i . ' 0' . (($i % 9) + 1) . '/01/2016 Animé',
                range(1, 32)
            ),
            'TOTAL A PAYER 1' . "\u{00A0}" . '248,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $this->assertSame(124800, $result->invoice?->totalCents);
    }

    // -- Sections and people ---------------------------------------------

    public function testALineWithNoSectionIsNotADefect(): void
    {
        $result = $this->parse([
            'COT_iAM_LOCAL Cotisation iAM (local) 25,00 1 25,00',
            'vandenberg lucie 08/12/2009 Animé',
            'TOTAL A PAYER 25,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $this->assertNull($result->invoice?->lines[0]->sectionCode);
    }

    public function testTheUnitStaffLabelMapsOntoTheSitesOwnCode(): void
    {
        $result = $this->parse([
            "COT_NORM Cotisation normale Staff d'unité 39,00 1 39,00",
            'DUPONT Sophie 22/06/1995 Animateur',
            'TOTAL A PAYER 39,00',
        ]);

        $this->assertSame('STAFFDU', $result->invoice?->lines[0]->sectionCode);
    }

    public function testATypographicApostropheInTheStaffLabelIsTheSameWord(): void
    {
        $result = $this->parse([
            "COT_NORM Cotisation normale Staff d\u{2019}unité 39,00 1 39,00",
            'DUPONT Sophie 22/06/1995 Animateur',
            'TOTAL A PAYER 39,00',
        ]);

        $this->assertSame('STAFFDU', $result->invoice?->lines[0]->sectionCode);
    }

    /**
     * Twins are registered together and appear on the same invoice. A key
     * built on surname + birth date would merge them, and the name count
     * would then disagree with the quantity on the one line where it
     * matters.
     */
    public function testTwinsAreTwoPeopleAndNotOne(): void
    {
        $result = $this->parse([
            'COT_COUPLE Cotisation couple SV025E1 35,00 2 70,00',
            'BASTIN Théo 19/02/2010 Animé',
            'BASTIN Manon 19/02/2010 Animé',
            'TOTAL A PAYER 70,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $people = $result->invoice?->lines[0]->people ?? [];
        $this->assertCount(2, $people);
        $this->assertNotSame($people[0]->matchKey(), $people[1]->matchKey());
    }

    public function testTheSamePersonRepeatedByAPageBreakIsCountedOnce(): void
    {
        $result = $this->parse([
            'COT_NORM Cotisation normale SV025B1 39,00 1 39,00',
            'PISSOORT Anouk 03/11/2015 Animé',
            'COT_NORM Cotisation normale SV025B1 39,00 1 39,00',
            // The same person, spelled differently by the same document.
            'Pissoort Anouk 03/11/2015 Animé',
            'TOTAL A PAYER 39,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $this->assertCount(1, $result->invoice?->lines[0]->people ?? []);
    }

    public function testANominativeLineWithNoFunctionStillReads(): void
    {
        $result = $this->parse([
            'COT_NORM Cotisation normale SV025B1 39,00 1 39,00',
            'DUBOIS Basile 12/04/2016',
            'TOTAL A PAYER 39,00',
        ]);

        $this->assertTrue($result->isAccepted());
        $this->assertNull($result->invoice?->lines[0]->people[0]->functionLabel);
    }

    public function testTheFooterIsRead(): void
    {
        $result = $this->parse(array_merge(
            ['Facture n° F2026/000123', 'Date : 08/01/2026'],
            self::oneGoodLine(),
            ['IBAN : BE71 0961 2345 6769', 'Communication structurée : +++123/4567/89012+++', 'Report0024 v.01']
        ));

        $invoice = $result->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame('F2026/000123', $invoice->documentNumber);
        $this->assertSame('2026-01-08', $invoice->issueDate);
        $this->assertSame('BE71096123456769', $invoice->iban);
        $this->assertSame('+++123/4567/89012+++', $invoice->structuredCommunication);
        $this->assertSame('Report0024 v.01', $invoice->templateNumber);
    }

    /** A template number is noted, never a condition. */
    public function testADocumentWithNoTemplateNumberIsStillRead(): void
    {
        $result = $this->parse(self::oneGoodLine());

        $this->assertTrue($result->isAccepted());
        $this->assertNull($result->invoice?->templateNumber);
    }
}
