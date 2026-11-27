<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\PdfTextExtractor;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceProblem;
use Modules\Fees\Invoice\InvoiceReader;
use Modules\Fees\Repository\InvoiceMemberMatchRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Service\InvoiceImportService;
use Modules\Fees\Value\InvoiceImportOutcome;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\Invoice\FederationExportFixture;
use Tests\Modules\Fees\FeesTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Three states, and two of them are failures — deliberately different
 * ones, because a document that does not add up and a site that is behind
 * Desk need answers from different people.
 *
 * Everything here goes through the real golden PDF
 * (`tests/fixtures/pdf/federation_invoice_sample.pdf`) and the real text
 * extractor: a parser tested only against strings it was written for
 * proves the strings, not the reading.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InvoiceImportService $service;
    private InvoiceRepository $invoices;
    private RosterSnapshotRepository $snapshots;
    private int $scoutYearId;
    private string $pdf;

    /** Every section the golden invoice names. */
    private const DOCUMENT_SECTIONS = ['SV025B1', 'SV025L1', 'STAFFDU', 'SV025E1', 'SV025P1'];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        foreach (self::DOCUMENT_SECTIONS as $code) {
            $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, '{$code}', 'Section {$code}')");
        }

        $this->invoices = new InvoiceRepository($this->pdo);
        $this->snapshots = new RosterSnapshotRepository($this->pdo);
        $this->service = new InvoiceImportService(
            new InvoiceReader(new PdfTextExtractor(), new InvoiceParser()),
            $this->invoices,
            new InvoiceMemberMatchRepository($this->pdo, $this->encryption),
            $this->snapshots,
            new SectionService(
    new SectionRepository(Connection::withPdo($this->pdo)),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo))
),
            new JournalService(new JournalRepository($this->pdo)),
            new \Core\Config\ScoutYearService($this->pdo)
        );

        $content = file_get_contents(dirname(__DIR__, 3) . '/fixtures/pdf/federation_invoice_sample.pdf');
        $this->assertIsString($content);
        $this->pdf = $content;
    }

    public function testTheGoldenInvoiceIsImportedWholeWithItsLines(): void
    {
        $outcome = $this->service->import($this->pdf, $this->scoutYearId, 7);

        $this->assertSame(InvoiceImportOutcome::IMPORTED, $outcome->status);
        $this->assertSame('F2026/000123', $outcome->documentNumber);
        $this->assertNotNull($outcome->invoiceId);

        $stored = $this->invoices->findById($outcome->invoiceId);
        $this->assertNotNull($stored);
        $this->assertSame(106900, $stored->totalCents);
        $this->assertSame('2026-01-08', $stored->issueDate);
        $this->assertCount(8, $this->invoices->findLines($outcome->invoiceId));
    }

    /**
     * The identity is the document number: a treasurer who is not sure
     * whether they already imported January must be able to just try.
     */
    public function testImportingTheSameDocumentTwiceCreatesOneInvoice(): void
    {
        $first = $this->service->import($this->pdf, $this->scoutYearId, null);
        $second = $this->service->import($this->pdf, $this->scoutYearId, null);

        $this->assertSame(InvoiceImportOutcome::ALREADY_IMPORTED, $second->status);
        $this->assertSame($first->invoiceId, $second->invoiceId);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    /**
     * The file is at fault. Nothing lands — there is no partial import and
     * no "imported with warnings".
     */
    public function testADocumentThatDoesNotAddUpIsRefusedAndStoresNothing(): void
    {
        $outcome = $this->service->import($this->tamperedByOneCent(), $this->scoutYearId, null);

        $this->assertSame(InvoiceImportOutcome::REFUSED, $outcome->status);
        $this->assertNotSame([], $outcome->problems);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    public function testSomethingThatIsNotAPdfAtAllIsRefusedRatherThanCrashing(): void
    {
        $outcome = $this->service->import('bonjour, ceci est un scan', $this->scoutYearId, null);

        $this->assertSame(InvoiceImportOutcome::REFUSED, $outcome->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    /**
     * The document is fine and the SITE is behind. Nothing is stored, and
     * the codes are named so the screen can say which ones — there is
     * deliberately no hand-mapping screen: an unknown code is an
     * out-of-date roster, not a forgotten piece of data.
     */
    public function testASectionTheSiteDoesNotKnowIsAStaleRosterNotARefusal(): void
    {
        $this->pdo->exec("DELETE FROM sections WHERE desk_code = 'SV025E1'");

        $outcome = $this->service->import($this->pdf, $this->scoutYearId, null);

        $this->assertSame(InvoiceImportOutcome::STALE_ROSTER, $outcome->status);
        $this->assertSame(['SV025E1'], $outcome->unknownSectionCodes);
        $this->assertSame('F2026/000123', $outcome->documentNumber);
        $this->assertSame(106900, $outcome->totalCents);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    public function testAStaleRosterIsToldApartFromAFileThatDoesNotAddUp(): void
    {
        $this->pdo->exec("DELETE FROM sections WHERE desk_code = 'SV025E1'");

        $stale = $this->service->import($this->pdf, $this->scoutYearId, null);
        $refused = $this->service->import($this->tamperedByOneCent(), $this->scoutYearId, null);

        $this->assertNotSame($stale->status, $refused->status);
        $this->assertSame([], $stale->problems);
        $this->assertSame([], $refused->unknownSectionCodes);
    }

    public function testTheInvoiceIsTiedToTheSnapshotClosestBeforeItsIssueDate(): void
    {
        $tooEarly = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2025-11-02 08:30:00'));
        $right = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));
        // Taken after the invoice was issued: it describes a roster the
        // federation had not seen, so it must not be the one compared.
        $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-03-01 07:00:00'));

        $outcome = $this->service->import($this->pdf, $this->scoutYearId, null);
        $this->assertNotNull($outcome->invoiceId);

        $snapshotId = $this->invoices->findById($outcome->invoiceId)?->snapshotId;
        $this->assertSame($right->id, $snapshotId);
        $this->assertNotSame($tooEarly->id, $snapshotId);
    }

    /**
     * An invoice predating every snapshot is still imported: the module
     * was activated late, which the verification report says with a date
     * gap rather than by refusing to hold the document.
     */
    public function testAnInvoiceOlderThanEverySnapshotIsStillImported(): void
    {
        $late = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-03-01 07:00:00'));

        $outcome = $this->service->import($this->pdf, $this->scoutYearId, null);

        $this->assertSame(InvoiceImportOutcome::IMPORTED, $outcome->status);
        $this->assertNotNull($outcome->invoiceId);
        $this->assertSame($late->id, $this->invoices->findById($outcome->invoiceId)?->snapshotId);
    }

    public function testWithNoSnapshotAtAllTheInvoiceIsKeptWithoutOne(): void
    {
        $outcome = $this->service->import($this->pdf, $this->scoutYearId, null);

        $this->assertNotNull($outcome->invoiceId);
        $this->assertNull($this->invoices->findById($outcome->invoiceId)?->snapshotId);
    }

    /** A member the document names is tied to their members.id, by key. */
    public function testAPersonTheRosterHoldsIsResolvedToAMemberId(): void
    {
        $names = $this->namesOnFirstFeeLine();
        $memberId = $this->createMember($names['first'], $names['last'], $names['birth']);

        $outcome = $this->service->import($this->pdf, $this->scoutYearId, null);
        $this->assertNotNull($outcome->invoiceId);

        $matched = [];
        foreach ($this->invoices->findLines($outcome->invoiceId) as $line) {
            $matched = array_merge($matched, $line->memberIds);
        }

        $this->assertSame([$memberId], $matched);
    }

    /** No personal data in the journal — identifiers and counters only. */
    public function testTheJournalCarriesCountersAndIdentifiersOnly(): void
    {
        $this->service->import($this->pdf, $this->scoutYearId, 7);

        $rows = $this->pdo->query('SELECT event_type, description, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotSame([], $rows);

        $dump = json_encode($rows);
        $this->assertIsString($dump);
        $this->assertStringContainsString('fees_invoice_imported', $dump);
        $this->assertStringNotContainsStringIgnoringCase('dubois', $dump);
        $this->assertStringNotContainsString('F2026/000123', $dump);
    }

    public function testARefusalAndAStaleRosterAreJournalledDifferently(): void
    {
        $this->pdo->exec("DELETE FROM sections WHERE desk_code = 'SV025E1'");
        $this->service->import($this->pdf, $this->scoutYearId, null);
        $this->service->import($this->tamperedByOneCent(), $this->scoutYearId, null);

        $types = $this->pdo->query('SELECT event_type FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['fees_invoice_stale_roster', 'fees_invoice_refused'], $types);
    }

    /**
     * The layout of the federation's own export (#1327), refused before as
     * « Aucune ligne de tarif n'a été reconnue », through the whole import.
     */
    public function testAnInvoiceInTheFederationsExportLayoutIsImported(): void
    {
        // The export is dated in another season than the one this test
        // files it in: the year is confirmed, which is not what this test
        // is about (#1468 has its own).
        $service = $this->serviceReading(FederationExportFixture::text());
        $asked = $service->import('%PDF', $this->scoutYearId, 7);
        $outcome = $service->import('%PDF', $this->scoutYearId, 7, $this->scoutYearId, $asked->documentNumber);

        $this->assertSame(InvoiceImportOutcome::IMPORTED, $outcome->status, implode("\n", array_map(
            static fn(InvoiceProblem $p): string => $p->message,
            $outcome->problems
        )));
        $this->assertNotNull($outcome->invoiceId);
        $stored = $this->invoices->findById($outcome->invoiceId);
        $this->assertNotNull($stored);
        $this->assertSame(FederationExportFixture::TOTAL_CENTS, $stored->totalCents);
        $this->assertCount(count(FederationExportFixture::rows()), $this->invoices->findLines($outcome->invoiceId));
    }

    /**
     * A refusal is journaled so the support can rebuild the case without
     * the PDF: the stage, stable codes and the reading's counts — never a
     * name, a date of birth, the document number or a line of its text.
     */
    public function testARefusedReadingIsJournaledWithItsStageCodesAndCountsOnly(): void
    {
        $text = str_replace(
            "SV025P1COTISATION NORMALECOT_NORM",
            "SV025P1COTISATION NORMALE",
            FederationExportFixture::text()
        );

        $outcome = $this->serviceReading($text)->import('%PDF', $this->scoutYearId, 7);

        $this->assertSame(InvoiceImportOutcome::REFUSED, $outcome->status);
        $this->assertTrue($outcome->isUnreadable());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());

        $row = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'fees_invoice_refused'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $context = json_decode((string) $row['context'], true);
        $this->assertIsArray($context);
        $this->assertSame('recognition', $context['stage']);
        $this->assertContains(InvoiceProblem::UNRECOGNISED_LINE, $context['codes']);
        $this->assertSame(10, $context['reading']['pages']);
        $this->assertGreaterThan(0, $context['reading']['unrecognised_financial_rows']);
        $this->assertTrue($context['reading']['tabs_seen']);
        $this->assertStringContainsString('format', (string) $row['description']);

        $dump = (string) json_encode($row);
        $this->assertStringNotContainsString('Nom', $dump, 'no name');
        $this->assertStringNotContainsString('/2014', $dump, 'no date of birth');
        $this->assertStringNotContainsString('FACT-00042', $dump);
        $this->assertStringNotContainsString('COTISATION', $dump, 'no line of text');
        $this->assertStringNotContainsString('BE71', $dump);
    }

    public function testAnArithmeticRefusalIsJournaledAsSuchAndNotAsAFormat(): void
    {
        $this->service->import($this->tamperedByOneCent(), $this->scoutYearId, null);

        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'fees_invoice_refused'"
        )->fetchColumn(), true);
        $this->assertIsArray($context);
        $this->assertSame('arithmetic', $context['stage']);
        $this->assertSame([InvoiceProblem::TOTAL_MISMATCH], $context['codes']);
        $this->assertTrue($context['reading']['total_found']);
    }

    public function testAnAmbiguousRepetitionIsJournaledWithoutCallingItAFormatOrTotalError(): void
    {
        $text = implode("\n", [
            'COT_NORM Cotisation normale ZX777B1 40,00 1 40,00', 'Page 1/2',
            'COT_NORM Cotisation normale ZX777B1 40,00 1 40,00', 'TOTAL A PAYER 80,00',
        ]);
        $outcome = $this->serviceReading($text)->import('%PDF', $this->scoutYearId, null);
        $statement = $this->pdo->prepare('SELECT description, context FROM event_log WHERE event_type = ?');
        $statement->execute(['fees_invoice_refused']);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $context = json_decode((string) $row['context'], true);

        self::assertSame([false, 'interpretation', [InvoiceProblem::AMBIGUOUS_REPETITION],
            'Facture refusée : des lignes identiques sans liste nominative restent ambiguës.'],
            [$outcome->isUnreadable(), $context['stage'], $context['codes'], $row['description']]);
    }

    /** Whatever the PDF library throws, the import answers, and journals no message. */
    public function testAnExtractorThatThrowsIsAReadingRefusalNotAnErrorPage(): void
    {
        $throwing = new class () extends PdfTextExtractor {
            public function extractText(string $pdfContent): ?string
            {
                throw new \RuntimeException('Nomaa Nomab 04/05/2014');
            }
        };
        $outcome = $this->serviceWith($throwing)->import('%PDF', $this->scoutYearId, null);

        $this->assertSame(InvoiceImportOutcome::REFUSED, $outcome->status);
        $this->assertSame(InvoiceProblem::EXTRACTION_FAILED, $outcome->problems[0]->kind);
        $context = (string) $this->pdo->query("SELECT context FROM event_log")->fetchColumn();
        $this->assertStringContainsString('extraction', $context);
        $this->assertStringNotContainsString('Nomaa', $context);
    }

    // ------------------------------------------------ the season (#1468)

    /**
     * Issued on 8 January 2026, in 2025-2026, while 2026-2027 is on
     * screen: asked, never refused, and nothing stored until confirmed —
     * a regularisation can be issued after its season ends. A
     * confirmation given for another year does not count.
     */
    public function testAnIssueDateInAnotherYearAsksForConfirmationBeforeAnythingIsStored(): void
    {
        $next = $this->scoutYear('2026-2027', '2026-09-01', '2027-08-31');

        $asked = $this->service->import($this->pdf, $next, 7);
        $this->assertSame(InvoiceImportOutcome::YEAR_TO_CONFIRM, $asked->status);
        $this->assertSame('2025-2026', $asked->issueYearLabel);
        $this->assertSame('2026-2027', $asked->targetYearLabel);
        $this->assertSame($next, $asked->targetYearId);
        $this->assertSame('2026-01-08', $asked->issueDate);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());

        $wrongConfirmation = $this->service->import($this->pdf, $next, 7, $this->scoutYearId, 'F2026/000123');
        $this->assertSame(InvoiceImportOutcome::YEAR_TO_CONFIRM, $wrongConfirmation->status);

        $this->assertSame(
            InvoiceImportOutcome::YEAR_TO_CONFIRM,
            $this->service->import($this->pdf, $next, 7, $next)->status,
            'a confirmation that names no document confirms none'
        );

        $confirmed = $this->service->import($this->pdf, $next, 7, $next, 'F2026/000123');
        $this->assertSame(InvoiceImportOutcome::IMPORTED, $confirmed->status);
        $this->assertSame($next, $this->invoices->findById((int) $confirmed->invoiceId)?->scoutYearId);
        $this->assertSame(
            ['scout_year_id' => $next, 'issue_year_id' => $this->scoutYearId, 'year_confirmed' => true],
            array_intersect_key($this->journalContext('fees_invoice_imported'), array_flip(['scout_year_id', 'issue_year_id', 'year_confirmed']))
        );
    }

    /**
     * The confirmation was given for one document: sent back with another
     * PDF attached, it does not file that other one (#1548 review) — the
     * question is asked again, about the document actually sent.
     */
    public function testAConfirmationGivenForAnotherDocumentIsNotHonoured(): void
    {
        $next = $this->scoutYear('2026-2027', '2026-09-01', '2027-08-31');

        $outcome = $this->service->import($this->pdf, $next, 7, $next, 'F2026/000999');

        $this->assertSame(InvoiceImportOutcome::YEAR_TO_CONFIRM, $outcome->status);
        $this->assertSame('F2026/000123', $outcome->documentNumber);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    /** In the year its issue date falls in, nothing is asked and nothing is confirmed. */
    public function testAnIssueDateInTheTargetYearIsImportedWithoutAQuestion(): void
    {
        $outcome = $this->service->import($this->pdf, $this->scoutYearId, 7);

        $this->assertSame(InvoiceImportOutcome::IMPORTED, $outcome->status);
        $this->assertFalse($outcome->yearUnchecked);
        $context = $this->journalContext('fees_invoice_imported');
        $this->assertSame($this->scoutYearId, $context['issue_year_id']);
        $this->assertFalse($context['year_confirmed']);
    }

    /**
     * The same document already filed in another year is recognised: no
     * second invoice, the screen names that year, and the journal says so
     * with ids only — no document number (ARCHITECTURE.md §8.6).
     */
    public function testTheSameDocumentInAnotherYearIsRecognisedAndNamesThatYear(): void
    {
        $next = $this->scoutYear('2026-2027', '2026-09-01', '2027-08-31');
        $first = $this->service->import($this->pdf, $this->scoutYearId, 7);

        $again = $this->service->import($this->pdf, $next, 7, $next);

        $this->assertSame(InvoiceImportOutcome::ALREADY_IMPORTED, $again->status);
        $this->assertSame($first->invoiceId, $again->invoiceId);
        $this->assertSame('2025-2026', $again->existingYearLabel);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());

        $row = $this->pdo->query("SELECT level, context FROM event_log WHERE event_type = 'invoice_import_duplicate_other_year'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('warning', $row['level']);
        $this->assertSame(
            ['existing_invoice_id' => $first->invoiceId, 'existing_year_id' => $this->scoutYearId, 'target_year_id' => $next],
            json_decode((string) $row['context'], true)
        );
        $this->assertStringNotContainsString('F2026/000123', (string) $row['context']);
    }

    /** No readable issue date: imported as before, and the outcome says the year went unchecked. */
    public function testAnInvoiceWithoutAReadableIssueDateKeepsTheCurrentBehaviourWithAMention(): void
    {
        $text = (new PdfTextExtractor())->extractText($this->pdf);
        $this->assertIsString($text);
        $withoutDate = str_replace('08/01/2026', '', $text, $count);
        $this->assertGreaterThan(0, $count);
        $next = $this->scoutYear('2026-2027', '2026-09-01', '2027-08-31');

        $outcome = $this->serviceReading($withoutDate)->import('pdf', $next, 7);

        $this->assertSame(InvoiceImportOutcome::IMPORTED, $outcome->status);
        $this->assertTrue($outcome->yearUnchecked);
        $this->assertNull($this->journalContext('fees_invoice_imported')['issue_year_id']);
    }

    /**
     * A date that is not a real day (« 31/02/2026 ») is no more readable
     * than a missing one: imported, and said unchecked (#1548 review).
     */
    public function testAnIssueDateThatIsNotARealDayIsSaidUnchecked(): void
    {
        $text = (new PdfTextExtractor())->extractText($this->pdf);
        $this->assertIsString($text);
        $next = $this->scoutYear('2026-2027', '2026-09-01', '2027-08-31');

        $outcome = $this->serviceReading(str_replace('08/01/2026', '31/02/2026', $text))->import('pdf', $next, 7);

        $this->assertSame(InvoiceImportOutcome::IMPORTED, $outcome->status);
        $this->assertTrue($outcome->yearUnchecked);
    }

    /**
     * A document that can never be imported is refused before any year
     * question (#1548 review): no number means no import, whatever the
     * treasurer would have confirmed.
     */
    public function testADocumentWithoutANumberIsRefusedBeforeAnyYearQuestion(): void
    {
        $text = (new PdfTextExtractor())->extractText($this->pdf);
        $this->assertIsString($text);
        $next = $this->scoutYear('2026-2027', '2026-09-01', '2027-08-31');

        $outcome = $this->serviceReading(str_replace('F2026/000123', '', $text))->import('pdf', $next, 7);

        $this->assertSame(InvoiceImportOutcome::REFUSED, $outcome->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    private function scoutYear(string $label, string $start, string $end): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> the context of the latest journal line of this type */
    private function journalContext(string $eventType): array
    {
        $stmt = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$eventType]);
        $context = $stmt->fetchColumn();
        $this->assertIsString($context, "no {$eventType} line");

        return (array) json_decode($context, true);
    }

    private function serviceReading(string $text): InvoiceImportService
    {
        return $this->serviceWith(new class ($text) extends PdfTextExtractor {
            public function __construct(private string $text)
            {
            }

            public function extractText(string $pdfContent): ?string
            {
                return $this->text;
            }
        });
    }

    private function serviceWith(PdfTextExtractor $extractor): InvoiceImportService
    {
        return new InvoiceImportService(
            new InvoiceReader($extractor, new InvoiceParser()),
            $this->invoices,
            new InvoiceMemberMatchRepository($this->pdo, $this->encryption),
            $this->snapshots,
            new SectionService(
                new SectionRepository(Connection::withPdo($this->pdo)),
                new MemberProfileRepository(
                    Connection::withPdo($this->pdo),
                    $this->encryption,
                    new MemberBadgeRepository($this->pdo)
                )
            ),
            new JournalService(new JournalRepository($this->pdo)),
            new \Core\Config\ScoutYearService($this->pdo)
        );
    }

    /**
     * The printed total moved by one cent, and nothing else. The reading
     * has to notice: an invoice that is "nearly" right is the one that
     * costs a unit money quietly.
     */
    private function tamperedByOneCent(): string
    {
        // The total as the PDF's own content stream spells it — `\240` is
        // the octal escape for the NBSP group separator. Same byte length,
        // so the stream stays valid and only the figure moves.
        $tampered = str_replace('1\240069,00', '1\240069,01', $this->pdf, $count);
        $this->assertSame(1, $count, 'The fixture should print its total exactly once.');

        return $tampered;
    }

    /** @return array{first: string, last: string, birth: string} */
    private function namesOnFirstFeeLine(): array
    {
        $invoice = (new InvoiceReader(new PdfTextExtractor(), new InvoiceParser()))->read($this->pdf)->invoice;
        $this->assertNotNull($invoice);
        $person = $invoice->lines[0]->people[0];

        return ['first' => $person->firstName, 'last' => $person->lastName, 'birth' => $person->birthDate];
    }

    private function createMember(string $first, string $last, string $birthDate): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($first, 'member_years.first_name'),
            $this->encryption->encrypt($last, 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
        ]);

        return $memberId;
    }
}
