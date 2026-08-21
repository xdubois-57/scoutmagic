<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberEmailRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new MemberEmailRepository($this->pdo, $this->encryption);
    }

    /**
     * The plaintext twin of findMemberIdsByValidBlindIndex(), for the caller
     * that needs the address itself: Core\Security\AuthService, giving a
     * confirmed secondary address its own account on first login.
     */
    public function testFindValidByBlindIndexReturnsTheDecryptedAddress(): void
    {
        $this->insert(1, 'alias@test.com', MemberEmail::STATUS_VALID);

        $rows = $this->repository->findValidByBlindIndex($this->blindIndex('alias@test.com'));

        $this->assertCount(1, $rows);
        $this->assertSame('alias@test.com', $rows[0]->email);
        $this->assertSame(1, $rows[0]->memberId);
    }

    /**
     * The same address confirmed on several members (a parent's address on
     * each of their children) is several rows, all carrying that one
     * address — ordered by id, so the caller's "first row wins" is stable.
     */
    public function testFindValidByBlindIndexReturnsEveryMemberHoldingThatAddress(): void
    {
        $this->insert(7, 'parent@test.com', MemberEmail::STATUS_VALID);
        $this->insert(3, 'parent@test.com', MemberEmail::STATUS_VALID);

        $rows = $this->repository->findValidByBlindIndex($this->blindIndex('parent@test.com'));

        $this->assertSame([7, 3], array_map(static fn(MemberEmail $row) => $row->memberId, $rows));
    }

    /**
     * A 'pending' or 'inactive' row grants nothing anywhere — not a login,
     * not a notification (same rule as Core\Security\RoleResolver).
     */
    public function testFindValidByBlindIndexIgnoresPendingAndInactiveRows(): void
    {
        $this->insert(1, 'unconfirmed@test.com', MemberEmail::STATUS_PENDING);
        $this->insert(2, 'unsubscribed@test.com', MemberEmail::STATUS_INACTIVE);

        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('unconfirmed@test.com')));
        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('unsubscribed@test.com')));
    }

    public function testFindValidByBlindIndexReturnsNothingForAnUnknownAddress(): void
    {
        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('nobody@test.com')));
    }

    private function insert(int $memberId, string $email, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', ?)"
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->blindIndex($email),
            $status,
        ]);
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(strtolower($email), 'email');
    }
}
