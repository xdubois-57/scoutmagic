<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Modules\Gallery\Repository\ZipDownloadRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

#[Group('database')]
class ZipDownloadRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ZipDownloadRepository $repository;
    private int $accountId;
    private int $otherAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc-a', 'idx-a']);
        $this->accountId = (int) $this->pdo->lastInsertId();
        $stmt->execute(['enc-b', 'idx-b']);
        $this->otherAccountId = (int) $this->pdo->lastInsertId();
        $this->repository = new ZipDownloadRepository($this->pdo);
    }

    public function testCountsOnlyThisAccountsRowsSinceTheGivenInstant(): void
    {
        $this->repository->record($this->accountId, '2026-11-13 09:00:00');
        $this->repository->record($this->accountId, '2026-11-13 10:00:00');
        $this->repository->record($this->accountId, '2026-11-13 10:30:00');
        $this->repository->record($this->otherAccountId, '2026-11-13 10:30:00');

        $this->assertSame(2, $this->repository->countSince($this->accountId, '2026-11-13 10:00:00'));
        $this->assertSame(1, $this->repository->countSince($this->otherAccountId, '2026-11-13 10:00:00'));
        $this->assertSame(0, $this->repository->countSince($this->accountId, '2026-11-13 11:00:00'));
    }

    public function testDeleteOlderThanRemovesOnlyOlderRows(): void
    {
        $this->repository->record($this->accountId, '2026-11-13 08:00:00');
        $this->repository->record($this->otherAccountId, '2026-11-13 08:59:59');
        $this->repository->record($this->accountId, '2026-11-13 09:00:00');

        $this->assertSame(2, $this->repository->deleteOlderThan('2026-11-13 09:00:00'));
        $this->assertSame(1, $this->repository->countSince($this->accountId, '2000-01-01 00:00:00'));
        $this->assertSame(0, $this->repository->countSince($this->otherAccountId, '2000-01-01 00:00:00'));
    }

    /**
     * The row says who downloaded when, and nothing else.
     */
    public function testARowHoldsOnlyTheAccountAndTheInstant(): void
    {
        $this->repository->record($this->accountId, '2026-11-13 10:00:00');

        $row = $this->pdo->query('SELECT * FROM gallery_zip_downloads')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        unset($row['id']);
        $this->assertSame(
            ['user_account_id' => $this->accountId, 'created_at' => '2026-11-13 10:00:00'],
            ['user_account_id' => (int) $row['user_account_id'], 'created_at' => $row['created_at']]
        );
        $this->assertSame(['user_account_id', 'created_at'], array_keys($row));
    }
}
