<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Service\FinanceAccountService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class FinanceAccountServiceTest extends TestCase
{
    private \PDO $pdo;
    private AccountRepository $accountRepository;
    private FinanceAccountService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->service = new FinanceAccountService($this->accountRepository);
    }

    public function testGetConfiguredAccountsReturnsOnlyActiveAccounts(): void
    {
        $activeId = $this->accountRepository->create('Compte actif', 'bank', null, 'BE68539007547034', 'Unité', 'intendant');
        $this->accountRepository->updateStatus($activeId, 'active');

        $draftId = $this->accountRepository->create('Compte brouillon', 'bank', null, null, null, 'intendant');

        $accounts = $this->service->getConfiguredAccounts();

        $ids = array_column($accounts, 'id');
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($draftId, $ids);
    }

    public function testGetConfiguredAccountsExposesIbanAndSectionId(): void
    {
        $id = $this->accountRepository->create('Compte Louveteaux', 'bank', 3, 'BE68539007547034', 'Unité', 'intendant');
        $this->accountRepository->updateStatus($id, 'active');

        $accounts = $this->service->getConfiguredAccounts();
        $found = current(array_filter($accounts, fn($a) => $a['id'] === $id));

        $this->assertNotFalse($found);
        $this->assertSame('BE68539007547034', $found['iban']);
        $this->assertSame('Unité', $found['holder_name']);
        $this->assertSame(3, $found['section_id']);
    }

    public function testGetDefaultAccountForSectionReturnsTheSectionsAccount(): void
    {
        $id = $this->accountRepository->create('Compte Louveteaux', 'bank', 5, 'BE68539007547034', 'Unité', 'intendant');
        $this->accountRepository->updateStatus($id, 'active');

        $this->assertSame($id, $this->service->getDefaultAccountForSection(5));
    }

    public function testGetDefaultAccountForSectionReturnsNullWhenNoneConfigured(): void
    {
        $this->assertNull($this->service->getDefaultAccountForSection(999));
    }

    public function testGetDefaultAccountForSectionIgnoresInactiveAccounts(): void
    {
        $id = $this->accountRepository->create('Compte brouillon', 'bank', 7, null, null, 'intendant');
        // Left in 'draft' status — never activated.

        $this->assertNull($this->service->getDefaultAccountForSection(7));
        // Silence unused-variable static analysis concern about $id being intentionally unused beyond creation.
        $this->assertGreaterThan(0, $id);
    }
}
