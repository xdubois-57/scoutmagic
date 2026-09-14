<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Protection\StorageProtection;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\Protection\Task\RunStorageProtectionsHandler;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The nightly chain: what it works, what it writes down, and how it
 * re-arms.
 *
 * A scheduled task that copies and deletes on its own must not arrive with
 * no test at all — `Tests\Architecture\ScheduledTasksAreTestedTest` says so
 * for modules, and a core handler that purges files is no different.
 */
final class RunStorageProtectionsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private TaskContext $context;
    private StorageLocationRepository $locations;
    private StorageProtectionRepository $protections;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storagePath = sys_get_temp_dir() . '/protection_handler_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        $this->locations = new StorageLocationRepository($this->pdo, $encryption);
        $this->protections = new StorageProtectionRepository($this->pdo);

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    private function declareLocal(string $label, string $path): int
    {
        return $this->locations->create(
            StorageLocationType::Local,
            $label,
            new LocalLocationConfig($path),
            null
        );
    }

    private function chainRows(): array
    {
        return $this->pdo->query(
            "SELECT * FROM scheduled_actions WHERE task_key = '" . RunStorageProtectionsHandler::TASK_KEY . "'"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testARelationThatIsDueIsWorkedAndItsPassRecordedAsCompleted(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        mkdir($this->storagePath . '/gallery/12', 0755, true);
        file_put_contents($this->storagePath . '/gallery/12/a.jpg', 'aaa');
        $this->protections->save($source, $destination, 30, 24, true);

        (new RunStorageProtectionsHandler())->handle([], $this->context);

        $this->assertFileExists($this->storagePath . '/nas/12/a.jpg', 'the file was not carried to the copy');
        $protection = $this->protections->findBySourceId($source);
        $this->assertNotNull($protection);
        $this->assertNotNull($protection->lastCompletedPassAt);
        $this->assertFalse($protection->isPassInProgress());
    }

    /**
     * **Counts, never keys.** A journal entry is carried by a support
     * package that leaves the installation, and an object key is a path —
     * which on a local location is a path somebody chose and may carry a
     * person's name.
     */
    public function testTheJournalSaysHowMuchMovedAndNamesNoFile(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        mkdir($this->storagePath . '/gallery/12', 0755, true);
        file_put_contents($this->storagePath . '/gallery/12/marie-dupont.jpg', 'aaa');
        $this->protections->save($source, $destination, 30, 24, true);

        (new RunStorageProtectionsHandler())->handle([], $this->context);

        $rows = $this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotSame([], $rows);
        $entry = (string) $rows[0]['description'];
        $this->assertStringContainsString('1 fichier(s) copié(s)', $entry);
        $this->assertStringNotContainsString('marie-dupont', $entry, 'a file name reached the journal');
    }

    /** A relation whose cadence has not elapsed is left alone. */
    public function testARelationThatIsNotDueIsSkipped(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        mkdir($this->storagePath . '/gallery/12', 0755, true);
        file_put_contents($this->storagePath . '/gallery/12/a.jpg', 'aaa');
        $id = $this->protections->save($source, $destination, 30, 24, true);
        $this->protections->recordPassCompleted($id);

        (new RunStorageProtectionsHandler())->handle([], $this->context);

        $this->assertFileDoesNotExist($this->storagePath . '/nas/12/a.jpg');
    }

    /** A disabled relation is never worked, however overdue it looks. */
    public function testADisabledRelationIsNeverWorked(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        mkdir($this->storagePath . '/gallery/12', 0755, true);
        file_put_contents($this->storagePath . '/gallery/12/a.jpg', 'aaa');
        $this->protections->save($source, $destination, 30, 24, false);

        (new RunStorageProtectionsHandler())->handle([], $this->context);

        $this->assertFileDoesNotExist($this->storagePath . '/nas/12/a.jpg');
    }

    /**
     * **The chain keeps ticking with nothing declared.** Arming it only
     * when a protection exists would mean it never starts on the request
     * that declares the first one.
     */
    public function testTheChainReArmsEvenWithNoProtectionDeclaredAtAll(): void
    {
        (new RunStorageProtectionsHandler())->handle([], $this->context);

        $this->assertCount(1, $this->chainRows(), 'exactly one chain, never a second row per run');
    }

    /** Two runs leave one chain, not two — `rearmAfter()` is the guarded twin. */
    public function testTwoRunsLeaveOneChainRatherThanTwo(): void
    {
        $handler = new RunStorageProtectionsHandler();
        $handler->handle([], $this->context);
        $handler->handle([], $this->context);

        $this->assertCount(1, $this->chainRows());
    }

    /**
     * A pass that threw clears the working state, and D15 is why: a run
     * that died mid-listing holds a cursor into a listing it can no
     * longer trust, and resuming from it would carry that blindness into
     * the next run.
     */
    public function testAPassThatThrewClearsTheWorkingStateAndJournalsIt(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        $id = $this->protections->save($source, $destination, 30, 24, true);
        $this->protections->recordPassProgress($id, StorageProtection::PHASE_INVENTORY, '12/a.jpg', 17);

        $throwing = new class (
            new \Core\Storage\Location\Protection\StorageInventoryStore(),
            new \Core\Storage\Location\Protection\ProtectedCopier()
        ) extends \Core\Storage\Location\Protection\ProtectionPass {
            public function run(
                StorageProtection $protection,
                \Core\Storage\Location\Backend\StorageBackendInterface $source,
                \Core\Storage\Location\Backend\StorageBackendInterface $destination,
                string $sourceLabel,
                callable $hasTimeLeft
            ): \Core\Storage\Location\Protection\ProtectionPassResult {
                throw new \RuntimeException('la source a cessé de répondre');
            }
        };

        (new RunStorageProtectionsHandler($throwing))->handle([], $this->context);

        $protection = $this->protections->findBySourceId($source);
        $this->assertNotNull($protection);
        $this->assertFalse(
            $protection->isPassInProgress(),
            'a cursor into an untrustworthy listing must not survive the failure'
        );
        $this->assertNotNull($protection->lastError);

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'storage_protection_failed'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
