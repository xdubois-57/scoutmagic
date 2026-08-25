<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Repository;

use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoicePerson;
use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private InvoiceRepository $repository;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->repository = new InvoiceRepository($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025L1', 'Meute Akela')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function createMember(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");

        return (int) $this->pdo->lastInsertId();
    }

    private function person(string $last, string $first, string $birth): InvoicePerson
    {
        return new InvoicePerson($last, $first, $birth, null);
    }

    private function invoice(string $documentNumber = 'F2026/000123'): ParsedInvoice
    {
        return new ParsedInvoice(
            $documentNumber,
            '2026-01-08',
            [
                new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 2, 7800, [
                    $this->person('Dubois', 'Basile', '2012-03-15'),
                    $this->person('Pissoort', 'Léa', '2013-09-01'),
                ]),
                new InvoiceLine('COT_ACOMPTE', 'Acompte déjà versé', null, -30000, 1, -30000),
            ],
            -22200,
            'BE71096123456769',
            '+++123/4567/89012+++',
            'Report0024 v.01',
            7
        );
    }

    public function testAWholeInvoiceIsStoredWithItsLinesAndItsPeople(): void
    {
        $memberId = $this->createMember();

        $id = $this->repository->store(
            $this->invoice(),
            $this->scoutYearId,
            null,
            null,
            ['SV025L1' => $this->sectionId],
            ['dubois|basile|2012-03-15' => $memberId, 'pissoort|lea|2013-09-01' => null]
        );

        $stored = $this->repository->findById($id);
        $this->assertNotNull($stored);
        $this->assertSame('F2026/000123', $stored->documentNumber);
        $this->assertSame('2026-01-08', $stored->issueDate);
        $this->assertSame(-22200, $stored->totalCents);
        $this->assertSame(7, $stored->ignoredRowCount);
        $this->assertSame('Report0024 v.01', $stored->templateNumber);

        $lines = $this->repository->findLines($id);
        $this->assertCount(2, $lines);
        $this->assertSame('COT_NORM', $lines[0]->reference);
        $this->assertSame($this->sectionId, $lines[0]->sectionId);
        $this->assertSame(InvoiceLine::NATURE_FEE, $lines[0]->nature);
        $this->assertSame([$memberId], $lines[0]->memberIds);
        $this->assertSame(InvoiceLine::NATURE_ADJUSTMENT, $lines[1]->nature);
    }

    /**
     * The count of billed people has to stay right even when the site
     * recognises none of them — that is what the NULL member_id row is
     * for, and it is why no name is needed to say "3 personnes facturées
     * que le site n'a pas reconnues".
     */
    public function testAPersonTheSiteCouldNotMatchIsStillCountedWithoutStoringAName(): void
    {
        $id = $this->repository->store(
            $this->invoice(),
            $this->scoutYearId,
            null,
            null,
            ['SV025L1' => $this->sectionId],
            []
        );

        $lines = $this->repository->findLines($id);
        $this->assertSame([], $lines[0]->memberIds);
        $this->assertSame(2, $lines[0]->unmatchedPeopleCount);
    }

    public function testNoNameOrBirthDateEverReachesTheTables(): void
    {
        $this->repository->store($this->invoice(), $this->scoutYearId, null, null, ['SV025L1' => $this->sectionId], []);

        foreach (['fees_invoices', 'fees_invoice_lines', 'fees_invoice_people'] as $table) {
            $dump = json_encode($this->pdo->query("SELECT * FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC));
            $this->assertIsString($dump);
            $this->assertStringNotContainsStringIgnoringCase('Dubois', $dump, "{$table} holds a name");
            $this->assertStringNotContainsStringIgnoringCase('Basile', $dump, "{$table} holds a first name");
            $this->assertStringNotContainsString('2012-03-15', $dump, "{$table} holds a birth date");
        }
    }

    public function testTheSameDocumentNumberIsFoundAgainSoATreasurerCanJustRetry(): void
    {
        $id = $this->repository->store($this->invoice(), $this->scoutYearId, null, null, ['SV025L1' => $this->sectionId], []);

        $found = $this->repository->findByDocumentNumber($this->scoutYearId, 'F2026/000123');
        $this->assertNotNull($found);
        $this->assertSame($id, $found->id);
        $this->assertNull($this->repository->findByDocumentNumber($this->scoutYearId, 'F2026/999999'));
    }

    public function testAFailureInTheMiddleLeavesNothingBehind(): void
    {
        // A section id no `sections` row carries: the FK on the second
        // line fails, and the header inserted a moment earlier must go
        // with it. Half an invoice is worse than none.
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        try {
            $this->repository->store(
                $this->invoice(),
                $this->scoutYearId,
                null,
                null,
                ['SV025L1' => 987654],
                []
            );
            $this->fail('The store should have propagated the foreign-key failure.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoice_lines')->fetchColumn());
    }

    public function testTheSeasonComesBackOldestFirstWithUndatedDocumentsLast(): void
    {
        $this->repository->store(
            new ParsedInvoice('F2', '2026-02-10', [], 1000, null, null, null, 0),
            $this->scoutYearId, null, null, [], []
        );
        $this->repository->store(
            new ParsedInvoice('F1', '2025-11-04', [], -30000, null, null, null, 0),
            $this->scoutYearId, null, null, [], []
        );
        $this->repository->store(
            new ParsedInvoice('F0', null, [], 500, null, null, null, 0),
            $this->scoutYearId, null, null, [], []
        );

        $numbers = array_map(
            static fn(\Modules\Fees\Value\StoredInvoice $i): string => $i->documentNumber,
            $this->repository->findAllForYear($this->scoutYearId)
        );

        $this->assertSame(['F1', 'F2', 'F0'], $numbers);
    }

    public function testTheLastIgnoredRowCountIsTheOneAChangedTemplateWouldMove(): void
    {
        $this->assertNull($this->repository->findLastIgnoredRowCount($this->scoutYearId));

        $this->repository->store(
            new ParsedInvoice('F1', '2025-11-04', [], -30000, null, null, null, 7),
            $this->scoutYearId, null, null, [], []
        );

        $this->assertSame(7, $this->repository->findLastIgnoredRowCount($this->scoutYearId));
    }

    public function testTheKeptPdfIsRememberedAsALooseReference(): void
    {
        $id = $this->repository->store(
            new ParsedInvoice('F1', '2025-11-04', [], -30000, null, null, null, 0),
            $this->scoutYearId, null, null, [], []
        );

        $this->repository->attachFinanceFile($id, 4242);

        $this->assertSame(4242, $this->repository->findById($id)?->financeFileId);
    }
}
