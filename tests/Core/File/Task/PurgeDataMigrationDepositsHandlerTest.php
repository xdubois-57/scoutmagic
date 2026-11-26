<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\File\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\DataMigrationDeposits;
use Core\File\Task\PurgeDataMigrationDepositsHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The core's hourly purge of the data migration deposits (issue #1259), as
 * the scheduler runs it: it is a core handler, so it exists whatever
 * modules are enabled; what is a day old goes; the journal gets a count and
 * nothing else; and the chain re-arms itself.
 */
#[Group('database')]
final class PurgeDataMigrationDepositsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storage = sys_get_temp_dir() . '/sm_dm_purge_' . bin2hex(random_bytes(6));
        mkdir($this->storage . '/' . DataMigrationDeposits::DIR, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testItIsACoreHandlerSoNoModuleDecidesWhetherItRuns(): void
    {
        $this->assertSame(
            PurgeDataMigrationDepositsHandler::class,
            CoreTaskHandlers::all()[PurgeDataMigrationDepositsHandler::TASK_KEY] ?? null
        );
    }

    public function testWhatIsADayOldGoesAndTheJournalCountsIt(): void
    {
        $directory = $this->storage . '/' . DataMigrationDeposits::DIR;
        file_put_contents($directory . '/run-old.zip', 'zip');
        touch($directory . '/run-old.zip', time() - DataMigrationDeposits::RETENTION_SECONDS - 60);
        file_put_contents($directory . '/run-new.zip', 'zip');

        (new PurgeDataMigrationDepositsHandler())->handle([], $this->taskContext());

        $this->assertFileDoesNotExist($directory . '/run-old.zip');
        $this->assertFileExists($directory . '/run-new.zip');
        $context = (string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'data_migration_deposits_purged' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertSame(['entries' => 1], json_decode($context, true));
        $this->assertNotNull($this->scheduled());
    }

    public function testNothingToDeleteWritesNothingAndStillReArms(): void
    {
        (new PurgeDataMigrationDepositsHandler())->handle([], $this->taskContext());

        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'data_migration_deposits_purged'"
            )->fetchColumn()
        );
        $this->assertNotNull($this->scheduled());
    }

    /** @return array<string, mixed>|null */
    private function scheduled(): ?array
    {
        return (new SchedulerService(new SchedulerRepository($this->pdo)))->find(
            'core',
            PurgeDataMigrationDepositsHandler::TASK_KEY,
            PurgeDataMigrationDepositsHandler::REFERENCE
        );
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('k', 32), str_repeat('b', 32)),
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            $this->storage
        );
    }
}
