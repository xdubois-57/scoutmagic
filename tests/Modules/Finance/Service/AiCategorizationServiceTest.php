<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AiCategorizationService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class AiCategorizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private CategoryRepository $categoryRepository;
    private AiCategorySuggestionRepository $suggestionRepository;
    private TransactionRepository $transactionRepository;
    private AccountRepository $accountRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private AttachmentRepository $attachmentRepository;
    private CalendarRepository $calendarRepository;
    private CalendarEventRepository $calendarEventRepository;
    private EncryptionService $encryption;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        CalendarTestHelper::createTables($this->pdo);

        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->suggestionRepository = new AiCategorySuggestionRepository($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $this->accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $this->encryption);
        $this->calendarRepository = new CalendarRepository($this->pdo);
        $this->calendarEventRepository = new CalendarEventRepository($this->pdo);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function service(?LlmConnectorInterface $llmConnector): AiCategorizationService
    {
        return new AiCategorizationService(
            $llmConnector, $this->categoryRepository, $this->suggestionRepository, new JournalService(new JournalRepository($this->pdo)),
            $this->accountRepository, $this->transactionAttachmentRepository, $this->attachmentRepository,
            $this->calendarRepository, $this->calendarEventRepository
        );
    }

    private function createTransaction(string $label, string $transactionDate = '2026-10-01'): Transaction
    {
        $id = $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, $transactionDate, $label, -20.0, null, null, Transaction::SOURCE_MANUAL, null
        );
        return $this->transactionRepository->findById($id);
    }

    public function testIsAvailableFalseWhenNoConnector(): void
    {
        $this->assertFalse($this->service(null)->isAvailable());
    }

    public function testIsAvailableReflectsConnector(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);

        $this->assertTrue($this->service($llmConnector)->isAvailable());
    }

    public function testCategorizeReturnsNullWithNoConnector(): void
    {
        $transaction = $this->createTransaction('Achat');
        $this->assertNull($this->service(null)->categorize($transaction));
    }

    public function testCategorizeMatchesExistingCategoryByName(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $transaction = $this->createTransaction('VIR Delhaize');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->once())->method('complete')
            ->with($this->callback(fn(LlmRequest $r) => str_contains($r->prompt, 'Alimentation') && str_contains($r->prompt, 'VIR Delhaize')))
            ->willReturn(new LlmResponse('{}', ['category' => 'Alimentation', 'new_category_suggestion' => null], 10, 5));

        $result = $this->service($llmConnector)->categorize($transaction);

        $this->assertSame($categoryId, $result);
    }

    public function testCategorizeMatchIsCaseAndTrimInsensitive(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $transaction = $this->createTransaction('VIR Delhaize');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('complete')->willReturn(new LlmResponse('{}', ['category' => '  ALIMENTATION  ', 'new_category_suggestion' => null], 10, 5));

        $this->assertSame($categoryId, $this->service($llmConnector)->categorize($transaction));
    }

    public function testCategorizeReturnsNullAndRecordsSuggestionWhenNoCategoryFits(): void
    {
        $this->categoryRepository->create('Alimentation');
        $transaction = $this->createTransaction('Achat inhabituel');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            '{}', ['category' => null, 'new_category_suggestion' => 'Fournitures de bureau'], 10, 5
        ));

        $result = $this->service($llmConnector)->categorize($transaction);

        $this->assertNull($result);
        $this->assertSame(['Fournitures de bureau'], $this->suggestionRepository->findRecent());
    }

    public function testCategorizeReturnsNullWhenModelNamesAnUnknownCategory(): void
    {
        $this->categoryRepository->create('Alimentation');
        $transaction = $this->createTransaction('Achat');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            '{}', ['category' => 'Catégorie Inexistante', 'new_category_suggestion' => null], 10, 5
        ));

        $this->assertNull($this->service($llmConnector)->categorize($transaction));
    }

    public function testCategorizeReturnsNullOnLlmException(): void
    {
        $transaction = $this->createTransaction('Achat');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('complete')->willThrowException(LlmException::noProvider());

        $this->assertNull($this->service($llmConnector)->categorize($transaction));
    }

    public function testCategorizeReturnsNullWhenResponseIsUnstructured(): void
    {
        $transaction = $this->createTransaction('Achat');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('complete')->willReturn(new LlmResponse('not json', null, 10, 5));

        $this->assertNull($this->service($llmConnector)->categorize($transaction));
    }

    public function testPromptIncludesCategoryDescriptions(): void
    {
        $this->categoryRepository->create('Alimentation', "Achats de nourriture pour les activités.");
        $transaction = $this->createTransaction('VIR Delhaize');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->once())->method('complete')
            ->with($this->callback(fn(LlmRequest $r) => str_contains($r->prompt, 'Achats de nourriture pour les activités.')))
            ->willReturn(new LlmResponse('{}', ['category' => null, 'new_category_suggestion' => null], 10, 5));

        $this->service($llmConnector)->categorize($transaction);
    }

    public function testPromptIncludesNearbySectionCalendarEvents(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $sectionId = $this->createSection($this->accountId, 'LOU');
        $calendarId = $this->calendarRepository->createSectionCalendar($sectionId, 'public');
        $this->calendarEventRepository->create($calendarId, 'Weekend de section', '2026-10-10', null, null, null, null, 'Weekend à la ferme', null);
        // outside the ±3 week window — must not appear
        $this->calendarEventRepository->create($calendarId, 'Camp été', '2027-07-01', null, null, null, null, null, null);

        $transaction = $this->createTransaction('VIR Cotisation weekend', '2026-10-01');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->once())->method('complete')
            ->with($this->callback(
                fn(LlmRequest $r) => str_contains($r->prompt, 'Weekend de section')
                    && str_contains($r->prompt, 'Weekend à la ferme')
                    && !str_contains($r->prompt, 'Camp été')
            ))
            ->willReturn(new LlmResponse('{}', ['category' => null, 'new_category_suggestion' => null], 10, 5));

        $this->service($llmConnector)->categorize($transaction);
    }

    public function testPromptIncludesAttachedReceiptDescription(): void
    {
        $transaction = $this->createTransaction('VIR Fournisseur');
        $fileId = $this->createFile();
        $attachmentId = $this->attachmentRepository->create($this->accountId, $fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->attachmentRepository->updateSuggestedDescription($attachmentId, 'Achat de matériel de camping pour le camp été');
        $this->transactionAttachmentRepository->associate($transaction->id, $attachmentId);

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->once())->method('complete')
            ->with($this->callback(fn(LlmRequest $r) => str_contains($r->prompt, 'Achat de matériel de camping pour le camp été')))
            ->willReturn(new LlmResponse('{}', ['category' => null, 'new_category_suggestion' => null], 10, 5));

        $this->service($llmConnector)->categorize($transaction);
    }

    public function testCategorizeWorksWithoutOptionalDependencies(): void
    {
        $transaction = $this->createTransaction('Achat');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('complete')->willReturn(new LlmResponse('{}', ['category' => null, 'new_category_suggestion' => null], 10, 5));

        $service = new AiCategorizationService(
            $llmConnector, $this->categoryRepository, $this->suggestionRepository, new JournalService(new JournalRepository($this->pdo))
        );

        $this->assertNull($service->categorize($transaction));
    }

    private function createSection(int $accountId, string $deskCode): int
    {
        $ageBranch = $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $ageBranch, 'Louveteaux']);
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE finance_accounts SET section_id = ? WHERE id = ?')->execute([$sectionId, $accountId]);
        return $sectionId;
    }

    private function createFile(): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('x', 'facture.pdf', 'application/pdf', 1)");
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }
}
