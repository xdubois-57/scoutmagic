<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
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

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->service = new FinanceService(
            $this->accountRepository,
            new CategoryRepository($this->pdo),
            new FiscalYearRepository($this->pdo),
            $sectionService
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

    public function testActivateAccountRejectsMissingIbanOrHolderName(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $this->expectException(FinanceException::class);
        $this->service->activateAccount($account->id);
    }

    public function testActivateAccountRejectsHolderNameAloneWithoutIban(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, null, 'Titulaire', 'intendant');

        $this->expectException(FinanceException::class);
        $this->service->activateAccount($account->id);
    }

    public function testActivateAccountSucceedsWithIbanAndHolderName(): void
    {
        $account = $this->service->createAccount('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');

        $this->service->activateAccount($account->id);

        $this->assertSame(Account::STATUS_ACTIVE, $this->accountRepository->findById($account->id)->status);
    }

    public function testGetAccountsForUserExcludesDraftAndArchivedAndBelowFloor(): void
    {
        $draft = $this->service->createAccount('Brouillon', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');
        $active = $this->service->createAccount('Actif intendant', Account::TYPE_BANK, null, 'BE92001511757024', 'Titulaire', 'intendant');
        $this->service->activateAccount($active->id);
        $adminOnly = $this->service->createAccount('Réservé admin', Account::TYPE_BANK, null, 'BE92001511757025', 'Titulaire', 'admin');
        $this->service->activateAccount($adminOnly->id);

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
        $stmt = $this->pdo->prepare("INSERT INTO finance_fiscal_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $stmt->execute();
        $fiscalYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO finance_transactions (account_id, fiscal_year_id, transaction_date, label, amount, category_id, source) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$accountId, $fiscalYearId, '2026-10-01', 'x', -1.0, $category->id, 'manual']);

        $this->expectException(FinanceException::class);
        $this->service->deleteCategory($category->id);
    }

    public function testCreateFiscalYearRejectsInvertedDateRange(): void
    {
        $this->expectException(FinanceException::class);

        $this->service->createFiscalYear('2026-2027', '2027-08-31', '2026-09-01');
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
}
