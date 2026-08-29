<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\MemberLookupRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\CampaignImportException;
use Modules\Finance\Service\CampaignImportService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\ReceivableSettlement;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Finance\Service\TreasurerScope;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignService $service;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private int $accountId;
    private int $otherAccountId;
    private int $scoutYearId;
    private int $fiscalYearId;
    /** @var array<string, int> */
    private array $memberIds = [];
    /** @var string[] */
    private array $tempFiles = [];
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/campaign_service_test_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);

        $this->service = new CampaignService(
            $this->pdo,
            $this->campaigns,
            $this->rows,
            new CampaignImportService(new MemberLookupRepository($this->pdo)),
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables),
            new StructuredCommunicationService($this->receivables),
            new AccountRepository($this->pdo, $this->encryption),
            new AccountVisibility(TreasurerScope::systemCaller()),
            new EncryptedFileStorageService(new FileRepository($this->pdo), $this->encryption, $this->storagePath),
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status, role_min_view) VALUES ('Compte réservé', 'bank', 'active', 'admin')");
        $this->otherAccountId = (int) $this->pdo->lastInsertId();

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);
        $this->fiscalYearId = $this->scoutYearId;

        foreach (['D-100', 'D-200', 'D-300'] as $deskId) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([$deskId]);
            $this->memberIds[$deskId] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->storagePath)) {
            foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    // ── creating ────────────────────────────────────────────────────────

    public function testEachLineBecomesARowAndAReceivable(): void
    {
        $campaignId = $this->create('Cotisations 2025-2026', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '38,25'],
        ]);

        $rows = $this->rows->findByCampaignId($campaignId);
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $receivables = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $row->id);
            $this->assertCount(1, $receivables, 'one receivable per campaign row');
            $this->assertSame($row->amountCents, $receivables[0]->amountDueCents);
            $this->assertSame($row->memberId, $receivables[0]->memberId);
            $this->assertSame($this->accountId, $receivables[0]->accountId);
        }
    }

    /**
     * One receivable per member, and its own communication — which is
     * what makes a transfer identifiable when it lands. Two children of
     * one household must never share one.
     */
    public function testEveryReceivableGetsItsOwnCommunication(): void
    {
        $campaignId = $this->create('Cotisations', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '45,00'],
            [$this->memberIds['D-300'], '45,00'],
        ]);

        $communications = [];
        foreach ($this->rows->findByCampaignId($campaignId) as $row) {
            $communications[] = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $row->id)[0]->communication;
        }

        $this->assertCount(3, array_unique($communications));
        foreach ($communications as $communication) {
            $this->assertTrue(StructuredCommunicationService::isValid($communication), $communication);
        }
    }

    public function testTheSourceFileIsStoredEncryptedAtRest(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']]);

        $campaign = $this->campaigns->findById($campaignId);
        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->sourceFileId);

        $file = (new FileRepository($this->pdo))->findById($campaign->sourceFileId);
        $this->assertNotNull($file);
        $this->assertTrue($file->encrypted);

        // The bytes on disk are not the spreadsheet: an .xlsx is a ZIP,
        // and a ZIP starts with "PK".
        $raw = (string) file_get_contents($this->storagePath . '/' . $file->relativePath);
        $this->assertStringStartsNotWith('PK', $raw);
    }

    /**
     * The columns a treasurer kept are personal data — a name, a section,
     * an address — and have no business sitting in the clear.
     */
    public function testTheSpreadsheetColumnsAreStoredEncrypted(): void
    {
        $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00', 'Vandenbrande']], ['ID interne', 'Montant', 'Nom']);

        $raw = (string) $this->pdo->query('SELECT merge_data FROM finance_campaign_rows')->fetchColumn();
        $this->assertStringNotContainsString('Vandenbrande', $raw);

        $row = $this->rows->findByCampaignId(1)[0];
        $this->assertSame('Vandenbrande', $row->mergeData['Nom']);
    }

    /**
     * A refused file leaves nothing behind — no campaign, no row, no
     * receivable, and not even an orphan blob on disk. A campaign
     * half-imported is worse than none, because the missing half is
     * invisible.
     */
    public function testARefusedFileCreatesNothingAtAll(): void
    {
        try {
            $this->create('Cotisations', [
                [$this->memberIds['D-100'], '45,00'],
                ['4821', '45,00'],
            ]);
            $this->fail('An unknown identifier must refuse the file.');
        } catch (CampaignImportException) {
            // Expected.
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_campaigns')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_campaign_rows')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testACampaignWithoutANameIsRefused(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/nom/');

        $this->create('   ', [[$this->memberIds['D-100'], '45,00']]);
    }

    /**
     * The account partition applies to a campaign exactly as to every
     * other finance page: an intendant cannot book one against an account
     * they cannot even see.
     */
    public function testACampaignCannotBeBookedAgainstAnAccountTheViewerCannotSee(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches("/n'existe pas|accessible/");

        $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']], null, $this->otherAccountId);
    }

    // ── the payments come in ────────────────────────────────────────────

    public function testAPaymentCarryingTheCommunicationSettlesItsOwnReceivable(): void
    {
        $campaignId = $this->create('Cotisations', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '45,00'],
        ]);

        $rows = $this->rows->findByCampaignId($campaignId);
        $first = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rows[0]->id)[0];
        $second = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rows[1]->id)[0];

        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->accountId, $this->fiscalYearId, 'REF-1', '2026-02-18',
            'Virement ' . $first->communication, 45.00, null, null, 'import', null
        );

        $service = FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables);
        $this->assertSame('paid', $service->getReceivableStatus($first->id)['status']);
        $this->assertSame('unpaid', $service->getReceivableStatus($second->id)['status']);
    }

    // ── the gestures that follow ────────────────────────────────────────

    public function testClosingFreezesTheCampaignWithoutHidingIt(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']]);

        $this->service->close($campaignId, Role::INTENDANT, 7);

        $campaign = $this->campaigns->findById($campaignId);
        $this->assertNotNull($campaign);
        $this->assertFalse($campaign->isOpen());
        $this->assertNotNull($campaign->closedAt);
        $this->assertSame(1, $this->rows->countByCampaignId($campaignId), 'the receivables survive a closure');
    }

    public function testReopeningClearsTheClosureDate(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']]);
        $this->service->close($campaignId, Role::INTENDANT, 7);

        $this->service->reopen($campaignId, Role::INTENDANT, 7);

        $campaign = $this->campaigns->findById($campaignId);
        $this->assertNotNull($campaign);
        $this->assertTrue($campaign->isOpen());
        $this->assertNull($campaign->closedAt);
    }

    public function testACampaignIsNotNotifiedUntilTheTreasurerSaysSo(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']]);
        $campaign = $this->campaigns->findById($campaignId);
        $this->assertNotNull($campaign);
        $this->assertFalse($campaign->isNotified());

        $notified = $this->service->markNotified($campaignId, Role::INTENDANT, 7);

        $this->assertTrue($notified->isNotified());
        $this->assertSame(7, $notified->notifiedBy);
    }

    public function testTheNoteIsStoredEncryptedWithItsAuthorAndDate(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']]);
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;

        $this->service->setNote($rowId, 'Maman appelée le 14/02, paiera fin du mois.', Role::INTENDANT, 7);

        $raw = (string) $this->pdo->query('SELECT note FROM finance_campaign_rows')->fetchColumn();
        $this->assertStringNotContainsString('Maman', $raw);

        $row = $this->rows->findById($rowId);
        $this->assertNotNull($row);
        $this->assertSame('Maman appelée le 14/02, paiera fin du mois.', $row->note);
        $this->assertSame(7, $row->noteAuthorId);
        $this->assertNotNull($row->noteUpdatedAt);
    }

    public function testABlankNoteClearsItAndItsAuthorship(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00']]);
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;
        $this->service->setNote($rowId, 'Un mot', Role::INTENDANT, 7);

        $this->service->setNote($rowId, '   ', Role::INTENDANT, 7);

        $row = $this->rows->findById($rowId);
        $this->assertNotNull($row);
        $this->assertNull($row->note);
        $this->assertNull($row->noteAuthorId);
        $this->assertNull($row->noteUpdatedAt);
    }

    /**
     * A note is free text about a person; the journal is read by people
     * who have no business reading that. Only the row's id goes in it.
     */
    public function testNothingAboutACampaignPutsPersonalDataInTheJournal(): void
    {
        $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00', 'Vandenbrande']], ['ID interne', 'Montant', 'Nom']);

        $entries = $this->pdo->query('SELECT description, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('Vandenbrande', (string) $entry['description']);
            $this->assertStringNotContainsString('Vandenbrande', (string) $entry['context']);
        }
    }

    // ── retention ───────────────────────────────────────────────────────

    /**
     * The file goes and so does the copy of its columns; the campaign,
     * its amounts and its receivables stay. Keeping the copy would make
     * the deletion of the file decorative.
     */
    public function testForgettingTheSourceFileKeepsTheFinancialHistory(): void
    {
        $campaignId = $this->create('Cotisations', [[$this->memberIds['D-100'], '45,00', 'Vandenbrande']], ['ID interne', 'Montant', 'Nom']);
        $campaign = $this->campaigns->findById($campaignId);
        $this->assertNotNull($campaign);
        $fileId = $campaign->sourceFileId;
        $this->assertNotNull($fileId);

        $this->service->forgetSourceFile($campaign);

        $refreshed = $this->campaigns->findById($campaignId);
        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->sourceFileId);
        $this->assertSame([], $refreshed->mergeColumns);
        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));

        $row = $this->rows->findByCampaignId($campaignId)[0];
        $this->assertSame([], $row->mergeData);
        $this->assertSame(4500, $row->amountCents);
        $this->assertCount(1, $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $row->id));
    }

    // ── helper ──────────────────────────────────────────────────────────

    /**
     * @param array<int, array<int, string|int>> $lines
     * @param ?string[] $headers
     */
    private function create(string $label, array $lines, ?array $headers = null, ?int $accountId = null): int
    {
        $headers ??= ['ID interne', 'Montant'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }
        foreach ($lines as $rowIndex => $cells) {
            foreach ($cells as $cellIndex => $value) {
                $sheet->setCellValue([$cellIndex + 1, $rowIndex + 2], $value);
            }
        }

        $path = (tempnam(sys_get_temp_dir(), 'campaign_') ?: '') . '.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $this->service->createFromFile(
            $label,
            $this->scoutYearId,
            $accountId ?? $this->accountId,
            $path,
            'cotisations.xlsx',
            Role::INTENDANT,
            7
        );
    }
}
