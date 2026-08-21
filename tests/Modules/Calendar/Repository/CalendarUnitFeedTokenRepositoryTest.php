<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Repository;

use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CalendarUnitFeedTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarUnitFeedTokenRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->repository = new CalendarUnitFeedTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
    }

    public function testFindTokenReturnsNullWhenNoneExists(): void
    {
        $this->assertNull($this->repository->findToken());
    }

    public function testSetTokenCreatesRowWhenNoneExists(): void
    {
        $this->repository->setToken('tok-1');

        $this->assertSame('tok-1', $this->repository->findToken());
    }

    public function testSetTokenReplacesTheSingleRow(): void
    {
        $this->repository->setToken('tok-1');
        $this->repository->setToken('tok-2');

        $this->assertSame('tok-2', $this->repository->findToken());
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM calendar_unit_feed_token');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testTokenExists(): void
    {
        $this->repository->setToken('tok-1');

        $this->assertTrue($this->repository->tokenExists('tok-1'));
        $this->assertFalse($this->repository->tokenExists('nope'));
    }

    public function testTheTokenIsNeverStoredInPlaintext(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->repository->setToken($token);

        $row = $this->pdo->query('SELECT token_encrypted, token_blind_index FROM calendar_unit_feed_token')->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString($token, (string) $row['token_encrypted']);
        $this->assertNotSame($token, $row['token_blind_index']);
        $this->assertNotSame(hash('sha256', $token), $row['token_blind_index']);

        $this->assertTrue($this->repository->tokenExists($token));
        $this->assertSame($token, $this->repository->findToken());
    }
}
