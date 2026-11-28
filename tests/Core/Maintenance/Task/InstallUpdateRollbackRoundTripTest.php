<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\Task\InstallUpdateHandler;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The automatic rollback of an update, with its queue actually restored
 * (issue #1463).
 *
 * The safety copy is taken in the middle of the pass that starts the
 * update, while that pass holds other rows `processing`. A migration that
 * fails on a LATER pass restores that copy — and with it a queue whose
 * rows have run since. These tests run the real scheduler, the real
 * handler and a real dump and restore, on a throwaway database of their
 * own (a restore drops and reloads a schema) and a throwaway file tree
 * (restoreFiles() extracts over the parent of the storage path), the way
 * {@see RestoreBackupRoundTripTest} does.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InstallUpdateRollbackRoundTripTest extends TestCase
{
    use UsesProductionEngine;

    private const ARTIFACT_URL = 'https://github.com/owner/repo/releases/download/v1.1.0/scoutmagic.zip';

    private ?Connection $connection = null;
    private ?\PDO $server = null;
    private string $database = '';
    private string $storagePath = '';

    /** @var \ArrayObject<string, int> task key => runs */
    private \ArrayObject $runs;

    protected function setUp(): void
    {
        $this->runs = new \ArrayObject();
        $server = self::productionEngineConnection()->getPdo();
        $this->database = 'scoutmagic_rollback_' . bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `' . $this->database . '`');
        $this->server = $server;
        $this->connection = self::productionEngineConnection($this->database);
        $this->createSchema();

        $base = sys_get_temp_dir() . '/scoutmagic_rollback_' . bin2hex(random_bytes(6));
        $this->storagePath = $base . '/storage';
        mkdir($this->storagePath . '/backups', 0o777, true);
        // An empty ZipArchive is never written: the copy needs one file.
        mkdir($base . '/public', 0o777, true);
        file_put_contents($base . '/public/index.php', "<?php // fixture\n");

        $settings = new SettingService(new SettingRepository($this->pdo()));
        $settings->register('update_github_owner', '', 'text', 'Owner', 'Fixture.');
        $settings->register('update_github_repo', '', 'text', 'Repo', 'Fixture.');
        $settings->set('update_github_owner', 'owner');
        $settings->set('update_github_repo', 'repo');
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }
        if ($this->storagePath !== '') {
            $this->removeTree(dirname($this->storagePath));
        }
    }

    /**
     * The ticket's scenario. The copy holds the update's own row, a
     * recurring chain and a one-shot task, all `processing`; the pass went
     * on to run the other two and the chain re-armed itself. A later pass
     * resumes the migration, which fails, and rolls back.
     *
     * Nothing of the copy runs again — neither on that pass nor on the
     * next, where releaseNeverStarted() would have handed the two claimed
     * rows straight back — and the chain is not frozen either: it has its
     * one pending row, which the next pass runs.
     */
    public function testAResumedMigrationThatRollsBackReplaysNothingOfTheCopy(): void
    {
        $history = new UpdateHistoryRepository($this->pdo());
        $historyId = $history->create('1.0.0', '1.1.0', false, null);
        $history->setStatus($historyId, 'backing_up');
        $claimedAt = (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');
        $install = $this->row('install_update', 'push', 'processing', $claimedAt, $this->installPayload($historyId), true);
        $chain = $this->row('tache_recurrente', 'auto', 'processing', $claimedAt, '{"curseur": 42}');
        $oneShot = $this->row('tache_ponctuelle', null, 'processing', $claimedAt);

        $backupId = $this->takeSafetyCopy();

        // The rest of that pass, and the passes since.
        $history->setBackupId($historyId, $backupId);
        $history->setStatus($historyId, 'migrating');
        $this->pdo()->exec("UPDATE scheduled_actions SET status = 'done' WHERE id IN ({$install}, {$chain}, {$oneShot})");
        $this->row('tache_recurrente', 'auto', 'pending', (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'));
        $resumeId = $this->row(
            'install_update',
            null,
            'pending',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            $this->installPayload($historyId)
        );

        // No `site_version` setting in this database: the migration has
        // nothing to do, and the completion that follows throws — the
        // rollback runs for real.
        $this->runner(new InstallUpdateHandler())->processOverdue();

        $this->assertSame('rolled_back', $history->findById($historyId)?->status);
        $this->assertSame([], $this->runs->getArrayCopy(), 'nothing of the copy ran on the rollback\'s own pass');
        $this->assertSame(
            [['tache_recurrente', 'auto', null, 'pending']],
            $this->liveRows(),
            'one pending row per chain, and nothing else live'
        );
        $this->assertSame('done', $this->statusOf($resumeId), 'the running row kept its id for the scheduler');
        $this->assertSame('canceled', $this->statusOf($install));
        $this->assertSame('canceled', $this->statusOf($oneShot));

        $line = $this->journalLine('update_rollback_tasks_reset');
        $this->assertSame('core', $line['category']);
        $this->assertSame('warning', $line['level']);
        // Key order is not part of the contract: MySQL's JSON type stores
        // an object's keys sorted by length, SQLite keeps them as written.
        $context = json_decode((string) $line['context'], true);
        $this->assertIsArray($context);
        ksort($context);
        $this->assertSame(['cancelled' => 3, 'rearmed' => 1, 'update_history_id' => $historyId], $context);

        $this->runner(new InstallUpdateHandler())->processOverdue();

        $this->assertSame(1, $this->runs['tache_recurrente'] ?? 0, 'the chain runs at once, not six hours on');
        $this->assertSame(0, $this->runs['tache_ponctuelle'] ?? 0, 'a one-shot task of the copy is never replayed');
    }

    /**
     * The initial attempt is the other case, and the reset would be wrong
     * there: its copy was taken by this very pass and nothing ran since,
     * so the rows the pass claimed after the update are still to run once.
     */
    public function testARollbackOnTheAttemptThatTookTheCopyLetsThePassRunWhatItClaimed(): void
    {
        $history = new UpdateHistoryRepository($this->pdo());
        $historyId = $history->create('1.0.0', '1.1.0', false, null);
        $due = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $install = $this->row('install_update', null, 'pending', $due, $this->installPayload($historyId));
        $this->row('tache_ponctuelle', null, 'pending', $due);

        $failingDownload = new class extends InstallUpdateHandler {
            protected function attemptDownload(string $url, string $destPath): array
            {
                return [false, 404, 'introuvable'];
            }
        };
        $this->runner($failingDownload)->processOverdue();

        $this->assertSame('rolled_back', $history->findById($historyId)?->status);
        $this->assertSame(1, $this->runs['tache_ponctuelle'] ?? 0, 'claimed by the pass, and run by it once');
        $this->assertSame('done', $this->statusOf($install));
        $this->assertNotContains(
            'update_rollback_tasks_reset',
            $this->pdo()->query('SELECT event_type FROM event_log')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    // ── fixture ───────────────────────────────────────────────────────

    private function runner(InstallUpdateHandler $install): SchedulerRunner
    {
        $journal = new JournalService(new JournalRepository($this->pdo()));
        $runner = new SchedulerRunner(new SchedulerRepository($this->pdo()), $journal);
        $runner->setTaskContext($this->context());
        $runner->registerHandler('core', 'install_update', $install);
        foreach (['tache_recurrente', 'tache_ponctuelle'] as $taskKey) {
            $runner->registerHandler('core', $taskKey, $this->counting($taskKey));
        }

        return $runner;
    }

    private function counting(string $taskKey): TaskHandlerInterface
    {
        return new class ($taskKey, $this->runs) implements TaskHandlerInterface {
            /** @param \ArrayObject<string, int> $runs */
            public function __construct(private string $taskKey, private \ArrayObject $runs)
            {
            }

            public function handle(array $payload, TaskContext $context): void
            {
                $this->runs[$this->taskKey] = ($this->runs[$this->taskKey] ?? 0) + 1;
            }
        };
    }

    private function installPayload(int $historyId): string
    {
        return (string) json_encode(
            ['history_id' => $historyId, 'download_url' => self::ARTIFACT_URL, 'source_type' => 'release']
        );
    }

    /**
     * A scheduled row as the scheduler leaves it: `processing` rows carry
     * the claim, and the one running carries its start as well.
     */
    private function row(
        string $taskKey,
        ?string $reference,
        string $status,
        string $runAt,
        ?string $payload = null,
        bool $started = false
    ): int {
        $claimed = $status === 'processing' ? $runAt : null;
        $this->pdo()->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, reference, payload, run_at, status, claimed_at, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['core', $taskKey, $reference, $payload, $runAt, $status, $claimed, $started ? $runAt : null]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** A safety copy as the handler registers one: a dump, an archive in clear, a completed row. */
    private function takeSafetyCopy(): int
    {
        $service = new BackupService($this->connection, $this->storagePath, dirname($this->storagePath));
        $dump = $service->createDatabaseDump();
        $zip = $service->createFileBackup();

        $backups = new BackupRepository($this->pdo());
        $files = new FileRepository($this->pdo());
        $backupId = $backups->create('auto_update', null);
        $register = fn (string $path, string $name, string $mime): int => $files->create(
            ltrim(substr($path, strlen($this->storagePath)), '/'),
            $name,
            $mime,
            (int) filesize($path),
            \Core\Maintenance\Backup::FILE_ROLE,
            null,
            null
        );
        $backups->markCompleted(
            $backupId,
            $register($zip, 'sauvegarde.zip', 'application/zip'),
            $register($dump, 'database.sql', 'application/sql')
        );

        return $backupId;
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: ?string, 3: string}>
     */
    private function liveRows(): array
    {
        $rows = $this->pdo()->query(
            "SELECT task_key, reference, payload, status FROM scheduled_actions
             WHERE status IN ('pending', 'processing') ORDER BY id"
        )->fetchAll(\PDO::FETCH_NUM);

        return array_map(
            static fn (array $row): array => [(string) $row[0], $row[1], $row[2], (string) $row[3]],
            $rows
        );
    }

    private function statusOf(int $id): string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM scheduled_actions WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function journalLine(string $eventType): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT category, level, context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$eventType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "no « {$eventType} » line in the journal");

        return $row;
    }

    private function pdo(): \PDO
    {
        $this->assertNotNull($this->connection);

        return $this->connection->getPdo();
    }

    private function context(): TaskContext
    {
        $this->assertNotNull($this->connection);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            $this->connection,
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo())),
            new SettingService(new SettingRepository($this->pdo())),
            new UserAccountRepository($this->pdo(), $encryption),
            $this->storagePath
        );
    }

    /** The real core schema, as RestoreBackupRoundTripTest::createSchema() loads it. */
    private function createSchema(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/schema/core.sql');
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
