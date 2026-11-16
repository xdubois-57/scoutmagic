<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceProblem;
use Modules\Fees\Invoice\InvoiceReadResult;
use PHPUnit\Framework\TestCase;

/**
 * The layout of the federation's own export (#1327), which a real invoice
 * was refused in: « Aucune ligne de tarif n'a été reconnue ».
 *
 * {@see FederationExportFixture} reproduces its shape with invented
 * figures; every rule the parser already had — arithmetic per line, names
 * = quantity, Σ = total, page-break repetitions — still applies to it.
 */
class InvoiceParserFederationExportTest extends TestCase
{
    private InvoiceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InvoiceParser();
    }

    public function testTheWholeTenPageInvoiceIsReadAndFallsOnItsTotal(): void
    {
        $result = $this->parser->parse(FederationExportFixture::text());

        $this->assertTrue($result->isAccepted(), self::problems($result));
        $invoice = $result->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame(FederationExportFixture::TOTAL_CENTS, $invoice->totalCents);
        $this->assertSame($invoice->totalCents, $invoice->recomputedTotalCents());
        $this->assertSame('FACT-00042', $invoice->documentNumber);
        $this->assertSame('2025-03-13', $invoice->issueDate);
        $this->assertSame('BE71096123456769', $invoice->iban);
        $this->assertCount(count(FederationExportFixture::rows()), $invoice->lines);
        $this->assertSame(10, $result->stats->pageCount);
    }

    public function testEveryLineKeepsItsSectionQuantityAndNature(): void
    {
        $invoice = $this->parser->parse(FederationExportFixture::text())->invoice;
        $this->assertNotNull($invoice);

        $actual = array_map(
            static fn(InvoiceLine $l): string => sprintf(
                '%s %s %d×%d=%d %d %s',
                $l->reference,
                $l->sectionCode ?? '-',
                $l->unitPriceCents,
                $l->quantity,
                $l->amountCents,
                count($l->people),
                $l->nature()
            ),
            $invoice->lines
        );

        $this->assertSame([
            'COT_COUPLE SV025B1 4325×9=38925 9 fee',
            'COT_FAM SV025B1 3675×4=14700 4 fee',
            'COT_NORM SV025B1 5425×18=97650 18 fee',
            'COT_COUPLE SV025L1 4325×16=69200 16 fee',
            'COT_FAM SV025L1 3675×6=22050 6 fee',
            'COT_NORM SV025L1 5425×60=325500 60 fee',
            'COT_NORM SV025E1 5425×70=379750 70 fee',
            'COT_NORM SV025P1 5425×42=227850 42 fee',
            'COT_ACOMPTE - 1001700×-1=-1001700 0 adjustment',
            'COT_COUPLE STAFFDU 4325×1=4325 1 fee',
            'COT_FAM STAFFDU 3675×1=3675 1 fee',
            'COT_NORM STAFFDU 5425×1=5425 1 fee',
            'RED_ANIM_BREV - -500×5=-2500 5 reduction',
        ], $actual);
    }

    /**
     * Seven rows are printed twice, once at the top of the next page; they
     * are one line each, their lists merged, never counted twice.
     */
    public function testAPageTopRepetitionIsOneLineWithItsListMerged(): void
    {
        $result = $this->parser->parse(FederationExportFixture::text());

        $this->assertSame(7, $result->stats->repeatedTariffRows);
        $this->assertSame(count(FederationExportFixture::rows()), $result->stats->distinctTariffLines);
    }

    /** The three lines the ticket names, as the export prints them. */
    public function testTheTicketsThreeLinesAreRead(): void
    {
        $lines = $this->lines([
            "SV025B1COTISATION COUPLECOT_COUPLE\t9,00 389,2543,25",
            "REDUCTION ANIMATEUR BREVETERED_ANIM_BREV\t5,00 -25,00-5,00",
            "CALCUL ACOMPTECOT_ACOMPTE\t-1,00 -10\u{00A0}017,0010\u{00A0}017,00",
        ]);

        $this->assertSame([
            ['COT_COUPLE', 4325, 9, 38925],
            ['RED_ANIM_BREV', -500, 5, -2500],
            ['COT_ACOMPTE', 1001700, -1, -1001700],
        ], array_map(
            static fn(InvoiceLine $l): array => [$l->reference, $l->unitPriceCents, $l->quantity, $l->amountCents],
            $lines
        ));
    }

    public function testThousandsSeparatedByAnOrdinarySpaceAreReadToo(): void
    {
        $lines = $this->lines(["SV025B2COTISATION NORMALECOT_NORM\t22,00 1 193,5054,25"]);

        $this->assertSame(119350, $lines[0]->amountCents);
        $this->assertSame(5425, $lines[0]->unitPriceCents);
        $this->assertSame(22, $lines[0]->quantity);
    }

    public function testATabSeparatedNameKeepsACompoundFirstNameWhole(): void
    {
        $result = $this->parser->parse(implode("\n", [
            "SV025B1COTISATION NORMALECOT_NORM\t1,00 54,2554,25",
            "Van Den Broeck\tMarie Louise\t04/05/2014 Animé",
            "54,25 €TOTAL A PAYER:",
        ]));

        $this->assertTrue($result->isAccepted(), self::problems($result));
        $person = $result->invoice?->lines[0]->people[0];
        $this->assertNotNull($person);
        $this->assertSame('Van Den Broeck', $person->lastName);
        $this->assertSame('Marie Louise', $person->firstName);
        $this->assertSame('2014-05-04', $person->birthDate);
    }

    /** `2,50` is not a number of people: refused, never truncated to 2. */
    public function testAFractionalQuantityIsRefused(): void
    {
        $result = $this->parser->parse(implode("\n", [
            "SV025B1COTISATION NORMALECOT_NORM\t2,50 135,6354,25",
            '135,63 €TOTAL A PAYER:',
        ]));

        $this->assertFalse($result->isAccepted());
        $this->assertContains(InvoiceProblem::FRACTIONAL_QUANTITY, self::kinds($result));
    }

    /**
     * A row that looks like money and was not understood refuses the whole
     * document — even when the rows that were understood add up, a partial
     * reading is never accepted.
     */
    public function testAFinancialRowThatWasNotReadRefusesTheDocumentEvenIfTheRestAddsUp(): void
    {
        $result = $this->parser->parse(implode("\n", [
            "SV025B1COTISATION NORMALECOT_NORM\t1,00 54,2554,25",
            "Nomaa\tNomab\t04/05/2014 Animé",
            "SV025B1COTISATION SPECIALE\t1,00 10,0010,00",
            '54,25 €TOTAL A PAYER:',
        ]));

        $this->assertFalse($result->isAccepted());
        $this->assertSame([InvoiceProblem::UNRECOGNISED_LINE], self::kinds($result));
        $this->assertTrue($result->problems[0]->isFormatProblem());
        $this->assertStringContainsString('ligne(s) 3 du texte', $result->problems[0]->message);
        $this->assertStringNotContainsString('Nomaa', $result->problems[0]->message);
    }

    public function testAnAmountThatIsNotUnitPriceTimesQuantityIsStillAnArithmeticRefusal(): void
    {
        $result = $this->parser->parse(implode("\n", [
            "SV025B1COTISATION COUPLECOT_COUPLE\t9,00 389,2643,25",
            '389,26 €TOTAL A PAYER:',
        ]));

        $this->assertSame([InvoiceProblem::LINE_ARITHMETIC], self::kinds($result));
        $this->assertFalse($result->problems[0]->isFormatProblem());
        $this->assertSame('arithmetic', $result->problems[0]->stage());
    }

    public function testATotalPrintedAfterItsLabelIsStillRead(): void
    {
        $lines = FederationExportFixture::text();
        $moved = str_replace("1\u{00A0}848,50 €TOTAL A PAYER:", "TOTAL A PAYER : 1\u{00A0}848,50 €", $lines);

        $this->assertTrue($this->parser->parse($moved)->isAccepted());
    }

    public function testAMissingTotalIsAFormatProblemNotAMismatch(): void
    {
        $text = str_replace("1\u{00A0}848,50 €TOTAL A PAYER:", '', FederationExportFixture::text());
        $result = $this->parser->parse($text);

        $this->assertSame([InvoiceProblem::TOTAL_MISSING], self::kinds($result));
        $this->assertTrue($result->problems[0]->isFormatProblem());
    }

    /** The statistics say which shape came in, and hold nothing of its text. */
    public function testTheStatisticsDescribeTheLayoutWithoutAWordOfTheDocument(): void
    {
        $stats = $this->parser->parse(FederationExportFixture::text())->stats->toArray();

        $this->assertSame(20, $stats['glued_tariff_rows']);
        $this->assertSame(0, $stats['spaced_tariff_rows']);
        $this->assertSame(20, $stats['decimal_quantities']);
        $this->assertSame(1, $stats['negative_quantities']);
        $this->assertTrue($stats['tabs_seen']);
        $this->assertTrue($stats['total_found']);
        $this->assertSame(0, $stats['orphan_person_rows']);
        $dump = (string) json_encode($stats);
        $this->assertStringNotContainsString('Nom', $dump);
        $this->assertStringNotContainsString('FACT-00042', $dump);
        $this->assertStringNotContainsString('COT_', $dump);
    }

    /**
     * The rows read as a document of their own, closed by the total their
     * amounts make.
     *
     * @param string[] $rows
     * @return InvoiceLine[]
     */
    private function lines(array $rows): array
    {
        $total = 0;
        foreach ($rows as $row) {
            preg_match('/\t\S+ (-?[\d\x{00A0} ]+,\d{2})/u', $row, $amount);
            $total += (int) str_replace([',', "\u{00A0}", ' '], '', $amount[1]);
        }

        $result = $this->parser->parse(
            implode("\n", $rows) . "\n" . FederationExportFixture::money($total) . ' €TOTAL A PAYER:'
        );
        $this->assertTrue($result->isAccepted(), self::problems($result));

        return $result->invoice?->lines ?? [];
    }

    /** @return string[] */
    private static function kinds(InvoiceReadResult $result): array
    {
        return array_map(static fn(InvoiceProblem $p): string => $p->kind, $result->problems);
    }

    private static function problems(InvoiceReadResult $result): string
    {
        return implode("\n", array_map(static fn(InvoiceProblem $p): string => $p->message, $result->problems));
    }
}
