<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Maintenance\BackupIntegrity;
use Core\Maintenance\BackupIntegrityStatus;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\Task\VerifyBackupIntegrityHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The pass that re-reads stored backups, and what it costs.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class VerifyBackupIntegrityHandlerTest extends TestCase
{
    private \PDO $pdo;
    private BackupRepository $backups;
    private FileRepository $files;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->backups = new BackupRepository($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/verifypass_' . uniqid();
        @mkdir($this->storagePath . '/maintenance', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/maintenance/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->storagePath . '/maintenance');
        @rmdir($this->storagePath);
    }

    public function testAPassRecordsWhatItFound(): void
    {
        $intact = $this->completed('good.zip');
        $broken = $this->completed('bad.zip');
        file_put_contents($this->storagePath . '/maintenance/bad.zip', 'truncated');

        $this->runPass();

        $this->assertSame(BackupIntegrityStatus::Intact, $this->backups->findById($intact)?->integrityStatus);
        $this->assertSame(BackupIntegrityStatus::Corrupt, $this->backups->findById($broken)?->integrityStatus);
    }

    /**
     * A pass is bounded, so that an installation with more archives than
     * one night can hash does not get a pass that runs long and is killed
     * half way, reporting nothing at all.
     */
    public function testOnePassVerifiesOnlyItsBatch(): void
    {
        for ($i = 0; $i < VerifyBackupIntegrityHandler::BATCH_SIZE + 2; $i++) {
            $this->completed('archive' . $i . '.zip');
        }

        $this->runPass();

        $checked = array_filter(
            $this->backups->findAllNewestFirst(),
            static fn ($b): bool => $b->integrityStatus !== BackupIntegrityStatus::Unknown
        );
        $this->assertCount(VerifyBackupIntegrityHandler::BATCH_SIZE, $checked);
    }

    /**
     * And the next pass takes the ones the first did not, rather than
     * re-reading the same two for ever.
     */
    public function testSuccessivePassesGetRoundToEveryBackup(): void
    {
        $total = VerifyBackupIntegrityHandler::BATCH_SIZE + 2;
        for ($i = 0; $i < $total; $i++) {
            $this->completed('archive' . $i . '.zip');
        }

        for ($pass = 0; $pass < 3; $pass++) {
            $this->runPass();
        }

        foreach ($this->backups->findAllNewestFirst() as $backup) {
            $this->assertNotSame(
                BackupIntegrityStatus::Unknown,
                $backup->integrityStatus,
                'Backup ' . $backup->id . ' was never reached — the pass is not rotating.'
            );
        }
    }

    /** A finding is journaled; a quiet night is not. */
    public function testOnlyAFailureIsJournaled(): void
    {
        $this->completed('good.zip');
        $this->runPass();
        $this->assertSame([], $this->integrityEntries());

        $broken = $this->completed('bad.zip');
        file_put_contents($this->storagePath . '/maintenance/bad.zip', 'truncated');
        $this->runPass();

        $entries = $this->integrityEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $context = is_string($entries[0]['context'] ?? null)
            ? json_decode((string) $entries[0]['context'], true)
            : ($entries[0]['context'] ?? null);
        $this->assertSame($broken, is_array($context) ? ($context['backup_id'] ?? null) : null);
    }

    /** The chain re-arms itself, or it runs once and never again. */
    public function testThePassSchedulesItsSuccessor(): void
    {
        $this->runPass();

        $next = (new SchedulerRepository($this->pdo))->findByModuleAndKey(
            'core',
            'backup_integrity',
            VerifyBackupIntegrityHandler::REFERENCE
        );

        $this->assertNotNull($next);
        $this->assertSame('pending', $next['status']);
        $this->assertGreaterThan(time(), strtotime((string) $next['run_at']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function integrityEntries(): array
    {
        return array_values(array_filter(
            (new JournalRepository($this->pdo))->search(),
            static fn (array $e): bool => $e['event_type'] === 'backup_integrity_failed'
        ));
    }

    private function runPass(): void
    {
        (new VerifyBackupIntegrityHandler())->handle([], $this->context());
    }

    private function completed(string $archive): int
    {
        file_put_contents($this->storagePath . '/maintenance/' . $archive, $archive . ' contents');
        $fileId = $this->files->create(
            'maintenance/' . $archive,
            $archive,
            'application/zip',
            strlen($archive . ' contents'),
            'admin',
            null,
            null
        );
        $id = $this->backups->create('full_no_gallery', null);
        (new BackupIntegrity($this->backups, $this->files, $this->storagePath))->complete(
            $id,
            $fileId,
            $this->storagePath . '/maintenance/' . $archive,
            null,
            null
        );
        usleep(1000);

        return $id;
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }
}
