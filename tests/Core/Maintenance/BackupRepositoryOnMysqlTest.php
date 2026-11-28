<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\BackupRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A failed backup's sentence fits `backups.error_message` on the engine
 * that refuses half a character (issue #1438). A byte cut of 'a' then
 * « é » lands in the middle of one, MariaDB refused it (error 1366), and
 * the backup stayed « en cours » for ever with no reason shown.
 */
#[Group('database')]
final class BackupRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testALongAccentedFailureIsCutByCharactersAndTheBackupIsMarkedFailed(): void
    {
        $pdo = $this->productionEngine();
        $repository = new BackupRepository($pdo);
        $id = $repository->create('database', null);

        $repository->markFailed($id, 'a' . str_repeat('é', 700) . "\xEE");

        $row = $pdo->query("SELECT status, error_message FROM backups WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertSame(BackupRepository::ERROR_MESSAGE_MAX, mb_strlen((string) $row['error_message'], 'UTF-8'));
        self::assertTrue(mb_check_encoding((string) $row['error_message'], 'UTF-8'));
    }
}
