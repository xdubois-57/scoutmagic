<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Badge\MemberBadgeRepository;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Controller\CampaignController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\MemberLookupRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\CampaignExportService;
use Modules\Finance\Service\CampaignImportService;
use Modules\Finance\Service\CampaignOverviewService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Finance\Service\TreasurerScope;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\TestTwig;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignController $controller;
    private CampaignService $campaignService;
    private CampaignRepository $campaigns;
    /**
     * The collaborators the controller is assembled from, kept so a test
     * can rebuild it with the mail-merge module present — the reminder is
     * the one path whose behaviour depends on that.
     *
     * @var array<string, mixed>
     */
    private array $controllerParts = [];
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new \Modules\Finance\Repository\CategoryRuleRepository($this->pdo);
        $checkpointRepository = new \Modules\Finance\Repository\BalanceCheckpointRepository($this->pdo);
        $scoutYearService = new ScoutYearService($this->pdo);
        $accountVisibility = new AccountVisibility(TreasurerScope::systemCaller());

        $financeService = new FinanceService(
            $accountRepository,
            $categoryRepository,
            new FiscalYearRepository($this->pdo, $scoutYearService),
            new SectionService(
    new SectionRepository(Connection::withPdo($this->pdo)),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo))
),
            $transactionRepository,
            new BalanceService($checkpointRepository, $transactionRepository),
            new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
            $categoryRuleRepository,
            new AccountTransferCategoryService($categoryRepository, $categoryRuleRepository, $transactionRepository),
            $accountVisibility
        );

        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $allocations = FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables);

        $this->campaignService = new CampaignService(
            $this->pdo,
            $this->campaigns,
            $this->rows,
            new CampaignImportService(new MemberLookupRepository($this->pdo)),
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables),
            new StructuredCommunicationService($this->receivables),
            $accountRepository,
            $accountVisibility,
            new EncryptedFileStorageService(new FileRepository($this->pdo), $this->encryption, sys_get_temp_dir()),
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->controllerParts = [
            'overview' => new CampaignOverviewService(
                $this->campaigns,
                $this->rows,
                $this->receivables,
                $allocations,
                $accountRepository,
                $accountVisibility,
                new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
                new UserAccountRepository($this->pdo, $this->encryption)
            ),
            'rows' => $this->rows,
            'receivables' => $this->receivables,
            'allocations' => $allocations,
            'accounts' => $accountRepository,
            'members' => new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
            'finance' => $financeService,
            'scoutYears' => $scoutYearService,
        ];

        $this->controller = new CampaignController(
            $this->twig(),
            $this->campaignService,
            new CampaignOverviewService(
                $this->campaigns,
                $this->rows,
                $this->receivables,
                $allocations,
                $accountRepository,
                $accountVisibility,
                new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
                new UserAccountRepository($this->pdo, $this->encryption)
            ),
            new CampaignExportService(),
            new \Modules\Finance\Service\CampaignReminderService(
                $this->rows,
                $this->receivables,
                $allocations,
                $accountRepository,
                new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
                new \Modules\Finance\Service\ReceivableQrTokenService($this->encryption),
                'https://scoutmagic.test',
                // mass_mail disabled: the button is simply not offered.
                null
            ),
            new \Modules\Finance\Service\CampaignNotificationService(
                $this->rows,
                $this->receivables,
                $allocations,
                new \Core\Member\MemberAccountResolver(
                    new MemberYearRepository($this->pdo),
                    new \Core\Member\MemberEmailRepository($this->pdo, $this->encryption),
                    new UserAccountRepository($this->pdo, $this->encryption),
                    $this->encryption
                ),
                new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
                new MemberYearRepository($this->pdo),
                // The notification centre is exercised on its own, in
                // Service\CampaignNotificationServiceTest.
                null
            ),
            $financeService,
            $allocations,
            new \Modules\Finance\Service\PaymentLabelService(
                $this->rows,
                $this->receivables,
                $allocations,
                $accountRepository,
                new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
                new \Modules\Finance\Service\SepaQrCodeService(),
                new \Core\Pdf\DocumentPdfService(),
                $this->twig()
            ),
            $scoutYearService
        );

        $this->accountId = $accountRepository->create('Compte Unité', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$this->accountId]);

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        foreach (['D-100', 'D-200'] as $deskId) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([$deskId]);
            $this->memberIds[$deskId] = (int) $this->pdo->lastInsertId();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ── the screens ─────────────────────────────────────────────────────

    public function testTheListRendersEvenWithNoCampaign(): void
    {
        $response = $this->controller->index(new Request('GET', '/finance/campaigns', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucune campagne', $response->getBody());
    }

    public function testTheFormExplainsWhereTheFileHasToComeFrom(): void
    {
        $response = $this->controller->form(new Request('GET', '/finance/campaigns/new', [], [], [], []), []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('ID interne', $body);
        $this->assertStringContainsString('Ne reconstruisez pas la liste à la main', $body);
    }

    public function testUploadingAValidFileCreatesTheCampaignAndGoesToIt(): void
    {
        $response = $this->upload('Cotisations 2025-2026', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '38,25'],
        ]);

        $this->assertSame(302, $response->getStatusCode(), $response->getBody());
        $this->assertSame('/finance/campaigns/1', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(2, $this->rows->countByCampaignId(1));
    }

    /**
     * A refusal that sends somebody to a help page without explaining
     * anything on the spot is a refusal done badly: the offending lines
     * are named on the page, with what the file says on them.
     */
    public function testARefusedFileNamesEveryOffendingLineOnThePage(): void
    {
        $response = $this->upload('Cotisations', [
            [$this->memberIds['D-100'], '45,00'],
            ['4821', '45,00'],
        ]);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('refusé', $body);
        $this->assertStringContainsString('4821', $body);
        $this->assertSame(0, $this->countFrom('SELECT COUNT(*) FROM finance_campaigns'));
    }

    public function testTheDetailPageSaysHowManyLinesTheExportWillTake(): void
    {
        $campaignId = $this->createCampaign();

        $response = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        );

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("L'export reprend les 2 créances affichées", $body);
        $this->assertStringContainsString('Exporter (2)', $body);
    }

    public function testAnUnknownCampaignIsANotFoundRatherThanAnError(): void
    {
        $response = $this->controller->show(new Request('GET', '/finance/campaigns/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── the export follows the filter ───────────────────────────────────

    /**
     * Exporting 262 lines while looking at the 41 unpaid ones is a
     * surprise; exporting 41 while believing you have 262 is worse.
     */
    public function testTheExportCarriesExactlyTheLinesTheFilterShows(): void
    {
        $campaignId = $this->createCampaign();
        $rows = $this->rows->findByCampaignId($campaignId);
        $paid = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rows[0]->id)[0];

        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Virement ' . $paid->communication, 45.00, null, null, 'import', null
        );

        $all = $this->exportRowCount($campaignId, 'all');
        $paidOnly = $this->exportRowCount($campaignId, 'paid');
        $todo = $this->exportRowCount($campaignId, 'todo');

        $this->assertSame(2, $all);
        $this->assertSame(1, $paidOnly);
        $this->assertSame(1, $todo);
    }

    // ── the gestures ────────────────────────────────────────────────────

    public function testSavingANoteWritesItAndComesBackToTheSameFilter(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;

        $response = $this->controller->saveNote(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'note' => 'Rappel fait', 'filter' => 'all'], [], []),
            ['id' => (string) $campaignId, 'rowId' => (string) $rowId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/finance/campaigns/' . $campaignId . '?filter=all', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('Rappel fait', $this->rows->findById($rowId)?->note);
    }

    public function testANoteIsNotWrittenWithoutAValidCsrfToken(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;

        $this->controller->saveNote(
            new Request('POST', '/x', [], ['_csrf_token' => 'wrong', 'note' => 'Rappel fait'], [], []),
            ['id' => (string) $campaignId, 'rowId' => (string) $rowId]
        );

        $this->assertNull($this->rows->findById($rowId)?->note);
    }

    /**
     * Abandoning settles the receivable and nothing enters the account —
     * which is exactly why it is not recorded as a payment.
     */
    public function testWaivingSettlesTheReceivableWithoutAnyMoneyComingIn(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;
        $receivableId = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rowId)[0]->id;

        $response = $this->controller->waive(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'waived' => '1', 'filter' => 'all'], [], []),
            ['id' => (string) $campaignId, 'receivableId' => (string) $receivableId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $receivable = $this->receivables->findById($receivableId);
        $this->assertNotNull($receivable);
        $this->assertTrue($receivable->isWaived());

        $status = FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables)
            ->getReceivableStatus($receivableId);
        $this->assertSame('waived', $status['status']);
        $this->assertSame(0, $status['amount_received']);
    }

    public function testClosingACampaignKeepsItReadable(): void
    {
        $campaignId = $this->createCampaign();

        $this->controller->updateStatus(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'status' => 'closed'], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertFalse($this->campaigns->findById($campaignId)?->isOpen());
        $response = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Clôturée', $response->getBody());
    }

    /**
     * The other caller of `Modules\MassMail\Api\MassMailDraftInterface`
     * — news's « Écrire aux répondants » is the first — and the reason
     * both had to be checked: the interface promised « the draft's edit
     * URL » and returned a JSON entry point, so repairing one caller
     * would have left the other pointing at a raw payload.
     *
     * What this pins is that finance hands the URL straight back to the
     * browser without rewriting it. That the URL is a page is asserted
     * once, on the single implementation both callers share
     * (Tests\Modules\MassMail\Service\MergeDraftServiceTest).
     */
    public function testTheCampaignReminderRedirectsToTheComposerUrlItWasGiven(): void
    {
        $campaignId = $this->createCampaign();

        $response = $this->controllerWithReminderUrl('/mass-mail/42')->reminder(
            new Request('POST', '/finance/campaigns/' . $campaignId . '/reminder', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/mass-mail/42', $response->getHeaders()['Location'] ?? null);
        $this->assertStringContainsString("il n'a pas été envoyé", (string) (FlashMessage::get()['message'] ?? ''));
    }

    /**
     * The same controller, with a reminder service that answers one URL.
     *
     * The service itself is exercised against real campaign rows in
     * Service\CampaignReminderServiceTest; what is under test here is the
     * one line after it — that whatever the mail-merge module names as
     * the draft's screen is where the treasurer is actually sent.
     */
    private function controllerWithReminderUrl(string $composerUrl): CampaignController
    {
        // A stub, not a mock: nothing here asserts how the service is
        // called, only what the controller does with its answer.
        $reminders = $this->createStub(\Modules\Finance\Service\CampaignReminderService::class);
        $reminders->method('isAvailable')->willReturn(true);
        $reminders->method('createDraft')->willReturn($composerUrl);

        return $this->controllerWithReminders($reminders);
    }

    /**
     * The same controller, with a reminder service that fails the way
     * another module fails: with an exception of its own that is not a
     * FinanceException.
     */
    private function controllerWithFailingReminder(\Throwable $failure): CampaignController
    {
        // A stub, not a mock: nothing here asserts how the service is
        // called, only what the controller does with its answer.
        $reminders = $this->createStub(\Modules\Finance\Service\CampaignReminderService::class);
        $reminders->method('isAvailable')->willReturn(true);
        $reminders->method('createDraft')->willThrowException($failure);

        return $this->controllerWithReminders($reminders);
    }

    private function controllerWithReminders(
        \Modules\Finance\Service\CampaignReminderService $reminders
    ): CampaignController {
        return new CampaignController(
            $this->twig(),
            $this->campaignService,
            $this->controllerParts['overview'],
            new CampaignExportService(),
            $reminders,
            new \Modules\Finance\Service\CampaignNotificationService(
                $this->controllerParts['rows'],
                $this->controllerParts['receivables'],
                $this->controllerParts['allocations'],
                new \Core\Member\MemberAccountResolver(
                    new MemberYearRepository($this->pdo),
                    new \Core\Member\MemberEmailRepository($this->pdo, $this->encryption),
                    new UserAccountRepository($this->pdo, $this->encryption),
                    $this->encryption
                ),
                $this->controllerParts['members'],
                new MemberYearRepository($this->pdo),
                null
            ),
            $this->controllerParts['finance'],
            $this->controllerParts['allocations'],
            new \Modules\Finance\Service\PaymentLabelService(
                $this->controllerParts['rows'],
                $this->controllerParts['receivables'],
                $this->controllerParts['allocations'],
                $this->controllerParts['accounts'],
                $this->controllerParts['members'],
                new \Modules\Finance\Service\SepaQrCodeService(),
                new \Core\Pdf\DocumentPdfService(),
                $this->twig()
            ),
            $this->controllerParts['scoutYears']
        );
    }

    /**
     * With the mail-merge module off, the button is not offered at all —
     * the campaign works exactly as before (ARCHITECTURE.md §7.5).
     */
    public function testWithoutTheMailMergeModuleTheReminderButtonIsNotOffered(): void
    {
        $campaignId = $this->createCampaign();

        $body = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        )->getBody();

        $this->assertStringNotContainsString('Brouillon de rappel', $body);
    }

    public function testTheDetailPageOffersToMarkTheFamiliesNotified(): void
    {
        $campaignId = $this->createCampaign();

        $body = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        )->getBody();

        $this->assertStringContainsString('Familles non notifiées', $body);
        $this->assertStringContainsString('Notifier les familles', $body);
    }

    /**
     * The mark is a separate gesture because the reminder leaves by hand
     * from the mail-merge screen: the site cannot know when the request
     * actually went out.
     */
    public function testMarkingTheFamiliesNotifiedRecordsItAndDropsTheBadge(): void
    {
        $campaignId = $this->createCampaign();

        $response = $this->controller->notify(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken()], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->campaigns->findById($campaignId)?->isNotified());

        $body = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        )->getBody();
        $this->assertStringNotContainsString('Familles non notifiées', $body);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    // ── the sheet of labels to cut out ──────────────────────────────────

    /**
     * The download itself: a real PDF, named after the campaign, and
     * carrying one QR per receivable that still asks for something.
     */
    public function testTheLabelSheetDownloadsAsAPdf(): void
    {
        $campaignId = $this->createCampaign();

        $response = $this->controller->labels(
            new Request('GET', '/finance/campaigns/' . $campaignId . '/labels', [], [], [], []),
            ['id' => (string) $campaignId]
        );

        $headers = $response->getHeaders();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $headers['Content-Type'] ?? null);
        $this->assertSame(
            'attachment; filename="etiquettes-campagne-' . $campaignId . '.pdf"',
            $headers['Content-Disposition'] ?? null
        );
        $this->assertStringStartsWith('%PDF-', $response->getBody());
        $this->assertSame(2, preg_match_all('#/Subtype\s*/Image#', $response->getBody()));
    }

    /**
     * **The sheet ignores the filter on screen**, unlike the export. A
     * label only exists for a receivable that still owes something, so
     * printing the « Payées » filter would hand out an empty sheet and
     * printing « Toutes » would hand a label to a family that has paid.
     */
    public function testTheLabelSheetIgnoresTheFilterTheScreenIsOn(): void
    {
        $campaignId = $this->createCampaign();

        $todo = $this->controller->labels(
            new Request('GET', '/finance/campaigns/' . $campaignId . '/labels', ['filter' => 'todo'], [], [], []),
            ['id' => (string) $campaignId]
        );
        $paid = $this->controller->labels(
            new Request('GET', '/finance/campaigns/' . $campaignId . '/labels', ['filter' => 'paid'], [], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertSame(200, $paid->getStatusCode());
        $this->assertSame(
            preg_match_all('#/Subtype\s*/Image#', $todo->getBody()),
            preg_match_all('#/Subtype\s*/Image#', $paid->getBody()),
            'the same two labels whichever filter the screen was on'
        );
    }

    /**
     * A campaign with nothing left to claim is a refusal on the campaign
     * page, where the treasurer can read why — never a downloaded PDF
     * that turns out to be blank.
     */
    public function testACampaignWithNothingLeftToClaimSaysSoOnThePage(): void
    {
        $campaignId = $this->createCampaign();
        foreach ($this->rows->findByCampaignId($campaignId) as $index => $row) {
            $receivable = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $row->id)[0];
            (new TransactionRepository($this->pdo, $this->encryption))->create(
                $this->accountId, $this->scoutYearId, 'PAID-' . $index, '2026-02-18',
                'Virement ' . $receivable->communication, $receivable->amountDueCents / 100, null, null, 'import', null
            );
        }

        $response = $this->controller->labels(
            new Request('GET', '/finance/campaigns/' . $campaignId . '/labels', [], [], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/finance/campaigns/' . $campaignId, $response->getHeaders()['Location'] ?? null);
    }

    public function testAnUnknownCampaignHasNoLabelSheet(): void
    {
        $response = $this->controller->labels(
            new Request('GET', '/finance/campaigns/999/labels', [], [], [], []),
            ['id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── what a treasurer reads when the gesture is refused ──────────────
    //
    // Eight of this controller's catch bodies had never been executed by
    // the suite (issue #449, second batch). Each of these tests reaches one
    // of them through ordinary input — an unknown id, an empty name, a
    // module that is not installed — rather than through a double told to
    // throw, and asserts the STATE as well as the sentence: a refusal that
    // reports an error and writes anyway is the failure worth catching.

    /**
     * A campaign with no name is refused before the file is even read, so
     * nothing is created and nothing is stored — the form comes back with
     * the reason on it rather than a redirect to a campaign that would be
     * unreadable on the list.
     */
    public function testACampaignWithoutANameIsRefusedAndNothingIsCreated(): void
    {
        $response = $this->upload('', [[$this->memberIds['D-100'], '45,00']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Donnez un nom à la campagne.', $response->getBody());
        $this->assertSame(0, $this->countFrom('SELECT COUNT(*) FROM finance_campaigns'));
        $this->assertSame(
            0,
            $this->countFrom('SELECT COUNT(*) FROM files'),
            'the spreadsheet was stored for a campaign that was never created'
        );
    }

    /**
     * The same answer an unknown page gets, and for the same reason: a
     * spreadsheet of nothing, or a 500, would both tell somebody probing
     * campaign ids that the id exists.
     */
    public function testAnUnknownCampaignHasNoExport(): void
    {
        $response = $this->controller->export(
            new Request('GET', '/finance/campaigns/999/export', [], [], [], []),
            ['id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A stale link, or a campaign somebody else deleted meanwhile: the
     * treasurer gets the reason on the campaign page rather than a 500.
     */
    public function testClosingAnUnknownCampaignSaysSoInsteadOfFailing(): void
    {
        // a real campaign exists, and the request names another one
        $this->createCampaign();
        FlashMessage::get();

        $response = $this->controller->updateStatus(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'status' => 'closed'], [], []),
            ['id' => '999']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            "Cette campagne n'existe pas.",
            FlashMessage::get()['message'] ?? null
        );
    }

    public function testANoteOnAnUnknownReceivableIsRefusedWithItsOwnReason(): void
    {
        $campaignId = $this->createCampaign();
        FlashMessage::get();

        $response = $this->controller->saveNote(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'note' => 'Payé en liquide'], [], []),
            ['id' => (string) $campaignId, 'rowId' => '999']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Cette créance n'existe pas.", FlashMessage::get()['message'] ?? null);
    }

    /**
     * The campaign is resolved before the receivable, so an unknown
     * campaign refuses a receivable that does exist — nothing is waived on
     * the way to finding out.
     *
     * What this does **not** show, because the route does not do it: that
     * the receivable belongs to this campaign. Nothing compares the two
     * (issue #582). `requireCampaign()` resolves and throws first here, so
     * the allocation service is never reached at all — the check that does
     * hold, on the receivable's own account, is asserted by
     * testAReceivableOnAnAccountOutOfReachIsRefusedThroughACampaignInReach,
     * which gets past this one by naming a campaign that IS in reach.
     */
    public function testWaivingThroughAnUnknownCampaignLeavesTheReceivableStanding(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;
        $receivableId = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rowId)[0]->id;
        FlashMessage::get();

        $response = $this->controller->waive(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'waived' => '1'], [], []),
            ['id' => '999', 'receivableId' => (string) $receivableId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Cette campagne n'existe pas.", FlashMessage::get()['message'] ?? null);
        $this->assertFalse(
            $this->receivables->findById($receivableId)?->isWaived(),
            'the receivable was waived through a campaign that does not exist'
        );
    }

    /**
     * No double here, and that is the point: `$this->controller` carries
     * the real CampaignReminderService, built with `null` for the
     * mail-merge module — the configuration of a site that does not run
     * it. The button is not offered on such a site (asserted above), but
     * the route still answers, and this is what it says.
     */
    public function testWithoutTheMailMergeModuleTheReminderRouteSaysWhyItCannot(): void
    {
        $campaignId = $this->createCampaign();
        FlashMessage::get();

        $response = $this->controller->reminder(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken()], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/finance/campaigns/' . $campaignId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            "Le module de publipostage n'est pas activé.",
            FlashMessage::get()['message'] ?? null
        );
    }

    /**
     * The one branch of this controller whose subject IS another module's
     * failure, so a double throwing one is the subject rather than a
     * stand-in for it — the precedent, and the reasoning, are
     * controllerWithReminderUrl() above.
     *
     * Half of what it pins is a message; the other half is a rule:
     * AGENTS.md § Exception messages that reach a visitor. The mail-merge
     * module's internals — the SQL state, the table name — must not reach
     * the screen, and it is Core\Exception\UserFacingMessage that decides
     * so, on the grounds that the exception is not a UserFacingException.
     */
    public function testAFailureInsideTheMailMergeModuleNeverReachesTheScreen(): void
    {
        $campaignId = $this->createCampaign();
        FlashMessage::get();

        $response = $this->controllerWithFailingReminder(
            new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: mass_mail_emails')
        )->reminder(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken()], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/finance/campaigns/' . $campaignId, $response->getHeaders()['Location'] ?? null);
        $message = (string) (FlashMessage::get()['message'] ?? '');
        $this->assertStringContainsString("Le brouillon de rappel n'a pas pu être créé", $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('mass_mail_emails', $message);
    }

    public function testNotifyingAnUnknownCampaignSaysSoInsteadOfFailing(): void
    {
        // a real campaign exists, and the request names another one
        $this->createCampaign();
        FlashMessage::get();

        $response = $this->controller->notify(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken()], [], []),
            ['id' => '999']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Cette campagne n'existe pas.", FlashMessage::get()['message'] ?? null);
    }

    /**
     * The refusal that could actually write, and therefore the one whose
     * state is worth asserting: the campaign exists, and the account it is
     * booked against has since been restricted above this treasurer. The
     * four gestures that change something must all leave it exactly as it
     * was — this is the per-account decision the controller's own docblock
     * states, not `role_min: intendant` on the route.
     *
     * All four end at `Service\CampaignService::requireCampaign()`, though
     * not all by the same road: `saveNote()` resolves the ROW first and
     * reaches the predicate through `$row->campaignId`, which is why its
     * refusal is asserted here one gesture at a time rather than once at the
     * end — `FlashMessage` is a single overwritten slot, so a lone assertion
     * after all four would pin only the last one's reason and let the other
     * three be refused for something else entirely.
     */
    public function testNothingCanBeChangedOnACampaignWhoseAccountIsOutOfReach(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;
        $receivableId = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rowId)[0]->id;
        // An admin raises the account's floor after the campaign exists.
        $this->pdo->prepare("UPDATE finance_accounts SET role_min_view = 'admin' WHERE id = ?")
            ->execute([$this->accountId]);

        $params = ['id' => (string) $campaignId];
        $refusals = [];
        $this->controller->updateStatus(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'status' => 'closed'], [], []),
            $params
        );
        $refusals['closing'] = FlashMessage::get()['message'] ?? null;
        $this->controller->saveNote(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'note' => 'Payé en liquide'], [], []),
            $params + ['rowId' => (string) $rowId]
        );
        $refusals['note'] = FlashMessage::get()['message'] ?? null;
        $this->controller->notify(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken()], [], []),
            $params
        );
        $refusals['notification'] = FlashMessage::get()['message'] ?? null;
        $this->controller->waive(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'waived' => '1'], [], []),
            $params + ['receivableId' => (string) $receivableId]
        );
        $refusals['waiver'] = FlashMessage::get()['message'] ?? null;

        foreach ($refusals as $gesture => $message) {
            $this->assertSame(
                "Cette campagne n'existe pas.",
                $message,
                'the ' . $gesture . ' was refused, but not by the per-account check'
            );
        }

        $campaign = $this->campaigns->findById($campaignId);
        $this->assertTrue($campaign?->isOpen(), 'the campaign was closed from outside its account');
        $this->assertFalse($campaign?->isNotified(), 'the families were marked notified from outside its account');
        // Not `findById($rowId)?->note`: a vanished row would satisfy that,
        // and "the row is gone" is not "the note was not written".
        $row = $this->rows->findById($rowId);
        $this->assertNotNull($row, 'the campaign line itself disappeared');
        $this->assertNull($row->note, 'the note was written from outside its account');
        $this->assertFalse(
            $this->receivables->findById($receivableId)?->isWaived(),
            'the receivable was waived from outside its account'
        );
    }

    /**
     * A count, prepared like every other statement in this repository
     * (`AGENTS.md` § SQL), and read without letting a failed fetch pass
     * for a zero: `(int) false` is `0`, which would make every assertion
     * below it unfalsifiable — the defect this whole chantier is about.
     */
    private function countFrom(string $sql): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();
        $count = $statement->fetchColumn();
        self::assertIsNumeric($count, 'the count query returned nothing: ' . $sql);

        return (int) $count;
    }

    /**
     * **The receivable's OWN account check, isolated — and isolating it took a
     * second construction once issue #582 was fixed.**
     *
     * The campaign named in the URL is in reach, and the receivable named
     * beside it **is one of that campaign's own**, moved onto a third account
     * the caller may not see. So the campaign guard passes, the
     * campaign-membership guard added for #582 passes too — its source still
     * points at a row of this campaign — and the only thing left to refuse is
     * `ReceivableAllocationService::requireReceivable()`. Remove that check
     * and this test goes red on its own.
     *
     * **It used to be built the other way round**, with a receivable of a
     * FOREIGN campaign on an out-of-reach account, back when nothing compared
     * the campaign to the receivable and the account check was the only guard
     * standing. That construction stopped isolating anything the moment #582
     * was fixed: the membership guard would refuse it first, with the same
     * sentence, so the test would have stayed green with the account check
     * deleted — two mechanisms for one boundary, each hiding the other's
     * mutation, which is the failure this repository keeps naming. The cross
     * case it used to cover has a test of its own below.
     *
     * A receivable sitting on an account other than its campaign's does not
     * arise from the screens — `createFromFile()` books both against the same
     * one. It is written directly here because that is the only way to hold
     * one guard still while testing the other.
     */
    public function testAReceivableOnAnAccountOutOfReachIsRefusedThroughACampaignInReach(): void
    {
        $inReach = $this->createCampaign();
        $ownRowId = $this->rows->findByCampaignId($inReach)[0]->id;
        $ownReceivableId = $this->receivables
            ->findBySource(CampaignService::SOURCE_MODULE, $ownRowId)[0]->id;
        [, $unreachableAccountId] = $this->createCampaignOnItsOwnAccount();
        $this->pdo->prepare("UPDATE finance_accounts SET role_min_view = 'admin' WHERE id = ?")
            ->execute([$unreachableAccountId]);
        $this->pdo->prepare('UPDATE finance_expected_receivables SET account_id = ? WHERE id = ?')
            ->execute([$unreachableAccountId, $ownReceivableId]);
        FlashMessage::get();

        $response = $this->controller->waive(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'waived' => '1'], [], []),
            ['id' => (string) $inReach, 'receivableId' => (string) $ownReceivableId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            "Cette créance n'existe pas.",
            FlashMessage::get()['message'] ?? null,
            'the refusal did not come from the receivable-side account check'
        );
        $this->assertFalse(
            $this->receivables->findById($ownReceivableId)?->isWaived(),
            "a treasurer waived a receivable on an account they may not see"
        );
    }

    /**
     * **Issue #582: the receivable named in the URL must belong to the
     * campaign named beside it.** Both campaigns here sit on the SAME
     * account, in reach, so every account check on the route passes and the
     * only thing that can refuse is
     * `CampaignService::requireReceivableSourceOfCampaign()`. Remove it and
     * this test goes red on its own — the neighbouring test, which moves a
     * receivable out of reach instead, stays green.
     *
     * The mix-up is not hypothetical: the two ids travel in one URL, the
     * receivable's comes from a table shared by every module, and nothing
     * upstream pairs them. What made it a bug about a route's honesty rather
     * than about privilege is that a campaign's page lists only its own
     * receivables, so reaching another's takes a hand-built request — the
     * caller may already see it, and the refusal is about the URL claiming a
     * pairing that does not exist.
     *
     * Three calls, because a refusal alone would not say what refused:
     *  1. the receivable is waived through ITS OWN campaign, which must
     *     succeed — otherwise the two assertions below pass on a route that
     *     refuses everyone;
     *  2. cancelling that waiver through the OTHER campaign is refused and
     *     leaves it waived — `cancelWaiver` is this same method with
     *     `waived=0`, and the guard sits before that branch, so a mutant that
     *     moved it inside the `waived=1` arm would survive without this call;
     *  3. waiving the other direction is refused too, with nothing waived.
     */
    public function testAReceivableIsRefusedThroughACampaignThatIsNotItsOwn(): void
    {
        $ownerCampaignId = $this->createCampaign();
        // A second campaign on the same account as the first: `upload()`
        // defaults to it, which is what holds the account checks still.
        $otherCampaignId = $this->campaignIdCreatedBy(
            $this->upload('Camp 2025', [[$this->memberIds['D-200'], '60,00']])
        );
        $ownerRowId = $this->rows->findByCampaignId($ownerCampaignId)[0]->id;
        $ownerReceivableId = $this->receivables
            ->findBySource(CampaignService::SOURCE_MODULE, $ownerRowId)[0]->id;
        $otherRowId = $this->rows->findByCampaignId($otherCampaignId)[0]->id;
        $otherReceivableId = $this->receivables
            ->findBySource(CampaignService::SOURCE_MODULE, $otherRowId)[0]->id;
        $this->assertNotSame(
            $ownerReceivableId,
            $otherReceivableId,
            'the two campaigns share a receivable, so this test crosses nothing'
        );

        $this->waiveThrough($ownerCampaignId, $ownerReceivableId, '1');
        $this->assertSame(
            'La créance a été abandonnée.',
            FlashMessage::get()['message'] ?? null,
            'waiving through the receivable\'s own campaign did not succeed, '
            . 'so the refusals below prove nothing about the pairing'
        );
        $this->assertTrue(
            $this->receivables->findById($ownerReceivableId)?->isWaived(),
            'the control call did not waive anything'
        );

        $this->waiveThrough($otherCampaignId, $ownerReceivableId, '0');
        $this->assertSame(
            "Cette créance n'existe pas.",
            FlashMessage::get()['message'] ?? null,
            'cancelling a waiver through a foreign campaign was not refused'
        );
        $this->assertTrue(
            $this->receivables->findById($ownerReceivableId)?->isWaived(),
            "a waiver was cancelled through a campaign the receivable does not belong to"
        );

        $this->waiveThrough($ownerCampaignId, $otherReceivableId, '1');
        $this->assertSame(
            "Cette créance n'existe pas.",
            FlashMessage::get()['message'] ?? null,
            'waiving through a foreign campaign was not refused'
        );
        $this->assertFalse(
            $this->receivables->findById($otherReceivableId)?->isWaived(),
            "a receivable was waived through a campaign it does not belong to"
        );
    }

    /**
     * One call on the abandon route, with the flash queue left for the caller
     * to read: `FlashMessage::get()` empties it, so a test that makes several
     * calls has to read between them, and reading is the assertion.
     */
    private function waiveThrough(int $campaignId, int $receivableId, string $waived): void
    {
        $response = $this->controller->waive(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'waived' => $waived], [], []),
            ['id' => (string) $campaignId, 'receivableId' => (string) $receivableId]
        );
        self::assertSame(302, $response->getStatusCode(), $response->getBody());
    }

    /**
     * A second campaign, booked against an account of its own, so a test can
     * cross the two. The account is created within reach — `createFromFile()`
     * checks visibility too — and a caller may restrict it afterwards.
     *
     * @return array{0: int, 1: int} the campaign's id and its account's
     */
    private function createCampaignOnItsOwnAccount(): array
    {
        $accounts = $this->controllerParts['accounts'];
        \assert($accounts instanceof AccountRepository);
        $accountId = $accounts->create(
            'Compte Section',
            Account::TYPE_BANK,
            null,
            'BE00000000000002',
            'Titulaire',
            'intendant'
        );
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$accountId]);

        return [
            $this->campaignIdCreatedBy(
                $this->upload('Camp 2025', [[$this->memberIds['D-200'], '60,00']], $accountId)
            ),
            $accountId,
        ];
    }

    private function createCampaign(): int
    {
        $response = $this->upload('Cotisations 2025-2026', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '38,25'],
        ]);

        // Read from the redirect rather than assumed to be 1: this file can
        // now hold two campaigns, and a hardcoded id would quietly hand back
        // the wrong one depending on which helper ran first.
        return $this->campaignIdCreatedBy($response);
    }

    private function campaignIdCreatedBy(\Core\Http\Response $response): int
    {
        self::assertSame(302, $response->getStatusCode(), $response->getBody());
        $location = (string) ($response->getHeaders()['Location'] ?? '');
        $campaignId = (int) substr($location, (int) strrpos($location, '/') + 1);
        self::assertGreaterThan(0, $campaignId, 'no campaign id in the redirect: ' . $location);

        return $campaignId;
    }

    /**
     * @param array<int, array<int, string|int>> $lines
     */
    private function upload(string $label, array $lines, ?int $accountId = null): \Core\Http\Response
    {
        $path = $this->spreadsheet($lines);

        // Core\Http\Request::getFile() reads the superglobal, exactly as
        // PHP fills it — so the test has to as well.
        $_FILES['spreadsheet'] = [
            'name' => 'cotisations.xlsx',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path) ?: 0,
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        try {
            return $this->controller->create(
                new Request(
                    'POST',
                    '/finance/campaigns',
                    [],
                    [
                        '_csrf_token' => $this->csrfToken(),
                        'label' => $label,
                        'scout_year_id' => (string) $this->scoutYearId,
                        'account_id' => (string) ($accountId ?? $this->accountId),
                    ],
                    [],
                    []
                ),
                []
            );
        } finally {
            unset($_FILES['spreadsheet']);
        }
    }

    private function exportRowCount(int $campaignId, string $filter): int
    {
        $response = $this->controller->export(
            new Request('GET', '/finance/campaigns/' . $campaignId . '/export', ['filter' => $filter], [], [], []),
            ['id' => (string) $campaignId]
        );
        self::assertSame(200, $response->getStatusCode());

        $path = (tempnam(sys_get_temp_dir(), 'campaign_export_') ?: '') . '.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, $response->getBody());

        $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
        $highestRow = $spreadsheet->getSheet(0)->getHighestDataRow();
        $spreadsheet->disconnectWorksheets();

        return $highestRow - 1; // minus the header row
    }

    /**
     * @param array<int, array<int, string|int>> $lines
     */
    private function spreadsheet(array $lines): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue([1, 1], 'ID interne');
        $sheet->setCellValue([2, 1], 'Montant');
        foreach ($lines as $rowIndex => $cells) {
            foreach ($cells as $cellIndex => $value) {
                $sheet->setCellValue([$cellIndex + 1, $rowIndex + 2], $value);
            }
        }

        $path = (tempnam(sys_get_temp_dir(), 'campaign_') ?: '') . '.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    private function twig(): Environment
    {
        $twig = TestTwig::create(['finance']);

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/campaigns');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}
