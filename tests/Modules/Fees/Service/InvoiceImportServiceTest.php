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
use Modules\Fees\Invoice\InvoiceReader;
use Modules\Fees\Repository\InvoiceMemberMatchRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Repository\RosterSnapshotRepository;
use Modules\Fees\Service\InvoiceImportService;
use Modules\Fees\Value\InvoiceImportOutcome;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

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
    private const DOCUMENT_SECTIONS = ['SV025B1', 'SV025L1', 'STAFFDU', 'SV025E1'];

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
            new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
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
