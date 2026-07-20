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
class CalendarPersonalTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarPersonalTokenRepository $repository;
    private int $userAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->repository = new CalendarPersonalTokenRepository($this->pdo);

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
}
