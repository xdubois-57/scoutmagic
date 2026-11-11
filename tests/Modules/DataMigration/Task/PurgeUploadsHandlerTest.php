<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\UploadStorage;
use Modules\DataMigration\Task\PurgeUploadsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The hourly purge, as the scheduler runs it: a file idle for more than
 * 24 hours goes, its run is abandoned, the journal says how many and
 * nothing else, and the chain re-arms itself.
 */
#[Group('database')]
final class PurgeUploadsHandlerTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private string $storage;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_purge_' . bin2hex(random_bytes(6));
        mkdir($this->storage . '/' . UploadStorage::DIR, 0700, true);
        $this->encryption = new EncryptionService(str_repeat('k', 32), str_repeat('b', 32));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/' . UploadStorage::DIR . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storage . '/' . UploadStorage::DIR);
        @rmdir($this->storage . '/temp');
        @rmdir($this->storage);
    }

    public function testAnIdleFileIsDeletedAndARecentOneKept(): void
    {
        $runs = new RunRepository($this->pdo, $this->encryption);
        $old = $this->runWithFile($runs, 'old.zip', (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'), Run::INTERRUPTED);
        $recent = $this->runWithFile($runs, 'recent.zip', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), Run::ANALYSED);

        (new PurgeUploadsHandler())->handle([], $this->taskContext());

        $this->assertFileDoesNotExist($this->storage . '/' . UploadStorage::DIR . '/old.zip');
        $this->assertFileExists($this->storage . '/' . UploadStorage::DIR . '/recent.zip');
        $this->assertSame(Run::ABANDONED, ($runs->find($old) ?? $this->fail('Run vanished.'))->state);
        $this->assertSame(Run::ANALYSED, ($runs->find($recent) ?? $this->fail('Run vanished.'))->state);

        $context = (string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'uploads_purged' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertSame(['files' => 1, 'orphans' => 0], json_decode($context, true));

        $this->assertNotNull(
            (new SchedulerService(new SchedulerRepository($this->pdo)))
                ->find(PurgeUploadsHandler::MODULE_ID, PurgeUploadsHandler::TASK_KEY, PurgeUploadsHandler::REFERENCE)
        );
    }

    private function runWithFile(RunRepository $runs, string $name, string $at, string $state): int
    {
        file_put_contents($this->storage . '/' . UploadStorage::DIR . '/' . $name, 'zip');

        return $runs->create('essai', ['state' => $state, 'file_path' => UploadStorage::DIR . '/' . $name], null, $at);
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            $this->storage
        );
    }
}
