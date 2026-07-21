<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class AccountRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AccountRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new AccountRepository($this->pdo, $encryption);
    }

    public function testCreateAndFindByIdRoundTripsEncryptedFields(): void
    {
        $id = $this->repository->create(
            'Compte Louveteaux',
            Account::TYPE_BANK,
            null,
            'BE92001511757023',
            'ASBL Les Scouts',
            'intendant'
        );

        $account = $this->repository->findById($id);

        $this->assertNotNull($account);
        $this->assertSame('Compte Louveteaux', $account->name);
        $this->assertSame('BE92001511757023', $account->iban);
        $this->assertSame('ASBL Les Scouts', $account->holderName);
        $this->assertSame(Account::STATUS_DRAFT, $account->status);
        $this->assertSame('intendant', $account->roleMinView);
    }

    public function testIbanIsStoredEncryptedNotInPlaintext(): void
    {
        $id = $this->repository->create('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');

        $stmt = $this->pdo->prepare('SELECT iban FROM finance_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $rawIban = $stmt->fetchColumn();

        $this->assertNotSame('BE92001511757023', $rawIban);
        $this->assertStringNotContainsString('BE92001511757023', (string) $rawIban);
    }

    public function testFindByIbanBlindIndexFindsMatchingAccount(): void
    {
        $id = $this->repository->create('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');
        $account = $this->repository->findById($id);

        $found = $this->repository->findByIbanBlindIndex($this->blindIndexFor($account));
        $this->assertNotNull($found);
        $this->assertSame($id, $found->id);
    }

    public function testFindByIbanBlindIndexReturnsNullForUnknownIban(): void
    {
        $this->repository->create('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'intendant');

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->assertNull($this->repository->findByIbanBlindIndex($encryption->blindIndex('BE00000000000000')));
    }

    public function testUpdateStatus(): void
    {
        $id = $this->repository->create('Compte', Account::TYPE_CASH, null, null, null, 'intendant');

        $this->repository->updateStatus($id, Account::STATUS_ACTIVE);

        $this->assertSame(Account::STATUS_ACTIVE, $this->repository->findById($id)->status);
    }

    public function testUpdateChangesAllFields(): void
    {
        $id = $this->repository->create('Ancien nom', Account::TYPE_BANK, null, null, null, 'intendant');

        $this->repository->update($id, 'Nouveau nom', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire', 'admin');

        $account = $this->repository->findById($id);
        $this->assertSame('Nouveau nom', $account->name);
        $this->assertSame('BE92001511757023', $account->iban);
        $this->assertSame('admin', $account->roleMinView);
    }

    public function testUpdateWithNullIbanAndHolderNamePreservesExistingValues(): void
    {
        $id = $this->repository->create('Compte', Account::TYPE_BANK, null, 'BE92001511757023', 'Titulaire original', 'intendant');

        $this->repository->update($id, 'Nouveau nom', Account::TYPE_BANK, null, null, null, 'admin');

        $account = $this->repository->findById($id);
        $this->assertSame('Nouveau nom', $account->name);
        $this->assertSame('admin', $account->roleMinView);
        $this->assertSame('BE92001511757023', $account->iban);
        $this->assertSame('Titulaire original', $account->holderName);
    }

    private function blindIndexFor(Account $account): string
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        return $encryption->blindIndex($account->iban);
    }
}
