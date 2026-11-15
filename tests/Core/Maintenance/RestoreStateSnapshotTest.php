<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\BackupRepository;
use Core\Maintenance\Restore\RestoreStateSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two ways today's state can fail to be kept across a restore, on a
 * real (SQLite) database: it cannot be read when it is held aside, or it
 * cannot be written back when it is put back.
 *
 * The round trip itself is `Tests\Core\Maintenance\Task\RestoreBackupRoundTripTest`.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RestoreStateSnapshotTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/restore_state_snapshot_' . uniqid();
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/temp/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/temp');
        @rmdir($this->storagePath);
    }

    /**
     * A journal that cannot be read means today's list cannot be kept, and
     * a restore that could not keep it must not go ahead: the refusal is a
     * sentence for the operator, and no half-written state file is left.
     */
    public function testAStateThatCannotBeReadIsRefusedAndLeavesNoFileBehind(): void
    {
        (new BackupRepository($this->pdo))->create('database', null);
        $this->pdo->exec('DROP TABLE event_log');

        try {
            RestoreStateSnapshot::holdAside($this->pdo, $this->storagePath);
            $this->fail('A state that could not be read was held aside.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'L\'état du site n\'a pas pu être mis de côté avant la restauration.',
                $e->getMessage()
            );
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        $this->assertSame([], glob($this->storagePath . '/temp/restore_state_*') ?: []);
    }

    /**
     * The put-back is one transaction: a row the database refuses (here a
     * trigger, as a failing write would) undoes the deletions already made,
     * so the restored list is still whole for the rollback that follows.
     */
    public function testAPutBackThatFailsUndoesWhatItHadAlreadyDeleted(): void
    {
        $backups = new BackupRepository($this->pdo);
        $backups->create('database', null);
        $path = RestoreStateSnapshot::holdAside($this->pdo, $this->storagePath);
        // The restored database: one backup of its own, which put-back
        // deletes before inserting today's.
        $this->pdo->exec('DELETE FROM backups');
        $restoredId = $backups->create('full_config', null);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_backup_rows BEFORE INSERT ON backups
             BEGIN SELECT RAISE(ABORT, 'backup row refused'); END"
        );

        try {
            RestoreStateSnapshot::putBack($this->pdo, $path, 0, false);
            $this->fail('A put-back the database refused was reported as done.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('backup row refused', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(
            [$restoredId],
            array_map('intval', $this->pdo->query('SELECT id FROM backups')->fetchAll(\PDO::FETCH_COLUMN))
        );
    }
}
