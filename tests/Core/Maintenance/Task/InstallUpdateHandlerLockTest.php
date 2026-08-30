<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Database\DeploymentMigration;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Maintenance\InstallLock;
use Core\Maintenance\Task\InstallUpdateHandler;
use Core\Maintenance\UpdateHistoryRepository;
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

/**
 * The one thing InstallUpdateHandlerTest cannot show: that the handler
 * actually stands down when another install holds the lock. That class
 * runs on SQLite, where GET_LOCK() does not exist and InstallLock
 * deliberately answers "granted" — so the refused branch is only reachable
 * against a real server, with a second connection genuinely holding the
 * lock.
 *
 * What is at stake is not bookkeeping: the section this guards overwrites
 * the live install directory, and two handlers doing that at once has
 * corrupted an install in practice.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InstallUpdateHandlerLockTest extends TestCase
{
    private ?Connection $connection = null;
    private ?\PDO $rival = null;
    private UpdateHistoryRepository $history;
    private TaskContext $context;
    private string $storagePath = '';
    private string $fakeRoot = '';

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');

        $connection = new Connection($host, $port, $dbName, $user, $password);
        if ($connection->testConnection() !== true) {
            $this->markTestSkipped('Database connection not available');
        }
        $this->connection = $connection;
        $this->dropAllTables($connection->getPdo());

        // The real declared schema rather than a hand-written subset: the
        // handler reads update_history and writes the journal, and a
        // duplicated DDL fixture here would drift away from both.
        DeploymentMigration::run($connection, dirname(__DIR__, 4));

        $pdo = $connection->getPdo();
        $this->history = new UpdateHistoryRepository($pdo);

        $this->rival = new \PDO(
            "mysql:host={$host};port={$port};dbname={$dbName}",
            $user,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // A throwaway install root, NOT a bare temp dir: the handler's
        // safety backup zips dirname(storagePath), and pointing that at
        // /tmp would have it archive the whole temp directory.
        $this->fakeRoot = sys_get_temp_dir() . '/install_lock_test_' . uniqid();
        $this->storagePath = $this->fakeRoot . '/storage';
        mkdir($this->storagePath . '/maintenance', 0755, true);
        file_put_contents($this->fakeRoot . '/VERSION', "1.0.0\n");
        // At least one file the backup will actually archive: it excludes
        // storage/ and VERSION, and ZipArchive writes nothing at all for an
        // archive with zero entries.
        mkdir($this->fakeRoot . '/core', 0755, true);
        file_put_contents($this->fakeRoot . '/core/placeholder.php', "<?php\n");

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $settings = new SettingService(new SettingRepository($pdo));
        $journalService = new JournalService(new JournalRepository($pdo));
        $userAccountRepository = new UserAccountRepository($pdo, $encryption);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            $journalService,
            $settings,
            $userAccountRepository,
            $this->storagePath,
            new NotificationService(
                new NotificationRepository($pdo, $encryption),
                new PushSubscriptionRepository($pdo, $encryption),
                new NotificationPreferenceRepository($pdo),
                $this->createMock(WebPush::class),
                $settings,
                $journalService,
                new SchedulerService(new SchedulerRepository($pdo)),
                $userAccountRepository
            )
        );
    }

    protected function tearDown(): void
    {
        if ($this->rival instanceof \PDO) {
            InstallLock::release($this->rival);
            $this->rival = null;
        }
        if ($this->connection !== null) {
            InstallLock::release($this->connection->getPdo());
            $this->dropAllTables($this->connection->getPdo());
        }
        if ($this->fakeRoot !== '' && is_dir($this->fakeRoot)) {
            $this->removeDirectory($this->fakeRoot);
        }
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function dropAllTables(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testItStandsDownWhenAnotherInstallHoldsTheLock(): void
    {
        $this->assertTrue(InstallLock::acquire($this->rival), 'guard: the rival must really hold the lock');

        $historyId = $this->history->create('1.0.0', 'dev-loser', false, null);
        (new InstallUpdateHandler())->handle(
            ['history_id' => $historyId, 'download_url' => 'https://example.test/a.zip', 'source_type' => 'branch'],
            $this->context
        );

        $row = $this->history->findById($historyId);
        // Terminal, not left at 'pending': a row abandoned there shows as
        // "En cours" in the update history forever — nothing else ever
        // closes it.
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('une autre installation', (string) $row->errorMessage);
        // And it stopped BEFORE touching anything: no backup was started.
        $this->assertNull($row->backupId);
    }

    /**
     * The lock must not leak past one invocation: an update whose migration
     * runs out of budget returns with the status still 'migrating' and
     * resumes later, and a lock still held by a connection that has gone
     * away would block every subsequent install.
     */
    public function testTheLockIsAvailableAgainOnceTheHandlerReturns(): void
    {
        $historyId = $this->history->create('1.0.0', 'dev-first', false, null);
        (new InstallUpdateHandler())->handle(
            ['history_id' => $historyId, 'download_url' => 'https://example.test/a.zip', 'source_type' => 'branch'],
            $this->context
        );

        $this->assertTrue(InstallLock::acquire($this->rival), 'the handler must not still be holding the lock');
    }
}
