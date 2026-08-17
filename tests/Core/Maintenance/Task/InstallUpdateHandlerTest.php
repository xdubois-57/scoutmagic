<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Task\InstallUpdateHandler;
use Core\Maintenance\UpdateException;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Mail\MailService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 *
 * The full success/rollback pipeline (real mysqldump/mysql/ZipArchive I/O)
 * is exercised by Core\Maintenance\BackupServiceTest, gated behind a real
 * TEST_DB_* server — InstallUpdateHandler only orchestrates calls into
 * BackupService, so these tests cover its own logic (status transitions,
 * no-op guards, notifications, temp-directory cleanup) using a connection
 * with no real database, which makes the safety-backup step itself fail
 * fast — the same trade-off already made in CreateBackupHandlerTest.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InstallUpdateHandlerTest extends TestCase
{
    private \PDO $pdo;
    private InstallUpdateHandler $handler;
    private UpdateHistoryRepository $updateHistoryRepository;
    private TaskContext $context;
    private string $storagePath;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->updateHistoryRepository = new UpdateHistoryRepository($this->pdo);
        $this->handler = new InstallUpdateHandler();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        $settingRepository = new SettingRepository($this->pdo);
        $settings = new SettingService($settingRepository);
        $settings->register('site_version', '0.0.0', 'text', 'Version', 'Version', null, null, null, false);

        $this->storagePath = sys_get_temp_dir() . '/install_update_handler_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        // Fake credentials — the safety backup step (mysqldump) fails fast
        // against these, which is exactly the branch these tests exercise
        // (see class docblock).
        $connection = Connection::withPdo($this->pdo);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            $journalService,
            $settings,
            $userAccountRepository,
            $this->storagePath,
            new NotificationService(
                new NotificationRepository($this->pdo, $encryption),
                new PushSubscriptionRepository($this->pdo, $encryption),
                new NotificationPreferenceRepository($this->pdo),
                $this->createMock(WebPush::class),
                $settings,
                $journalService,
                new SchedulerService(new SchedulerRepository($this->pdo)),
                $userAccountRepository
            )
        );
    }

    public function testHandleNoOpsWhenHistoryIdIsMissing(): void
    {
        $this->handler->handle(['download_url' => 'https://example.test/artifact.zip'], $this->context);
        $this->addToAssertionCount(1);
    }

    public function testHandleNoOpsWhenDownloadUrlIsMissing(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->handler->handle(['history_id' => $id], $this->context);

        $this->assertSame('pending', $this->updateHistoryRepository->findById($id)->status);
    }

    public function testHandleNoOpsWhenHistoryDoesNotExist(): void
    {
        $this->handler->handle(['history_id' => 999999, 'download_url' => 'https://example.test/artifact.zip'], $this->context);
        $this->addToAssertionCount(1);
    }

    public function testHandleNoOpsWhenHistoryIsAlreadyNotPending(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->updateHistoryRepository->markCompleted($id);

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $this->assertSame('completed', $this->updateHistoryRepository->findById($id)->status);
    }

    public function testHandleMarksFailedAndNotifiesWhenTheSafetyBackupFails(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $history = $this->updateHistoryRepository->findById($id);
        $this->assertSame('failed', $history->status);
        $this->assertNotNull($history->errorMessage);
        $this->assertNull($history->backupId);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $notifications = (new NotificationRepository($this->pdo, $encryption))->findByUserAccountId($this->userId);
        $this->assertCount(1, $notifications);
        $this->assertSame('Échec de la mise à jour', $notifications[0]->title);
    }

    /**
     * A row left stuck in a non-terminal status by a crashed/superseded
     * attempt must be cleaned up the moment a NEW update genuinely starts
     * — even though this test's own attempt then immediately fails too
     * (fake DB creds, see class docblock), that failure happens strictly
     * AFTER the stuck sibling row is already marked failed, so this
     * assertion holds regardless.
     */
    public function testHandleMarksAnyOtherStuckUpdateAsFailedBeforeStartingItsOwnWork(): void
    {
        $stuckId = $this->updateHistoryRepository->create('1.0.0', 'dev-stuck', false, null);
        $this->updateHistoryRepository->setStatus($stuckId, 'downloading');

        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $this->assertSame('failed', $this->updateHistoryRepository->findById($stuckId)->status);
    }

    public function testHandleRemovesLeftoverTempDirectoryEvenOnFailure(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $tempDir = $this->storagePath . '/temp/update_' . $id;
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/stray.txt', 'leftover from a previous crashed run');

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    public function testHandleAcceptsABranchSourceTypeInThePayloadWithoutError(): void
    {
        // Full success path can't run here (see class docblock — the
        // safety-backup step fails fast against fake DB credentials before
        // ever reaching the branch-specific install logic) — this only
        // confirms the new field doesn't break the existing early pipeline.
        $id = $this->updateHistoryRepository->create('1.0.0', 'dev-a1b2c3d', false, $this->userId);

        $this->handler->handle(
            ['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip', 'source_type' => 'branch'],
            $this->context
        );

        $this->assertSame('failed', $this->updateHistoryRepository->findById($id)->status);
    }

    /**
     * The actual behavior guaranteed by source_type "branch" — resolving
     * GitHub's single wrapping "{owner}-{repo}-{sha}/" directory before
     * installFiles() runs — is pure filesystem logic with no DB/network
     * dependency, so it's tested directly here rather than through the
     * full handle() pipeline (which this test class's own fake-DB-creds
     * constraint can't reach past the safety-backup step — see above).
     */
    private function invokeResolveBranchArchiveRoot(string $extractedDir): string
    {
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'resolveBranchArchiveRoot');
        $method->setAccessible(true);

        return $method->invoke($this->handler, $extractedDir);
    }

    public function testResolveBranchArchiveRootDescendsIntoTheSingleWrappingDirectory(): void
    {
        $extractedDir = $this->storagePath . '/extracted_wrapped';
        $wrapped = $extractedDir . '/owner-repo-a1b2c3d';
        mkdir($wrapped, 0755, true);
        file_put_contents($wrapped . '/composer.json', '{}');

        $root = $this->invokeResolveBranchArchiveRoot($extractedDir);

        $this->assertSame($wrapped, $root);
    }

    public function testResolveBranchArchiveRootLeavesAFlatArchiveUnchanged(): void
    {
        $extractedDir = $this->storagePath . '/extracted_flat';
        mkdir($extractedDir, 0755, true);
        mkdir($extractedDir . '/core', 0755, true);
        mkdir($extractedDir . '/modules', 0755, true);
        file_put_contents($extractedDir . '/composer.json', '{}');

        $root = $this->invokeResolveBranchArchiveRoot($extractedDir);

        $this->assertSame($extractedDir, $root);
    }

    public function testResolveBranchArchiveRootLeavesASingleTopLevelFileUnchanged(): void
    {
        // A single top-level entry that is a FILE (not a directory) must
        // never be treated as a wrapping directory.
        $extractedDir = $this->storagePath . '/extracted_single_file';
        mkdir($extractedDir, 0755, true);
        file_put_contents($extractedDir . '/README.md', 'hello');

        $root = $this->invokeResolveBranchArchiveRoot($extractedDir);

        $this->assertSame($extractedDir, $root);
    }

    /**
     * Same "pure filesystem logic, tested directly via reflection" pattern
     * as resolveBranchArchiveRoot() above — the full pipeline can't reach
     * this step either (fake DB creds fail the safety-backup step first).
     */
    private function invokeClearCompiledTemplateCache(string $storagePath): void
    {
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'clearCompiledTemplateCache');
        $method->setAccessible(true);

        $method->invoke($this->handler, $storagePath);
    }

    public function testClearCompiledTemplateCacheRemovesTheTwigCacheDirectory(): void
    {
        $cacheDir = $this->storagePath . '/temp/twig_cache';
        mkdir($cacheDir, 0755, true);
        file_put_contents($cacheDir . '/abcdef1234.php', '<?php // pre-update compiled template');

        $this->invokeClearCompiledTemplateCache($this->storagePath);

        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    public function testClearCompiledTemplateCacheWipesEveryVersionSubdirectoryUnconditionally(): void
    {
        // Core\View\TwigFactory namespaces its cache by installed version
        // (storage/temp/twig_cache/{version}) — a stable release directory
        // and a development-mode "dev-{sha}" one can legitimately coexist
        // (e.g. someone switched auto_update_level back and forth). The
        // clear must not be scoped to "whichever version we're installing
        // right now": it sweeps the whole tree, so nothing accumulates and
        // a re-install of the exact same version is never served leftover
        // compiled output from a previous, possibly-interrupted attempt.
        $cacheRoot = $this->storagePath . '/temp/twig_cache';
        mkdir($cacheRoot . '/1.0.22', 0755, true);
        mkdir($cacheRoot . '/dev-a1b2c3d', 0755, true);
        file_put_contents($cacheRoot . '/1.0.22/abcdef1234.php', '<?php // stable release, compiled');
        file_put_contents($cacheRoot . '/dev-a1b2c3d/1234abcdef.php', '<?php // dev build, compiled');

        $this->invokeClearCompiledTemplateCache($this->storagePath);

        $this->assertDirectoryDoesNotExist($cacheRoot);
    }

    public function testClearCompiledTemplateCacheHasNoSourceTypeOrVersionParameter(): void
    {
        // Structural guarantee behind "even for a development commit
        // install": the method has no way to special-case source_type
        // ('release' vs 'branch') or compare versions, because it isn't
        // given either — handle() calls it identically on both paths.
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'clearCompiledTemplateCache');

        $this->assertCount(1, $method->getParameters());
        $this->assertSame('storagePath', $method->getParameters()[0]->getName());
    }

    public function testClearCompiledTemplateCacheIsANoOpWhenTheCacheDirDoesNotExistYet(): void
    {
        // A fresh install (or a storage/temp already cleared) has no
        // twig_cache directory at all — must not throw.
        $this->invokeClearCompiledTemplateCache($this->storagePath);
        $this->addToAssertionCount(1);
    }

    /**
     * Same reflection pattern as the two groups above — installFiles() and
     * its copyRecursive() are pure filesystem logic the full pipeline
     * can't reach here (the safety-backup step fails first on fake DB
     * credentials).
     */
    private function invokeInstallFiles(string $sourceDir, string $destDir): void
    {
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'installFiles');
        $method->setAccessible(true);

        $method->invoke($this->handler, $sourceDir, $destDir);
    }

    public function testInstallFilesCopiesTheWholeTreeExceptStorageAndVersion(): void
    {
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core/View', 0755, true);
        mkdir($source . '/storage', 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/core/View/TwigFactory.php', '<?php // new');
        file_put_contents($source . '/VERSION', '9.9.9');
        file_put_contents($source . '/storage/keep.txt', 'live data');

        $this->invokeInstallFiles($source, $dest);

        $this->assertSame('<?php // new', file_get_contents($dest . '/core/View/TwigFactory.php'));
        $this->assertFileDoesNotExist($dest . '/VERSION', 'VERSION is written separately, once the rest succeeded');
        $this->assertFileDoesNotExist($dest . '/storage/keep.txt', 'storage/ holds live data an update never touches');
    }

    public function testInstallFilesThrowsWhenAFileCannotBeWritten(): void
    {
        // The regression this guards: copy()'s return value used to be
        // discarded, so one unwritable file was skipped silently, the
        // install "succeeded", nothing rolled back, and VERSION was
        // written over a half-updated tree — which took the Maintenance
        // page down with "Unknown 'markdown' filter" (new template
        // installed, previous TwigFactory left behind).
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core', 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/core/TwigFactory.php', '<?php // new');
        // Destination parent is a regular file, so copy() into it fails —
        // a failure mode that holds even when the test runs as root, which
        // a chmod-based one would not.
        file_put_contents($dest . '/core', 'not a directory');

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('partiellement appliquée');

        $this->invokeInstallFiles($source, $dest);
    }

    public function testInstallFilesStopsAtTheFirstFailureInsteadOfContinuingSilently(): void
    {
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source, 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/a.php', '<?php // a');
        mkdir($source . '/b', 0755, true);
        file_put_contents($source . '/b/inner.php', '<?php // inner');
        // Blocks the b/ directory from being created at the destination.
        file_put_contents($dest . '/b', 'not a directory');

        try {
            $this->invokeInstallFiles($source, $dest);
            $this->fail('installFiles() should have thrown on the unwritable directory');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('partiellement appliquée', $e->getMessage());
        }

        // The throw is what hands control to rollbackToSafetyBackup(); the
        // point is that it happens at all, not that nothing was copied
        // before it (scandir order decides that).
        $this->assertFileDoesNotExist($dest . '/b/inner.php');
    }
}
