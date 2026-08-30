<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\SuperAdminService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SuperAdminServiceTest extends TestCase
{
    private \PDO $pdo;
    private UserAccountRepository $userRepo;
    private SuperAdminService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $encryption);
        $this->service = new SuperAdminService(
            $this->userRepo,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    public function testDeactivateWithdrawsAccessAndJournalsIt(): void
    {
        $account = $this->userRepo->create('admin@test.com', true);
        $actor = $this->userRepo->create('actor@test.com', true);

        $this->service->deactivate($account->id, $actor->id);

        $this->assertFalse($this->userRepo->findById($account->id)?->isActive);

        $entry = $this->lastJournalEntry();
        $this->assertSame('super_admin_deactivated', $entry['event_type']);
        $this->assertSame('security', $entry['level']);
        $this->assertSame($actor->id, (int) $entry['user_account_id']);
    }

    public function testReactivateRestoresAccessAndJournalsIt(): void
    {
        $account = $this->userRepo->create('admin@test.com', true);
        $this->userRepo->create('other-admin@test.com', true);
        $this->service->deactivate($account->id, null);

        $this->service->reactivate($account->id, null);

        $this->assertTrue($this->userRepo->findById($account->id)?->isActive);

        $entry = $this->lastJournalEntry();
        $this->assertSame('super_admin_reactivated', $entry['event_type']);
        $this->assertSame('security', $entry['level']);
    }

    /**
     * An email address is personal data, and the journal is not where it
     * belongs (AGENTS.md § Security checklist #4). The account id is what
     * makes the entry traceable without storing the address.
     */
    public function testTheJournalEntryNamesTheAccountIdAndNeverTheAddress(): void
    {
        $account = $this->userRepo->create('admin@test.com', true);
        $this->userRepo->create('other-admin@test.com', true);

        $this->service->deactivate($account->id, null);
        $entry = $this->lastJournalEntry();

        $this->assertSame(
            ['user_account_id' => $account->id],
            json_decode((string) $entry['context'], true)
        );

        $wholeRow = implode(' ', array_map(static fn($v): string => (string) $v, $entry));
        $this->assertStringNotContainsString('admin@test.com', $wholeRow);
        $this->assertStringNotContainsString('@', $wholeRow);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastJournalEntry(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM event_log ORDER BY id DESC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        $this->assertIsArray($row, 'no journal entry was written');

        return $row;
    }
}
