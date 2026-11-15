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

    /**
     * The backfill of issue #1108 is an UPDATE of `files` whose value and
     * filter are subqueries on `member_photos` — the shape AGENTS.md
     * § Database asks to see on the production engine (a subquery reading
     * the table being updated would be refused there, error 1093; this one
     * must not be).
     */
    public function testTheOwnershipBackfillRunsOnTheProductionEngine(): void
    {
        $owned = $this->file('a.jpg');
        $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)')
            ->execute([$this->memberId, $this->yearId, $owned]);
        $unrelated = $this->file('b.jpg');

        $this->assertSame(1, $this->repository->backfillFileOwnership());

        $owner = $this->pdo->query("SELECT owner_type, owner_id FROM files WHERE id = {$owned}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('member_photo', $owner['owner_type']);
        $this->assertSame($this->memberId, (int) $owner['owner_id']);
        $this->assertNull(
            $this->pdo->query("SELECT owner_type FROM files WHERE id = {$unrelated}")->fetchColumn() ?: null
        );
        $this->assertSame(0, $this->repository->backfillFileOwnership());
    }

    private function file(string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES (?, ?, 'image/jpeg', 100)"
        )->execute(['core/member_photos/' . $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
