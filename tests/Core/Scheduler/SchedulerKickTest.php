<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerKick;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The kick is what the two file-replacing handlers use to get the schema
 * migration started in a process of their own, immediately, having
 * deliberately not migrated in the process that replaced the files.
 *
 * Everything about it is opportunistic. By the time it runs, the update
 * or restore has already succeeded and the migration is already queued —
 * so every way of failing to send the request must leave the operation
 * untouched. A failed kick is a slower migration, never a failed update,
 * and these tests are that promise.
 */
class SchedulerKickTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/scheduler_kick_test_' . uniqid();
        mkdir($this->storagePath . '/keys', 0755, true);
        mkdir($this->storagePath . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['keys/master.key', 'config/secrets.enc'] as $file) {
            @unlink($this->storagePath . '/' . $file);
        }
        foreach (['keys', 'config'] as $dir) {
            @rmdir($this->storagePath . '/' . $dir);
        }
        @rmdir($this->storagePath);
    }

    private function context(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );
    }

    /**
     * No secrets.enc at all — the shape a host takes when secrets could
     * not be written, and one of several ways there is nowhere to send
     * the request. It reports that it sent nothing; it does not throw.
     */
    public function testAnInstallationWithNoSecretsReportsRatherThanThrows(): void
    {
        $this->assertFalse(SchedulerKick::now($this->context()));
    }

    /**
     * The same for a database that answers nothing useful: this runs
     * moments after a file tree was replaced under a live process, which
     * is not the moment to discover a new way to throw.
     */
    public function testAnUnusableConnectionIsStillNotAThrow(): void
    {
        $broken = new TaskContext(
            new Connection('invalid.invalid.host', 3306, 'nope', 'nope', 'nope'),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );

        $this->assertFalse(SchedulerKick::now($broken));
    }
}
