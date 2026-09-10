<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Alert\Check;

use Core\Alert\AlertThresholds;
use Core\Alert\Check\PortableBackupLingerCheck;
use Core\Maintenance\Backup;
use Core\Maintenance\BackupRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The alert whose subject is a backup that worked.
 *
 * Everything else on this installation reports a failure; this one reports
 * an archive holding the site's master key that nobody carried away.
 */
final class PortableBackupLingerCheckTest extends TestCase
{
    private \PDO $pdo;
    private BackupRepository $backups;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->backups = new BackupRepository($this->pdo);
    }

    private function completedBackup(string $type, string $completedAt): int
    {
        $id = $this->backups->create($type, null);
        $stmt = $this->pdo->prepare("UPDATE backups SET status = 'completed', completed_at = ? WHERE id = ?");
        $stmt->execute([$completedAt, $id]);

        return $id;
    }

    private function read(string $now = '2026-09-10 12:00:00'): \Core\Alert\AlertReading
    {
        return (new PortableBackupLingerCheck(
            $this->backups,
            new \DateTimeImmutable($now, new \DateTimeZone('UTC'))
        ))->read();
    }

    public function testNothingOnTheServerIsTheStateThisCheckWants(): void
    {
        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm, 'An empty server must re-arm the alert, not leave it standing.');
        $this->assertSame('aucune', $reading->value);
    }

    public function testAFreshPortableBackupDoesNotTriggerIt(): void
    {
        $this->completedBackup(Backup::PORTABLE_TYPE, '2026-09-10 09:00:00');

        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
    }

    public function testOneLeftForTheThresholdTriggersIt(): void
    {
        $this->completedBackup(
            Backup::PORTABLE_TYPE,
            '2026-09-' . str_pad((string) (10 - AlertThresholds::PORTABLE_LINGER_TRIGGER_DAYS), 2, '0', STR_PAD_LEFT)
            . ' 09:00:00'
        );

        $reading = $this->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertStringContainsString('clés de chiffrement', $reading->why);
    }

    /** A day short of the threshold is in the gap: neither triggered nor re-armed. */
    public function testTheDayBeforeIsNeither(): void
    {
        $this->completedBackup(Backup::PORTABLE_TYPE, '2026-09-05 09:00:00');

        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    /**
     * Another kind of backup, however old, says nothing here.
     *
     * `BackupAgeCheck` is the one that cares how old the newest backup is.
     * This check is only about the archive that carries the keys, and
     * counting an ordinary one would make it fire on every healthy site
     * with an old full backup lying around.
     */
    public function testAnOrdinaryBackupIsNotThisChecksBusiness(): void
    {
        $this->completedBackup('full_no_gallery', '2026-01-01 09:00:00');

        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertSame('aucune', $reading->value);
    }

    /**
     * **Taking a fresh portable backup must not silence the old one.**
     *
     * Retention keeps one, so two coexist only briefly — a purge skips a
     * backup an operation in flight depends on. But that is exactly when
     * the check reading the NEWEST would go quiet about an archive that
     * has been sitting in `storage/` for a month, which is the failure
     * mode worth pinning: the query asks for the oldest.
     */
    public function testANewOneDoesNotSilenceTheOldOneStillOnTheDisk(): void
    {
        $this->completedBackup(Backup::PORTABLE_TYPE, '2026-08-01 09:00:00');
        $this->completedBackup(Backup::PORTABLE_TYPE, '2026-09-10 09:00:00');

        $reading = $this->read();

        $this->assertTrue($reading->overTrigger, 'The month-old archive is still there and still holds the key.');
    }

    /** A pending row is not an archive anybody could carry away. */
    public function testAPendingPortableBackupIsNotCounted(): void
    {
        $this->backups->create(Backup::PORTABLE_TYPE, null);

        $this->assertFalse($this->read()->overTrigger);
        $this->assertSame('aucune', $this->read()->value);
    }
}
