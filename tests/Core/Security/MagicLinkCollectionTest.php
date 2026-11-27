<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\MagicLinkRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1530: a confirmed magic link opens ONE session. Taking it is a
 * conditional UPDATE (MagicLinkRepository::collect()), and a link confirmed
 * in the session that asked is collected by the statement that confirms it
 * (markUsed(..., collect: true)), so no poll can take it afterwards.
 *
 * Run on SQLite here and on the production engine by
 * MagicLinkCollectionOnMysqlTest, where the second request is a second
 * connection.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MagicLinkCollectionTest extends TestCase
{
    protected \PDO $pdo;
    private MagicLinkRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->database();
        $this->repo = new MagicLinkRepository($this->pdo);
    }

    protected function database(): \PDO
    {
        return \Tests\DatabaseTestHelper::createTestDatabase();
    }

    /** What a second, simultaneous request would hold: its own connection where the engine has one. */
    protected function otherRequestsConnection(): \PDO
    {
        return $this->pdo;
    }

    private function newLink(string $expires = '+15 minutes'): int
    {
        return $this->repo->create(str_repeat('a', 64), '$2y$10$hash', new \DateTimeImmutable($expires));
    }

    public function testAConfirmedLinkIsCollectedOnceAndOnlyOnce(): void
    {
        $id = $this->newLink();
        $this->assertTrue($this->repo->markUsed($id));

        $this->assertTrue($this->repo->collect($id));
        $this->assertFalse((new MagicLinkRepository($this->otherRequestsConnection()))->collect($id));
        $this->assertFalse($this->repo->collect($id));
        $this->assertNotNull($this->repo->findById($id)?->collectedAt);
    }

    public function testALinkConfirmedInTheSessionThatAskedIsCollectedByThatConfirmation(): void
    {
        $id = $this->newLink();

        $this->assertTrue($this->repo->markUsed($id, true));

        $record = $this->repo->findById($id);
        $this->assertNotNull($record?->confirmedAt);
        $this->assertNotNull($record->collectedAt);
        $this->assertFalse((new MagicLinkRepository($this->otherRequestsConnection()))->collect($id));
    }

    public function testALinkConfirmedElsewhereWaitsToBeCollected(): void
    {
        $id = $this->newLink();

        $this->assertTrue($this->repo->markUsed($id));

        $this->assertNull($this->repo->findById($id)?->collectedAt);
    }

    public function testAnUnconfirmedASpentOrAnExpiredLinkCannotBeCollected(): void
    {
        $unconfirmed = $this->newLink();
        $this->assertFalse($this->repo->collect($unconfirmed));

        $spent = $this->newLink();
        $this->repo->invalidate($spent);
        $this->assertFalse($this->repo->collect($spent), 'used, but never confirmed');

        $expired = $this->newLink('-1 minute');
        $this->repo->markUsed($expired);
        $this->assertFalse($this->repo->collect($expired));
    }
}
