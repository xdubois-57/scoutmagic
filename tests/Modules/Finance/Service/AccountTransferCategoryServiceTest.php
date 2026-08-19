<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The derived "Virement <compte>" category and its system rule, which make
 * an inter-account transfer show up as such rather than as ordinary income
 * or expense. It had no test file of its own.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AccountTransferCategoryServiceTest extends TestCase
{
    private \PDO $pdo;
    private AccountRepository $accountRepository;
    private CategoryRepository $categoryRepository;
    private CategoryRuleRepository $categoryRuleRepository;
    private TransactionRepository $transactionRepository;
    private AccountTransferCategoryService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);

        $this->service = new AccountTransferCategoryService(
            $this->categoryRepository, $this->categoryRuleRepository, $this->transactionRepository
        );
    }

    public function testSyncCreatesACategoryAndASystemRuleForAnEligibleAccount(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');

        $this->service->sync($account);

        $category = $this->categoryRepository->findByAccountId($account->id);
        $this->assertNotNull($category);
        $this->assertSame('Virement Louveteaux', $category->name);

        $rule = $this->categoryRuleRepository->findSystemRuleForCategory($category->id);
        $this->assertNotNull($rule);
        $this->assertTrue($rule->isSystem);
        $this->assertSame('BE92001511757023', $rule->counterpartyAccountPattern);
    }

    /**
     * The system rule must outrank every admin-authored rule, which start
     * numbering from the count of existing rules (so effectively 0, 1, 2…).
     */
    public function testTheSystemRuleGetsAStrongyNegativePriority(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');

        $this->service->sync($account);

        $category = $this->categoryRepository->findByAccountId($account->id);
        $rule = $this->categoryRuleRepository->findSystemRuleForCategory($category->id);
        $this->assertLessThan(0, $rule->priority);
    }

    public function testSyncIsIdempotent(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');

        $this->service->sync($account);
        $this->service->sync($account);
        $this->service->sync($account);

        $this->assertCount(1, $this->categoryRepository->findAllOrdered());
        $this->assertCount(1, $this->categoryRuleRepository->findAllOrderedByPriority());
    }

    public function testSyncUpdatesTheRuleWhenTheIbanChanges(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');
        $this->service->sync($account);

        $this->accountRepository->update($account->id, 'Louveteaux', Account::TYPE_BANK, null, 'BE43001234567892', 'Titulaire', 'intendant');
        $this->service->sync($this->accountRepository->findById($account->id));

        $category = $this->categoryRepository->findByAccountId($account->id);
        $rule = $this->categoryRuleRepository->findSystemRuleForCategory($category->id);
        $this->assertSame('BE43001234567892', $rule->counterpartyAccountPattern);
        $this->assertCount(1, $this->categoryRuleRepository->findAllOrderedByPriority());
    }

    /**
     * The category is found by account_id, never by name, so an admin
     * renaming it must not cause a duplicate to be created.
     */
    public function testARenamedCategoryIsReusedRatherThanDuplicated(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');
        $this->service->sync($account);
        $category = $this->categoryRepository->findByAccountId($account->id);
        $this->categoryRepository->update($category->id, 'Transferts Louveteaux', 'Renommée par un admin');

        $this->service->sync($account);

        $this->assertCount(1, $this->categoryRepository->findAllOrdered());
        $this->assertSame('Transferts Louveteaux', $this->categoryRepository->findByAccountId($account->id)->name);
    }

    /**
     * @dataProvider ineligibleAccounts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ineligibleAccounts')]
    public function testSyncRemovesEverythingForAnIneligibleAccount(string $status, ?string $iban): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');
        $this->service->sync($account);
        $this->assertNotNull($this->categoryRepository->findByAccountId($account->id));

        $this->pdo->prepare('UPDATE finance_accounts SET status = ? WHERE id = ?')->execute([$status, $account->id]);
        if ($iban === null) {
            $this->accountRepository->update($account->id, 'Louveteaux', Account::TYPE_BANK, null, null, null, 'intendant');
        }

        $this->service->sync($this->accountRepository->findById($account->id));

        $this->assertNull($this->categoryRepository->findByAccountId($account->id));
        $this->assertSame([], $this->categoryRuleRepository->findAllOrderedByPriority());
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function ineligibleAccounts(): array
    {
        return [
            'deactivated' => [Account::STATUS_INACTIVE, 'BE92001511757023'],
            'back to draft' => [Account::STATUS_DRAFT, 'BE92001511757023'],
            'iban removed' => [Account::STATUS_ACTIVE, null],
        ];
    }

    /**
     * Unlike an admin-facing category deletion (refused while movements
     * reference it), this derived category is removed and its movements
     * simply fall back to uncategorized.
     */
    public function testRemoveForUnlinksTheMovementsThatUsedTheCategory(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');
        $this->service->sync($account);
        $categoryId = $this->categoryRepository->findByAccountId($account->id)->id;

        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $transactionId = $this->transactionRepository->create(
            $account->id, $fiscalYearId, null, '2026-10-01', 'Virement interne', -50.0, $categoryId, null, 'manual', null,
            null, null, null, 'auto'
        );

        $this->service->removeFor($account->id);

        $this->assertNull($this->transactionRepository->findById($transactionId)->categoryId);
        $this->assertNull($this->transactionRepository->findById($transactionId)->categorySource);
        $this->assertNull($this->categoryRepository->findById($categoryId));
    }

    public function testRemoveForIsANoOpWhenTheAccountNeverHadOne(): void
    {
        $account = $this->activeBankAccount('Louveteaux', 'BE92001511757023');

        $this->service->removeFor($account->id);

        $this->assertSame([], $this->categoryRepository->findAllOrdered());
    }

    private function activeBankAccount(string $name, string $iban): Account
    {
        $id = $this->accountRepository->create($name, Account::TYPE_BANK, null, $iban, 'Titulaire', 'intendant');
        $this->accountRepository->updateStatus($id, Account::STATUS_ACTIVE);
        return $this->accountRepository->findById($id);
    }
}
