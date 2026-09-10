<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\BackupSafetyNet;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Deleting the net an operation is about to fall into is refused.
 *
 * The `auto_update` backup an install takes is the ONLY thing its
 * automatic rollback can start from, and somebody reading a list of dates
 * has no way of telling which line that is. So the refusal is the
 * server's, it names a reason, and it lifts by itself once the operation
 * finishes.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BackupSafetyNetTest extends TestCase
{
    private \PDO $pdo;
    private BackupSafetyNet $net;
    private SchedulerService $scheduler;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->net = new BackupSafetyNet($this->scheduler, new UpdateHistoryRepository($this->pdo));
    }

    public function testABackupNothingIsUsingCanGo(): void
    {
        $this->assertNull($this->net->reasonToKeep(42));
    }

    public function testAQueuedTaskThatNamesTheBackupRefusesIt(): void
    {
        $this->queue(['backup_id' => 42]);

        $reason = $this->net->reasonToKeep(42);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('core/restore_backup', $reason);
    }

    /**
     * The second key, and the one the roadmap names: a restore takes its
     * own safety copy before overwriting anything, and that copy is what
     * it falls back to.
     */
    public function testTheSafetyCopyOfARunningRestoreIsRefusedToo(): void
    {
        $this->queue(['safety_backup_id' => 7]);

        $this->assertNotNull($this->net->reasonToKeep(7));
    }

    /** A task naming a different backup says nothing about this one. */
    public function testATaskAboutAnotherBackupDoesNotRefuse(): void
    {
        $this->queue(['backup_id' => 42]);

        $this->assertNull($this->net->reasonToKeep(43));
    }

    /**
     * An install records its net in `update_history`, not only in a task
     * payload — the task is gone long before the install is.
     */
    public function testAnInstallStillRunningRefusesItsOwnBackup(): void
    {
        $updates = new UpdateHistoryRepository($this->pdo);
        $id = $updates->create('1.0.0', '1.1.0', false, null);
        $updates->setBackupId($id, 99);
        $updates->setStatus($id, 'installing');

        $reason = $this->net->reasonToKeep(99);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('mise à jour en cours', $reason);
    }

    /**
     * And it lifts by itself. A rolled-back update has already used its
     * net or never will, so holding the backup for ever would be keeping
     * a copy nothing can consume.
     */
    public function testAFinishedUpdateReleasesItsBackup(): void
    {
        $updates = new UpdateHistoryRepository($this->pdo);
        $id = $updates->create('1.0.0', '1.1.0', false, null);
        $updates->setBackupId($id, 99);
        $updates->markCompleted($id);

        $this->assertNull($this->net->reasonToKeep(99));
    }

    /**
     * Asking whether a delete button may be pressed must not change the
     * state of an update while answering — which is why this reads the
     * table directly instead of going through findInProgress(), whose
     * stale-row sweep has a side effect.
     */
    public function testAskingDoesNotMarkAStalledUpdateFailed(): void
    {
        $updates = new UpdateHistoryRepository($this->pdo);
        $id = $updates->create('1.0.0', '1.1.0', false, null);
        $updates->setBackupId($id, 99);
        $updates->setStatus($id, 'installing');
        $this->pdo->exec(
            "UPDATE update_history SET started_at = '2020-01-01 00:00:00', progress_at = '2020-01-01 00:00:00' "
            . 'WHERE id = ' . $id
        );

        $this->net->reasonToKeep(99);

        $this->assertSame('installing', $updates->findById($id)?->status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queue(array $payload): void
    {
        $this->scheduler->schedule(
            'core',
            'restore_backup',
            new \DateTimeImmutable('+1 hour'),
            $payload,
            'restore'
        );
    }
}
