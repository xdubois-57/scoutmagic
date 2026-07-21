<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class FinanceServiceTest extends TestCase
{
    private \PDO $pdo;
    private FinanceService $service;
    private AccountRepository $accountRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private CategoryRepository $categoryRepository;
    private TransactionRepository $transactionRepository;
    private BalanceCheckpointRepository $checkpointRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo, new ScoutYearService($this->pdo));
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);

        $this->service = new FinanceService(
            $this->accountRepository,
            $this->categoryRepository,
            $this->fiscalYearRepository,
            $sectionService,
            $this->transactionRepository,
            $balanceService
        );
    }

    public function testCreateAccountRejectsRoleMinViewBelowIntendant(): void
    {
        $this->expectException(FinanceException::class);

        $this->service->createAccount('Compte', Account::TYPE_BANK, null, null, null, 'identified');
    }

    public function testCreateAccountRejectsInvalidAccountType(): void
    {
        $this->expectException(FinanceException::class);

        $this->service->createAccount('Compte', 'crypto', null, null, null, 'intendant');
    }

    public function testCreateAccountRejectsEmptyName(): void
    {
        $this->expectException(FinanceException::class);

        $this->service->createAccount('   ', Account::TYPE_BANK, null, null, null, 'intendant');
    }

    public function testCreateAccountAcceptsValidRoleMinViewValues(): void
    {
        foreach (['intendant', 'chief', 'admin'] as $roleMinView) {
            $account = $this->service->createAccount("Compte {$roleMinView}", Account::TYPE_BANK, null, null, null, $roleMinView);
            $this->assertSame($roleMinView, $account->roleMinView);
        }
    }

    public function testCreateAccountStaysDraftWithoutIbanOrHolderName(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $this->assertSame(Account::STATUS_DRAFT, $this->accountRepository->findById($account->id)->status);
    }

    public function testCreateAccountStaysDraftWithHolderNameAloneWithoutIban(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, null, 'Titulaire', 'intendant');

        $this->assertSame(Account::STATUS_DRAFT, $this->accountRepository->findById($account->id)->status);
    }

    public function testCreateAccountActivatesAutomaticallyWithIbanAndHolderName(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');

        $this->assertSame(Account::STATUS_ACTIVE, $this->accountRepository->findById($account->id)->status);
    }

    public function testCreateCashAccountActivatesImmediatelyWithoutIban(): void
    {
        $account = $this->service->createAccount('Caisse', Account::TYPE_CASH, null, null, null, 'intendant');

        $this->assertSame(Account::STATUS_ACTIVE, $this->accountRepository->findById($account->id)->status);
    }

    public function testUpdateAccountActivatesAutomaticallyOnceIbanAndHolderAreAdded(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->assertSame(Account::STATUS_DRAFT, $this->accountRepository->findById($account->id)->status);

        $this->service->updateAccount($account->id, 'Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');

        $this->assertSame(Account::STATUS_ACTIVE, $this->accountRepository->findById($account->id)->status);
    }

    public function testUpdateAccountNeverDeactivatesAnAlreadyActiveOrArchivedAccount(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');
        $this->service->archiveAccount($account->id);

        $this->service->updateAccount($account->id, 'Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');

        $this->assertSame(Account::STATUS_ARCHIVED, $this->accountRepository->findById($account->id)->status);
    }

    public function testGetAccountsForUserExcludesDraftAndArchivedAndBelowFloor(): void
    {
        $draft = $this->service->createAccount('Brouillon', Account::TYPE_BANK, null, null, null, 'intendant');
        $active = $this->service->createAccount('Actif intendant', Account::TYPE_BANK, null, 'BE92001511757024', 'Titulaire', 'intendant');
        $adminOnly = $this->service->createAccount('Réservé admin', Account::TYPE_BANK, null, 'BE92001511757025', 'Titulaire', 'admin');

        $visibleToIntendant = $this->service->getAccountsForUser(Role::INTENDANT);
        $this->assertCount(1, $visibleToIntendant);
        $this->assertSame($active->id, $visibleToIntendant[0]->id);

        $visibleToAdmin = $this->service->getAccountsForUser(Role::ADMIN);
        $this->assertCount(2, $visibleToAdmin);
    }

    public function testDeleteCategoryRejectsWhenReferencedByTransactions(): void
    {
        $category = $this->service->createCategory('Alimentation');

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $accountId = (int) $this->pdo->lastInsertId();
        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $stmt = $this->pdo->prepare('INSERT INTO finance_transactions (account_id, fiscal_year_id, transaction_date, label, amount, category_id, source) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$accountId, $fiscalYearId, '2026-10-01', 'x', -1.0, $category->id, 'manual']);

        $this->expectException(FinanceException::class);
        $this->service->deleteCategory($category->id);
    }

    public function testEnsureDefaultAccountsForSectionsIsIdempotent(): void
    {
        $branchStmt = $this->pdo->prepare("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 10)");
        $branchStmt->execute();
        $branchId = (int) $this->pdo->lastInsertId();
        $sectionStmt = $this->pdo->prepare("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', ?, 'Renards')");
        $sectionStmt->execute([$branchId]);

        $this->service->ensureDefaultAccountsForSections();
        $this->assertCount(1, $this->service->getAllAccountsForConfig());

        $this->service->ensureDefaultAccountsForSections();
        $this->assertCount(1, $this->service->getAllAccountsForConfig());
    }

    // --- getCategorySummary() ---

    public function testGetCategorySummaryReturnsEmptyArrayWhenNoMovements(): void
    {
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear();

        $this->assertSame([], $this->service->getCategorySummary($accountId, $fiscalYearId));
    }

    public function testGetCategorySummaryAggregatesIncomeAndExpensePerCategory(): void
    {
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear();
        $alimentation = $this->service->createCategory('Alimentation');
        $this->createMovement($accountId, $fiscalYearId, -20.0, $alimentation->id);
        $this->createMovement($accountId, $fiscalYearId, -30.0, $alimentation->id);
        $this->createMovement($accountId, $fiscalYearId, 100.0, $alimentation->id);

        $summary = $this->service->getCategorySummary($accountId, $fiscalYearId);

        $this->assertCount(1, $summary);
        $this->assertSame($alimentation->id, $summary[0]['category_id']);
        $this->assertSame(100.0, $summary[0]['income']);
        $this->assertSame(50.0, $summary[0]['expense']);
        $this->assertSame(50.0, $summary[0]['total']);
    }

    public function testGetCategorySummaryGroupsUncategorizedMovements(): void
    {
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear();
        $this->createMovement($accountId, $fiscalYearId, -15.0, null);

        $summary = $this->service->getCategorySummary($accountId, $fiscalYearId);

        $this->assertCount(1, $summary);
        $this->assertNull($summary[0]['category_id']);
        $this->assertNull($summary[0]['category_name']);
    }

    public function testGetCategorySummarySortedByIncomeDescending(): void
    {
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear();
        $low = $this->service->createCategory('Faibles recettes');
        $high = $this->service->createCategory('Fortes recettes');
        $this->createMovement($accountId, $fiscalYearId, 10.0, $low->id);
        $this->createMovement($accountId, $fiscalYearId, 500.0, $high->id);

        $summary = $this->service->getCategorySummary($accountId, $fiscalYearId);

        $this->assertSame($high->id, $summary[0]['category_id']);
        $this->assertSame($low->id, $summary[1]['category_id']);
    }

    public function testGetCategorySummaryScopedToAccountAndFiscalYear(): void
    {
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear();
        $otherAccountId = $this->accountRepository->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->createMovement($accountId, $fiscalYearId, -20.0, null);
        $this->createMovement($otherAccountId, $fiscalYearId, -999.0, null);

        $summary = $this->service->getCategorySummary($accountId, $fiscalYearId);

        $this->assertSame(20.0, $summary[0]['expense']);
    }

    // --- getBalanceEvolution() ---

    public function testGetBalanceEvolutionEmptyWhenAccountOrFiscalYearUnknown(): void
    {
        $this->assertSame([], $this->service->getBalanceEvolution(9999, 9999));
    }

    public function testGetBalanceEvolutionOneEntryPerMonthForAPastFiscalYear(): void
    {
        // Entirely in the past, so "today" never truncates it — a
        // deterministic 12-entry result regardless of when the test runs.
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear('2020-01-01', '2020-12-31');
        $this->checkpointRepository->create($accountId, '2020-01-01', 1000.0, 'manual');

        $evolution = $this->service->getBalanceEvolution($accountId, $fiscalYearId);

        $this->assertCount(12, $evolution);
        $this->assertSame('2020-01', $evolution[0]['month']);
        $this->assertSame('2020-12', $evolution[11]['month']);
    }

    public function testGetBalanceEvolutionNeverProjectsPastToday(): void
    {
        $today = new \DateTimeImmutable('today');
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear($today->format('Y-m-d'), $today->modify('+1 year')->format('Y-m-d'));
        $this->checkpointRepository->create($accountId, $today->format('Y-m-d'), 1000.0, 'manual');

        $evolution = $this->service->getBalanceEvolution($accountId, $fiscalYearId);

        $lastMonth = end($evolution)['month'];
        $this->assertSame($today->format('Y-m'), $lastMonth);
    }

    public function testGetBalanceEvolutionReflectsCumulativeMovements(): void
    {
        [$accountId, $fiscalYearId] = $this->createAccountAndFiscalYear('2020-01-01', '2020-03-31');
        $this->checkpointRepository->create($accountId, '2020-01-01', 100.0, 'manual');
        $this->createMovementOnDate($accountId, $fiscalYearId, '2020-02-15', -20.0);

        $evolution = $this->service->getBalanceEvolution($accountId, $fiscalYearId);

        $balancesByMonth = [];
        foreach ($evolution as $point) {
            $balancesByMonth[$point['month']] = $point['balance'];
        }

        $this->assertSame(100.0, $balancesByMonth['2020-01']);
        $this->assertSame(80.0, $balancesByMonth['2020-02']);
        $this->assertSame(80.0, $balancesByMonth['2020-03']);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createAccountAndFiscalYear(string $start = '2026-09-01', string $end = '2027-08-31'): array
    {
        $accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, $start . ' - ' . $end, $start, $end);
        return [$accountId, $fiscalYearId];
    }

    private function createMovement(int $accountId, int $fiscalYearId, float $amount, ?int $categoryId): void
    {
        $this->transactionRepository->create(
            $accountId, $fiscalYearId, 'ref-' . uniqid(), '2026-10-01', 'x', $amount, $categoryId, null, Transaction::SOURCE_MANUAL, null
        );
    }

    private function createMovementOnDate(int $accountId, int $fiscalYearId, string $date, float $amount): void
    {
        $this->transactionRepository->create(
            $accountId, $fiscalYearId, 'ref-' . uniqid(), $date, 'x', $amount, null, null, Transaction::SOURCE_MANUAL, null
        );
    }
}
