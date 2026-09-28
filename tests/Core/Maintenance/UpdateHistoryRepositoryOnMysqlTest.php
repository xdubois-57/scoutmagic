<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\UpdateHistoryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The two compare-and-set transitions of #689 on the engine production runs.
 *
 * `UpdateHistoryRepository::claimPendingForBackup()` and
 * `markSkippedIfPending()` both answer from `rowCount()` after an
 * `UPDATE … WHERE id = ? AND status = 'pending'`, which AGENTS.md § Database
 * names outright: SQLite reports the rows MATCHED, MySQL and MariaDB the rows
 * CHANGED, and this application does not set `PDO::MYSQL_ATTR_FOUND_ROWS`.
 * `UpdateHistoryRepositoryTest` runs on SQLite like most of the suite, so it
 * cannot see that difference at all.
 *
 * What rides on it is which of two concurrent writers wins: an install
 * claiming the row it is about to replace files from, and a newer push
 * marking that row « Ignorée ». Both must be able to lose, and the loser must
 * be told — a `true` returned to both would let an install replace files
 * while the history says « Ignorée », with `findInProgress()` blind to it and
 * `MaintenanceGate` no longer holding visitors back.
 *
 * Raised in review of #691, which had these methods on SQLite only.
 */
#[Group('database')]
class UpdateHistoryRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private UpdateHistoryRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new UpdateHistoryRepository($this->pdo);
    }

    /**
     * **First, the premise.** Everything below reads `rowCount()` as « my
     * write is the one that happened », which only holds on an engine that
     * reports rows CHANGED rather than rows MATCHED. Asserted here so that
     * the day a connection attribute or an engine upgrade changes it, this
     * test says so instead of passing for free — the shape AGENTS.md
     * § Database requires of an `…OnMysqlTest`.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedRows(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, null);

        $stmt = $this->pdo->prepare('UPDATE update_history SET status = ? WHERE id = ?');
        $stmt->execute(['pending', $id]);

        $this->assertSame(
            0,
            $stmt->rowCount(),
            'this engine reports matched rows, so a compare-and-set cannot be decided from rowCount() here'
        );
    }

    public function testTheBackupClaimIsWonOnceAndRefusedAfterwards(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, null);

        $this->assertTrue($this->repository->claimPendingForBackup($id));
        $this->assertSame('backing_up', $this->repository->findById($id)->status);

        // The second caller is the concurrent one, and it must lose.
        $this->assertFalse(
            $this->repository->claimPendingForBackup($id),
            'two installs could both believe they had claimed the row'
        );
    }

    public function testTheSkipIsWonOnceAndRefusedAfterwards(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, null);

        $this->assertTrue($this->repository->markSkippedIfPending($id, 'Remplacée.'));
        $this->assertSame('skipped', $this->repository->findById($id)->status);

        $this->assertFalse(
            $this->repository->markSkippedIfPending($id, 'Remplacée.'),
            'a second supersede reported success against a row it had not changed'
        );
    }

    /**
     * The collision the whole change exists for, in the order that matters:
     * the install claims, then the push tries to skip. The claim stands, the
     * skip is refused, and the row keeps saying an install is under way — so
     * `MaintenanceGate` keeps holding visitors back while files are replaced.
     */
    public function testAnInstallThatWonItsClaimIsNotSkippedFromUnderIt(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, null);

        $this->assertTrue($this->repository->claimPendingForBackup($id));
        $this->assertFalse($this->repository->markSkippedIfPending($id, 'Remplacée.'));

        $this->assertSame(
            'backing_up',
            $this->repository->findById($id)->status,
            'a running install was marked « Ignorée » while it was replacing files'
        );
    }

    /** And the other order: the push wins first, so the install stands down. */
    public function testASkippedRowCannotThenBeClaimed(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, null);

        $this->assertTrue($this->repository->markSkippedIfPending($id, 'Remplacée.'));
        $this->assertFalse($this->repository->claimPendingForBackup($id));

        $this->assertSame('skipped', $this->repository->findById($id)->status);
    }
}
