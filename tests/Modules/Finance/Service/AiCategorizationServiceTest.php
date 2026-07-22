<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AiCategorizationService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
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
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->suggestionRepository = new AiCategorySuggestionRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function service(?LlmConnectorInterface $llmConnector): AiCategorizationService
    {
        return new AiCategorizationService(
            $llmConnector, $this->categoryRepository, $this->suggestionRepository, new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function createTransaction(string $label): Transaction
    {
        $id = $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01', $label, -20.0, null, null, Transaction::SOURCE_MANUAL, null
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
}
