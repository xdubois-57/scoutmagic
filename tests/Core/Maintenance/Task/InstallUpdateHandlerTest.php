<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupService;
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
use Core\Security\RoleResolver;
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
        // update_history.error_message reaches a title="" tooltip on
        // Configuration > Maintenance, so what a caught \Throwable happened
        // to say never lands there verbatim — see
        // Core\Exception\UserFacingMessage.
        $this->assertStringNotContainsString('SQLSTATE', (string) $history->errorMessage);
        $this->assertStringNotContainsString('/', (string) $history->errorMessage);

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

    // ── Waiting for an artifact CI has not published yet ────────────────

    /** The URL Core\Maintenance\GitHubWebhookService builds for a push. */
    private const DEV_ARTIFACT_URL =
        'https://github.com/owner/repo/releases/download/dev-build/scoutmagic-dev-a1b2c3d.zip';

    /**
     * A handler whose artifact probe answers $status without touching the
     * network. probeArtifactStatus() is `protected` for exactly this — the
     * decision under test is what the handler does with the answer, and
     * the redirect walk that produces it is covered separately above.
     */
    private function handlerProbing(?int $status): InstallUpdateHandler
    {
        return new class ($status) extends InstallUpdateHandler {
            public function __construct(private ?int $probeStatus)
            {
            }

            protected function probeArtifactStatus(string $url): ?int
            {
                return $this->probeStatus;
            }
        };
    }

    /**
     * The development channel schedules its install from the push webhook,
     * which fires the instant the commit lands — one to three minutes
     * before CI has finished building and uploading the artifact. The
     * first attempt therefore finds nothing, and must cost nothing: no
     * backup, no status change, just this same task queued again.
     */
    public function testAnArtifactNotPublishedYetReschedulesTheSameTaskAndChangesNothing(): void
    {
        $id = $this->updateHistoryRepository->create('dev-0000000', 'dev-a1b2c3d', false, null);
        $payload = [
            'history_id' => $id,
            'download_url' => self::DEV_ARTIFACT_URL,
            'source_type' => 'release',
            'wait_for_artifact_until' => time() + 600,
            'reference' => 'push_install',
        ];

        $this->handlerProbing(404)->handle($payload, $this->context);

        $history = $this->updateHistoryRepository->findById($id);
        // Still 'pending', and that is safe: Maintenance\AbandonedInstallSweeper
        // only closes a pending row that NO queued scheduled action points
        // at, and the retry below points at this one.
        $this->assertSame('pending', $history->status);
        $this->assertNull($history->backupId, 'nothing may be backed up while merely waiting');

        $queued = (new SchedulerRepository($this->pdo))->findByModuleAndTaskKey('core', 'install_update', 10);
        $this->assertCount(1, $queued);
        $retried = json_decode((string) $queued[0]['payload'], true);
        $this->assertSame($payload['download_url'], $retried['download_url']);
        $this->assertSame($payload['wait_for_artifact_until'], $retried['wait_for_artifact_until']);
        $this->assertSame('release', $retried['source_type']);
        // Same reference, so a newer push still supersedes this attempt
        // (GitHubWebhookService::supersedeQueuedInstall() finds the queued
        // task by reference) instead of leaving two installs due at once.
        $this->assertSame('push_install', $queued[0]['reference']);
    }

    /**
     * Past the deadline the build is not late, it is broken — and a broken
     * build has to be visible. There is deliberately NO fallback to
     * GitHub's zipball of the commit: that archive carries no vendor/, and
     * installing it is exactly the silent dependency drift this channel
     * was rebuilt to remove.
     */
    public function testAnArtifactThatNeverAppearedFailsTheUpdateInsteadOfWaitingForever(): void
    {
        $id = $this->updateHistoryRepository->create('dev-0000000', 'dev-a1b2c3d', false, null);

        $this->handlerProbing(404)->handle([
            'history_id' => $id,
            'download_url' => self::DEV_ARTIFACT_URL,
            'source_type' => 'release',
            'wait_for_artifact_until' => time() - 1,
            'reference' => 'push_install',
        ], $this->context);

        $history = $this->updateHistoryRepository->findById($id);
        $this->assertSame('failed', $history->status);
        $this->assertNull($history->backupId, 'the update stopped before touching anything');

        // Names what is missing and where, in French, without the URL, a
        // filesystem path or any library text — the message is rendered as
        // a title="" tooltip on Configuration > Maintenance.
        $message = (string) $history->errorMessage;
        $this->assertStringContainsString('dev-build', $message);
        $this->assertStringContainsString('scoutmagic-dev-a1b2c3d.zip', $message);
        $this->assertStringNotContainsString('https://', $message);

        // Nothing left queued: the wait is over, not paused.
        $this->assertCount(0, (new SchedulerRepository($this->pdo))->findByModuleAndTaskKey('core', 'install_update', 10));

        // The detail an operator needs to find the failed build IS
        // journaled, unlike the tooltip.
        $row = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'update_failed' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame(self::DEV_ARTIFACT_URL, $context['download_url']);
        $this->assertSame(404, $context['http_status']);
    }

    /**
     * A probe that fails for a transient reason — rate limit, refused
     * connection (null), a 5xx — is "not there yet", never fatal: only the
     * deadline ends the wait. Otherwise one GitHub hiccup would fail an
     * update whose artifact was sitting there all along.
     *
     * @return array<string, array{0: int|null}>
     */
    public static function transientProbeFailures(): array
    {
        return [
            'no response at all' => [null],
            'rate limited' => [403],
            'GitHub having a moment' => [503],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transientProbeFailures')]
    public function testATransientProbeFailureIsTreatedAsAbsentAndRetried(?int $status): void
    {
        $id = $this->updateHistoryRepository->create('dev-0000000', 'dev-a1b2c3d', false, null);

        $this->handlerProbing($status)->handle([
            'history_id' => $id,
            'download_url' => self::DEV_ARTIFACT_URL,
            'source_type' => 'release',
            'wait_for_artifact_until' => time() + 600,
            'reference' => 'push_install',
        ], $this->context);

        $this->assertSame('pending', $this->updateHistoryRepository->findById($id)->status);
        $this->assertCount(1, (new SchedulerRepository($this->pdo))->findByModuleAndTaskKey('core', 'install_update', 10));
    }

    /**
     * The artifact is there — the wait is over and the normal pipeline
     * runs. It still fails here, at the safety-backup step, because this
     * class deliberately runs against fake database credentials (see the
     * class docblock); reaching THAT failure is the proof it got past the
     * wait, since a still-waiting install never leaves 'pending'.
     */
    public function testAPublishedArtifactLetsTheInstallProceed(): void
    {
        $id = $this->updateHistoryRepository->create('dev-0000000', 'dev-a1b2c3d', false, null);

        $this->handlerProbing(200)->handle([
            'history_id' => $id,
            'download_url' => self::DEV_ARTIFACT_URL,
            'source_type' => 'release',
            'wait_for_artifact_until' => time() + 600,
            'reference' => 'push_install',
        ], $this->context);

        $history = $this->updateHistoryRepository->findById($id);
        $this->assertSame('failed', $history->status);
        // It failed on the DATABASE dump, i.e. inside the safety-backup
        // step — not on the artifact, which is the point.
        $this->assertStringContainsString('base de données', (string) $history->errorMessage);
        $this->assertStringNotContainsString('jamais été publiée', (string) $history->errorMessage);
        $this->assertCount(0, (new SchedulerRepository($this->pdo))->findByModuleAndTaskKey('core', 'install_update', 10));
    }

    /**
     * A stable-release install — and any task queued before this field
     * existed — carries no deadline, and must never be probed or delayed:
     * its artifact was published before the install was ever scheduled.
     */
    public function testAnInstallWithNoDeadlineIsNeverMadeToWait(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        // A probe answering 404 would reschedule if it were consulted.
        $this->handlerProbing(404)->handle(
            ['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'],
            $this->context
        );

        $this->assertSame('failed', $this->updateHistoryRepository->findById($id)->status);
        $this->assertCount(0, (new SchedulerRepository($this->pdo))->findByModuleAndTaskKey('core', 'install_update', 10));
    }

    /**
     * The artifact is unpacked over the live PHP tree, so download() refuses
     * any non-GitHub URL before the first byte is fetched — the outermost
     * layer of the H1 self-update integrity fix. Tested via reflection since
     * the guard throws immediately (no network), well before the retry loop.
     */
    /**
     * The rollback that actually worked is the one nobody could diagnose.
     * `markRolledBack()` writes a deliberately vague user-facing string
     * (UserFacingMessage's rule: no class name, no file path, no library
     * text on a page) promising the detail is in the event journal — and
     * the journal entry did not carry it. Six identical production
     * rollbacks on scoutmagic.be were readable only by reasoning about the
     * diff, because `Error: Unknown named parameter $backupCreated`
     * existed in no record at all: not the journal, not update_history,
     * and not the server log, since the handler catches the throwable.
     *
     * So this asserts the promise rather than the wording: the message,
     * the class and the throw site are all in the entry, and none of them
     * leaks into what the page shows.
     */
    public function testASuccessfulRollbackJournalsTheErrorThatCausedIt(): void
    {
        $historyId = $this->updateHistoryRepository->create('dev-8e3b6c1', 'dev-63afd86', false, $this->userId);
        $history = $this->updateHistoryRepository->findById($historyId);
        $this->assertNotNull($history);

        // The restore itself must SUCCEED: the entry under test is the one
        // written after it, and a failing restore takes the other branch.
        $backupService = $this->createMock(BackupService::class);
        $backupService->expects($this->once())->method('restoreDatabase');
        $backupService->expects($this->once())->method('restoreFiles');

        $error = new \Error('Unknown named parameter $backupCreated');

        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'rollbackToSafetyBackup');
        $method->setAccessible(true);
        $method->invoke(
            $this->handler,
            $historyId,
            $history,
            $this->context,
            $this->updateHistoryRepository,
            $backupService,
            $this->storagePath . '/dump.sql',
            $this->storagePath . '/files.zip',
            $error
        );

        $row = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'update_rolled_back' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'a completed rollback must be journaled');

        $context = json_decode((string) $row['context'], true);
        $this->assertSame('dev-8e3b6c1', $context['version_from']);
        $this->assertSame('dev-63afd86', $context['version_to']);
        $this->assertSame('Unknown named parameter $backupCreated', $context['error']);
        $this->assertSame('Error', $context['error_class']);
        $this->assertStringStartsWith('InstallUpdateHandlerTest.php:', $context['error_at']);
    }

    /**
     * The counterpart, and the reason the detail goes in the journal and
     * not in `markRolledBack()`: that string is rendered as a title=""
     * tooltip on Configuration > Maintenance, so it must stay free of the
     * exception's own text.
     */
    public function testTheDetailStaysOutOfWhatTheMaintenancePageShows(): void
    {
        $historyId = $this->updateHistoryRepository->create('dev-8e3b6c1', 'dev-63afd86', false, $this->userId);
        $history = $this->updateHistoryRepository->findById($historyId);
        $this->assertNotNull($history);

        $backupService = $this->createMock(BackupService::class);

        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'rollbackToSafetyBackup');
        $method->setAccessible(true);
        $method->invoke(
            $this->handler,
            $historyId,
            $history,
            $this->context,
            $this->updateHistoryRepository,
            $backupService,
            $this->storagePath . '/dump.sql',
            $this->storagePath . '/files.zip',
            new \Error('Unknown named parameter $backupCreated')
        );

        $after = $this->updateHistoryRepository->findById($historyId);
        $this->assertNotNull($after);
        $this->assertSame('rolled_back', $after->status);
        $this->assertStringNotContainsString('backupCreated', (string) $after->errorMessage);
        $this->assertStringContainsString('journal des événements', (string) $after->errorMessage);
    }

    public function testDownloadRefusesANonGitHubUrlBeforeFetching(): void
    {
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'download');
        $method->setAccessible(true);

        $dest = $this->storagePath . '/temp/should_never_be_written.zip';

        // The specific "refused" message (not a generic download failure)
        // proves the URL was rejected up front rather than merely failing to
        // connect after the retry window.
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('URL de mise à jour refusée');
        try {
            $method->invoke($this->handler, 'https://evil.example/artifact.zip', $dest);
        } finally {
            $this->assertFileDoesNotExist($dest, 'a refused URL must never write the destination file');
        }
    }

    /**
     * A GitHub download legitimately redirects across GitHub's own hosts, but
     * every hop is re-validated: a redirect (or an initial URL) pointing off
     * GitHub aborts the attempt instead of being followed. attemptDownload()
     * checks the allowlist as the first thing in its per-hop loop, so a
     * non-GitHub URL returns the refusal reason without any network call.
     */
    public function testAttemptDownloadRefusesAHopToANonGitHubHost(): void
    {
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'attemptDownload');
        $method->setAccessible(true);

        [$ok, $status, $reason] = $method->invoke(
            $this->handler,
            'https://evil.example/artifact.zip',
            $this->storagePath . '/temp/never.zip'
        );

        $this->assertFalse($ok);
        $this->assertNull($status);
        $this->assertSame('redirection hors GitHub refusée', $reason);
    }

    public function testParseLocationHeaderReturnsTheLastLocationCaseInsensitively(): void
    {
        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'parseLocationHeader');
        $method->setAccessible(true);

        $this->assertSame(
            'https://codeload.github.com/owner/repo/zip/refs/tags/v1.2.3',
            $method->invoke($this->handler, [
                'HTTP/1.1 302 Found',
                'Location: https://example.invalid/first',
                'LOCATION: https://codeload.github.com/owner/repo/zip/refs/tags/v1.2.3',
            ])
        );
        $this->assertNull($method->invoke($this->handler, ['HTTP/1.1 200 OK']));
        $this->assertNull($method->invoke($this->handler, null));
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

    // ── What the admin is told, and what they are not ───────────────────

    public function testTheFailureNamesTheFileRelativeToTheInstallRoot(): void
    {
        // Naming the file is the whole point: an interrupted update with
        // no file in it leaves the admin with a broken site and nowhere to
        // start. Naming the absolute path was the reason it could not be
        // shown to them — that prefix is the hosting account, and often a
        // customer id above it.
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core/View', 0755, true);
        mkdir($dest . '/core', 0755, true);
        file_put_contents($source . '/core/View/TwigFactory.php', '<?php // new');
        // Blocks core/View specifically, so the message has a path with
        // more than one segment in it to get right.
        file_put_contents($dest . '/core/View', 'not a directory');

        try {
            $this->invokeInstallFiles($source, $dest);
            $this->fail('installFiles() should have thrown');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('core/View', $e->getMessage());
            $this->assertStringNotContainsString($dest, $e->getMessage());
            $this->assertStringNotContainsString($this->storagePath, $e->getMessage());
        }
    }

    public function testTheFailureIsWrittenForTheAdminWhoHasToFixIt(): void
    {
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core', 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/core/Thing.php', '<?php');
        file_put_contents($dest . '/core', 'not a directory');

        try {
            $this->invokeInstallFiles($source, $dest);
            $this->fail('installFiles() should have thrown');
        } catch (UpdateException $e) {
            // French, whole sentences, and it says what to go and check —
            // which is what lets the class be a UserFacingException at
            // all (tests/Core/Exception/UserFacingExceptionInventoryTest).
            $this->assertStringContainsString("droits d'écriture", $e->getMessage());
            $this->assertStringContainsString('espace disque', $e->getMessage());
            // No raw PHP warning: error_get_last() at this point is not
            // reliably the warning from the suppressed call above it.
            $this->assertStringNotContainsString('mkdir(', $e->getMessage());
            $this->assertStringNotContainsString('Permission denied', $e->getMessage());
        }
    }

    /**
     * @return string[]
     */
    private function replacedPhpFiles(): array
    {
        $property = new \ReflectionProperty(InstallUpdateHandler::class, 'replacedPhpFiles');
        $property->setAccessible(true);

        /** @var string[] $value */
        $value = $property->getValue($this->handler);

        return $value;
    }

    /**
     * The list OPcache invalidation is driven from. Without it the handler
     * would have to evict the whole shared cache — impolite on hosting
     * where one pool serves several sites.
     */
    public function testInstallFilesRecordsEveryPhpFileItReplaced(): void
    {
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core/Help', 0755, true);
        mkdir($source . '/modules/rental/help', 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/core/Help/HelpFrontMatterParser.php', '<?php // new');
        file_put_contents($source . '/modules/rental/help/gerer-les-locations.md', '---');
        file_put_contents($source . '/composer.json', '{}');

        $this->invokeInstallFiles($source, $dest);

        // Exactly the compiled-code files, and nothing else: the Markdown
        // topic and the JSON manifest are read from disk on every request
        // and were never compiled, so there is nothing to invalidate.
        $this->assertSame(
            [$dest . '/core/Help/HelpFrontMatterParser.php'],
            $this->replacedPhpFiles()
        );
    }

    /**
     * A resumed migration re-enters the handler without re-running the
     * copy. If the list survived from the previous attempt, that second
     * pass would invalidate paths it never touched.
     */
    public function testInstallFilesStartsItsListFreshOnEveryRun(): void
    {
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core', 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/core/First.php', '<?php');

        $this->invokeInstallFiles($source, $dest);
        $this->assertCount(1, $this->replacedPhpFiles());

        unlink($source . '/core/First.php');
        file_put_contents($source . '/core/Second.php', '<?php');
        $this->invokeInstallFiles($source, $dest);

        $this->assertSame([$dest . '/core/Second.php'], $this->replacedPhpFiles());
    }

    /**
     * The regression this closes: for up to `opcache.revalidate_freq`
     * seconds after an update, PHP kept executing the previous version of
     * every class against the templates and help topics the same update
     * had just replaced — old Core\Help\HelpFrontMatterParser against a
     * new topic returned 500 on every route for 54 seconds on the real
     * site. The sweep must also survive a host with no OPcache at all,
     * which is why nothing here is allowed to throw.
     */
    public function testStaleCompiledCodeIsDroppedAndTheListReleased(): void
    {
        $source = $this->storagePath . '/src';
        $dest = $this->storagePath . '/dest';
        mkdir($source . '/core', 0755, true);
        mkdir($dest, 0755, true);
        file_put_contents($source . '/core/Thing.php', '<?php');

        $this->invokeInstallFiles($source, $dest);
        $this->assertNotEmpty($this->replacedPhpFiles());

        $method = new \ReflectionMethod(InstallUpdateHandler::class, 'dropStaleCompiledCode');
        $method->setAccessible(true);
        $method->invoke($this->handler);

        $this->assertSame([], $this->replacedPhpFiles(), 'the list is released once it has been acted on');
    }

    /**
     * A superadmin account plus a TaskContext whose NotificationService can
     * actually resolve roles — the shape a real install runs in, and the
     * only shape in which the automatic-update types' role_min means
     * anything (the shared context in setUp() has no RoleResolver, so it
     * degrades to "every recipient allowed").
     *
     * @return array{0: int, 1: TaskContext}
     */
    private function superadminAndRoleAwareContext(): array
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $email = 'super@test.example';
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 1)'
        );
        $stmt->execute([
            $encryption->encrypt($email, 'user_accounts.email'),
            $encryption->blindIndex($email, 'email'),
        ]);
        $superadminId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $journalService = new JournalService(new JournalRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $settings = new SettingService(new SettingRepository($this->pdo));

        $context = new TaskContext(
            Connection::withPdo($this->pdo),
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
                $userAccountRepository,
                new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo),
                new ScoutYearService($this->pdo)
            )
        );

        return [$superadminId, $context];
    }

    /**
     * The bug this whole feature exists for: a dev build installed from a
     * push webhook has no requester, and used to notify nobody at all —
     * several could install overnight leaving nothing but journal entries.
     */
    public function testAnInstallNobodyRequestedNotifiesTheDeclaredAudience(): void
    {
        [$superadminId, $context] = $this->superadminAndRoleAwareContext();
        $id = $this->updateHistoryRepository->create('1.0.0', 'dev-a1b2c3d', false, null);

        $this->handler->handle(
            ['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip', 'source_type' => 'branch'],
            $context
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $notifications = (new NotificationRepository($this->pdo, $encryption))->findByUserAccountId($superadminId);
        $this->assertCount(1, $notifications);
        $this->assertSame('core.update_failed', $notifications[0]->typeId);
        $this->assertSame('Échec de la mise à jour', $notifications[0]->title);
    }

    /**
     * An admin is offered the same type but has to ask for it — nothing
     * arrives while they have never touched the switch.
     */
    public function testAnInstallNobodyRequestedLeavesAnAccountBelowRoleMinAlone(): void
    {
        [, $context] = $this->superadminAndRoleAwareContext();
        $id = $this->updateHistoryRepository->create('1.0.0', 'dev-a1b2c3d', false, null);

        $this->handler->handle(
            ['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip', 'source_type' => 'branch'],
            $context
        );

        // $this->userId is the plain account created in setUp(): no
        // superadmin flag, no member_year, so RoleResolver puts it at
        // "identified" — below the types' 'admin' role_min.
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->assertCount(
            0,
            (new NotificationRepository($this->pdo, $encryption))->findByUserAccountId($this->userId)
        );
    }

    /**
     * A manual "Installer maintenant" still answers its own requester
     * directly, and only them — the broadcast would otherwise tell the
     * superadmin who clicked the button about it a second time.
     */
    public function testARequestedInstallStillNotifiesOnlyItsRequester(): void
    {
        [$superadminId, $context] = $this->superadminAndRoleAwareContext();
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $context);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $repository = new NotificationRepository($this->pdo, $encryption);

        $requesterNotifications = $repository->findByUserAccountId($this->userId);
        $this->assertCount(1, $requesterNotifications);
        $this->assertSame('Échec de la mise à jour', $requesterNotifications[0]->title);

        $this->assertCount(0, $repository->findByUserAccountId($superadminId));
    }
}
