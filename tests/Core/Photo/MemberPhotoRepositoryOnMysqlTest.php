<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Photo\MemberPhotoRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * `MemberPhotoRepository::insertIfAbsent()` on the engine production runs:
 * whether a photo is « absent » is decided by the UNIQUE index
 * (member_id, scout_year_id) refusing the row — error 1062 on MySQL and
 * MariaDB, which SQLite reports differently — and the data migration import
 * calls it inside the transaction of one item, which must survive the
 * refusal.
 */
#[Group('database')]
final class MemberPhotoRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private MemberPhotoRepository $repository;
    private int $memberId;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new MemberPhotoRepository($this->pdo);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2022-2023', '2022-09-01', '2023-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
    }

    public function testASecondPhotoForTheSameYearIsRefusedAndTheFirstKept(): void
    {
        $first = $this->file('a.jpg');
        $second = $this->file('b.jpg');

        $this->assertFalse($this->repository->hasPhotoForYear($this->memberId, $this->yearId));
        $this->assertTrue($this->repository->insertIfAbsent($this->memberId, $this->yearId, $first, null));
        $this->assertTrue($this->repository->hasPhotoForYear($this->memberId, $this->yearId));
        $this->assertFalse($this->repository->insertIfAbsent($this->memberId, $this->yearId, $second, null));

        $this->assertSame($first, $this->repository->findFileIdForYearOrEarlier($this->memberId, $this->yearId));
    }

    public function testARefusedDuplicateLeavesTheCallersTransactionUsable(): void
    {
        $first = $this->file('a.jpg');
        $this->repository->insertIfAbsent($this->memberId, $this->yearId, $first, null);

        $this->pdo->beginTransaction();
        $written = $this->file('written-in-the-transaction.jpg');
        $this->assertFalse($this->repository->insertIfAbsent($this->memberId, $this->yearId, $written, null));
        // The premise: the engine rolled back the refused statement only.
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->commit();

        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM files WHERE original_name = 'written-in-the-transaction.jpg'")
                ->fetchColumn()
        );
    }

    public function testAnotherConstraintIsNotTakenForADuplicate(): void
    {
        $this->expectException(\PDOException::class);

        $this->repository->insertIfAbsent($this->memberId + 1000, $this->yearId, $this->file('a.jpg'), null);
    }

    private function file(string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES (?, ?, 'image/jpeg', 100)"
        )->execute(['core/member_photos/' . $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
