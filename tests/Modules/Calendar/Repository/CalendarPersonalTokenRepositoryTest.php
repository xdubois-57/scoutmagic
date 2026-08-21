<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Repository;

use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CalendarPersonalTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarPersonalTokenRepository $repository;
    private int $userAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->repository = new CalendarPersonalTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('enc', 'idx')");
        $this->userAccountId = (int) $this->pdo->lastInsertId();
    }

    public function testFindTokenByUserAccountIdReturnsNullWhenNoToken(): void
    {
        $this->assertNull($this->repository->findTokenByUserAccountId($this->userAccountId));
    }

    public function testSetTokenCreatesRowWhenNoneExists(): void
    {
        $this->repository->setToken($this->userAccountId, 'tok-1');

        $this->assertSame('tok-1', $this->repository->findTokenByUserAccountId($this->userAccountId));
    }

    public function testSetTokenReplacesExistingToken(): void
    {
        $this->repository->setToken($this->userAccountId, 'tok-1');
        $this->repository->setToken($this->userAccountId, 'tok-2');

        $this->assertSame('tok-2', $this->repository->findTokenByUserAccountId($this->userAccountId));
        $this->assertNull($this->repository->findUserAccountIdByToken('tok-1'));
    }

    public function testFindUserAccountIdByToken(): void
    {
        $this->repository->setToken($this->userAccountId, 'tok-1');

        $this->assertSame($this->userAccountId, $this->repository->findUserAccountIdByToken('tok-1'));
    }

    public function testFindUserAccountIdByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->repository->findUserAccountIdByToken('nope'));
    }

    public function testTheTokenIsNeverStoredInPlaintext(): void
    {
        // A long-lived feed credential: any DB read must not yield a working
        // ICS URL. Encrypted at rest; the blind index is a keyed HMAC.
        $token = bin2hex(random_bytes(32));
        $this->repository->setToken($this->userAccountId, $token);

        $row = $this->pdo->query('SELECT token_encrypted, token_blind_index FROM calendar_personal_tokens')->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString($token, (string) $row['token_encrypted']);
        $this->assertNotSame($token, $row['token_blind_index']);
        $this->assertNotSame(hash('sha256', $token), $row['token_blind_index']);

        $this->assertSame($token, $this->repository->findTokenByUserAccountId($this->userAccountId));
    }
}
