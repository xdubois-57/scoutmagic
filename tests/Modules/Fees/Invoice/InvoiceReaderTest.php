<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

use Core\File\PdfTextExtractor;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceProblem;
use Modules\Fees\Invoice\InvoiceReader;
use PHPUnit\Framework\TestCase;

/**
 * The golden test: a whole invoice, through the real PDF text extractor,
 * recomputed to the centime.
 *
 * `tests/fixtures/pdf/federation_invoice_sample.pdf` reproduces the shape
 * of a real federation invoice — column order, reference codes, nominative
 * lists, Belgian numbers, a page-break repetition, the footer, the template
 * number — with invented names and figures. Its generator sits beside it
 * and says so at length; when a real invoice can be anonymised, the data in
 * that generator is what gets replaced, and these assertions, written
 * against totals and shapes rather than names, keep holding.
 */
class InvoiceReaderTest extends TestCase
{
    private InvoiceReader $reader;
    private string $fixture;

    protected function setUp(): void
    {
        $this->reader = new InvoiceReader(new PdfTextExtractor(), new InvoiceParser());
        $path = dirname(__DIR__, 3) . '/fixtures/pdf/federation_invoice_sample.pdf';
        $content = file_get_contents($path);
        $this->assertIsString($content, $path);
        $this->fixture = $content;
    }

    public function testTheWholeInvoiceIsReadAndFallsOnItsTotalToTheCent(): void
    {
        $result = $this->reader->read($this->fixture);

        $this->assertTrue($result->isAccepted(), implode("\n", array_map(
            static fn(InvoiceProblem $p): string => $p->message,
            $result->problems
        )));

        $invoice = $result->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame(106900, $invoice->totalCents);
        $this->assertSame($invoice->totalCents, $invoice->recomputedTotalCents());
    }

    public function testEveryLineOfTheDocumentIsThereWithItsNature(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;
        $this->assertNotNull($invoice);

        $actual = array_map(
            static fn(InvoiceLine $line): array => [
                $line->reference,
                $line->sectionCode,
                $line->unitPriceCents,
                $line->quantity,
                $line->amountCents,
                count($line->people),
                $line->nature(),
            ],
            $invoice->lines
        );

        $this->assertSame([
            ['COT_NORM', 'SV025B1', 3900, 12, 46800, 12, InvoiceLine::NATURE_FEE],
            ['COT_NORM', 'SV025L1', 3900, 10, 39000, 10, InvoiceLine::NATURE_FEE],
            ['COT_NORM', 'STAFFDU', 3900, 8, 31200, 8, InvoiceLine::NATURE_FEE],
            ['COT_FAM', 'SV025L1', 3100, 4, 12400, 4, InvoiceLine::NATURE_FEE],
            ['COT_COUPLE', 'SV025E1', 3500, 2, 7000, 2, InvoiceLine::NATURE_FEE],
            // The Iama are not exempt, and their line carries no section.
            ['COT_iAM_LOCAL', null, 2500, 1, 2500, 1, InvoiceLine::NATURE_FEE],
            ['RED_ANIM_BREV', 'STAFFDU', -1000, 2, -2000, 2, InvoiceLine::NATURE_REDUCTION],
            // Negative and nameless: the deposit already invoiced.
            ['COT_ACOMPTE', null, -30000, 1, -30000, 0, InvoiceLine::NATURE_ADJUSTMENT],
        ], $actual);
    }

    /**
     * The fixture repeats one whole block across the page break, the way a
     * real invoice does. It has to read as one line whose lists merge.
     */
    public function testThePageBreakRepetitionIsOneLineAndNotTwo(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;
        $this->assertNotNull($invoice);

        $family = array_values(array_filter(
            $invoice->lines,
            static fn(InvoiceLine $line): bool => $line->reference === 'COT_FAM'
        ));

        $this->assertCount(1, $family);
        $this->assertCount(4, $family[0]->people);
    }

    public function testTheFooterIsRead(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;
        $this->assertNotNull($invoice);

        $this->assertSame('F2026/000123', $invoice->documentNumber);
        $this->assertSame('2026-01-08', $invoice->issueDate);
        $this->assertSame('BE71096123456769', $invoice->iban);
        $this->assertSame('+++123/4567/89012+++', $invoice->structuredCommunication);
        $this->assertSame('Report0024 v.01', $invoice->templateNumber);
    }

    /**
     * The number itself means nothing; a jump in it from one import to the
     * next is the only warning this parser can give that the template
     * changed. Pinned so a change to the fixture's chrome is noticed.
     */
    public function testTheIgnoredRowsAreCounted(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;

        $this->assertSame(7, $invoice?->ignoredRowCount);
    }

    public function testTheSectionsItNamesAreTheOnesToMatchOnDeskCode(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;

        $this->assertSame(['SV025B1', 'SV025L1', 'STAFFDU', 'SV025E1'], $invoice?->sectionCodes());
    }

    public function testTwinsOnTheSameLineAreTwoPeople(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;
        $this->assertNotNull($invoice);

        $couple = array_values(array_filter(
            $invoice->lines,
            static fn(InvoiceLine $line): bool => $line->reference === 'COT_COUPLE'
        ))[0];

        $this->assertSame('2010-02-19', $couple->people[0]->birthDate);
        $this->assertSame($couple->people[0]->birthDate, $couple->people[1]->birthDate);
        $this->assertNotSame($couple->people[0]->matchKey(), $couple->people[1]->matchKey());
    }

    public function testTheDocumentsInconsistentCasingFoldsToOneIdentity(): void
    {
        $invoice = $this->reader->read($this->fixture)->invoice;
        $this->assertNotNull($invoice);

        $keys = [];
        foreach ($invoice->people() as $person) {
            $keys[$person->matchKey()] = $person->lastName;
        }

        // "DUPONT Sophie" is billed on one line and reduced on another,
        // spelled the same; "Lemaire Marc" is spelled in title case
        // throughout. Either way each is one identity.
        $this->assertArrayHasKey('dupont|sophie|1995-06-22', $keys);
        $this->assertArrayHasKey('lemaire|marc|1990-01-10', $keys);
        $this->assertArrayHasKey('vandenberg|lucie|2009-12-08', $keys);
    }

    /** One cent, and the whole document is refused, naming the line. */
    public function testAnInvoiceTamperedWithByOneCentIsRefused(): void
    {
        $tampered = $this->tamper('468,00', '468,01');

        $result = $this->reader->read($tampered);

        $this->assertFalse($result->isAccepted());
        $kinds = array_map(static fn(InvoiceProblem $p): string => $p->kind, $result->problems);
        $this->assertContains(InvoiceProblem::LINE_ARITHMETIC, $kinds);
        $this->assertSame('COT_NORM', $result->problems[0]->reference);
        $this->assertSame('SV025B1', $result->problems[0]->sectionCode);
    }

    public function testATamperedTotalIsRefusedWithBothFigures(): void
    {
        $tampered = $this->tamper('1\240069,00', '1\240069,01');

        $result = $this->reader->read($tampered);

        $this->assertFalse($result->isAccepted());
        $problem = $result->problems[0];
        $this->assertSame(InvoiceProblem::TOTAL_MISMATCH, $problem->kind);
        $this->assertSame(106900, $problem->expectedCents);
        $this->assertSame(106901, $problem->foundCents);
    }

    public function testAPdfWithNoTextLayerIsRefusedAsScannedRatherThanAsEmpty(): void
    {
        $result = $this->reader->read('%PDF-1.4 not really a pdf');

        $this->assertFalse($result->isAccepted());
        $this->assertSame(InvoiceProblem::NO_TEXT_LAYER, $result->problems[0]->kind);
        $this->assertStringContainsString('scanné', $result->problems[0]->message);
    }

    /**
     * The fixture is generated; a change to the generator that nobody
     * re-ran would leave the committed PDF describing a different invoice
     * from the one the generator documents.
     */
    public function testTheCommittedFixtureMatchesItsGenerator(): void
    {
        $generator = dirname(__DIR__, 3) . '/fixtures/pdf/generate-federation-invoice.php';
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($generator) . ' --check 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * The fixture is a hand-assembled PDF whose text lives in plain
     * `(…) Tj` strings, so one amount can be replaced in the bytes without
     * re-rendering anything. The content stream's declared /Length stays
     * right because the replacement is the same size.
     */
    private function tamper(string $from, string $to): string
    {
        $this->assertSame(strlen($from), strlen($to), 'The replacement must keep the stream length.');
        $tampered = str_replace($from, $to, $this->fixture, $count);
        $this->assertSame(1, $count, "Expected exactly one occurrence of $from in the fixture.");

        return $tampered;
    }
}
