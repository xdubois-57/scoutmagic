<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Photo\MemberPhotoRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberPhotoRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberPhotoRepository $repository;
    private int $memberId;
    private int $fileIdA;
    private int $fileIdB;
    private int $yearOldId;
    private int $yearMidId;
    private int $yearNewId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new MemberPhotoRepository($this->pdo);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $this->yearOldId = $this->createScoutYear('2023-2024', '2023-09-01');
        $this->yearMidId = $this->createScoutYear('2024-2025', '2024-09-01');
        $this->yearNewId = $this->createScoutYear('2025-2026', '2025-09-01');

        $this->fileIdA = $this->createFile('a.jpg');
        $this->fileIdB = $this->createFile('b.jpg');
    }

    private function createScoutYear(string $label, string $startDate): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, '2099-08-31')"
        );
        $stmt->execute([$label, $startDate]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createFile(string $name): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES (?, ?, 'image/jpeg', 100)"
        );
        $stmt->execute(['core/member_photos/' . $name, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testFindFileIdReturnsNullWhenNoPhoto(): void
    {
        $result = $this->repository->findFileIdForYearOrEarlier($this->memberId, $this->yearNewId);

        $this->assertNull($result);
    }

    public function testFindFileIdReturnsExactYearMatch(): void
    {
        $this->repository->upsert($this->memberId, $this->yearNewId, $this->fileIdA, null);

        $result = $this->repository->findFileIdForYearOrEarlier($this->memberId, $this->yearNewId);

        $this->assertSame($this->fileIdA, $result);
    }

    public function testFindFileIdFallsBackToMostRecentEarlierYear(): void
    {
        $this->repository->upsert($this->memberId, $this->yearOldId, $this->fileIdA, null);
        $this->repository->upsert($this->memberId, $this->yearMidId, $this->fileIdB, null);

        // No photo for the newest year -> falls back to the most recent earlier one (mid, not old).
        $result = $this->repository->findFileIdForYearOrEarlier($this->memberId, $this->yearNewId);

        $this->assertSame($this->fileIdB, $result);
    }

    public function testFindFileIdIgnoresFutureYearPhotos(): void
    {
        $this->repository->upsert($this->memberId, $this->yearNewId, $this->fileIdA, null);

        // Looking at the old year: the newer photo must NOT be used.
        $result = $this->repository->findFileIdForYearOrEarlier($this->memberId, $this->yearOldId);

        $this->assertNull($result);
    }

    public function testUpsertReplacesExistingPhotoForSameYear(): void
    {
        $this->repository->upsert($this->memberId, $this->yearNewId, $this->fileIdA, null);
        $this->repository->upsert($this->memberId, $this->yearNewId, $this->fileIdB, null);

        $result = $this->repository->findFileIdForYearOrEarlier($this->memberId, $this->yearNewId);

        $this->assertSame($this->fileIdB, $result);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM member_photos')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
