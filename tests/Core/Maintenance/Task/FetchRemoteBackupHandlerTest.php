<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Maintenance\BackupService;
use Core\Maintenance\Remote\RemoteRetention;
use Core\Maintenance\Task\FetchRemoteBackupHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use Core\Storage\Location\Backend\LocalStorageBackend;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PortableHints;
use Tests\UsesProductionEngine;

/**
 * Bringing an off-site copy back (#847, part 4): in slices over several
 * runs, its size checked, its phrase checked before the site is touched,
 * then the portable restore queued — or, with a wrong phrase, nothing
 * modified and the copy kept for another try, then discarded.
 *
 * On the production engine because the archive is a real portable one,
 * built from a real dump; the destination is a local directory, which
 * reads ranges like Drive now does.
 */
#[Group('database')]
final class FetchRemoteBackupHandlerTest extends TestCase
{
    use UsesProductionEngine;

    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';

    private \PDO $pdo;
    private TaskContext $context;
    private string $base;
    private string $storagePath;
    private string $remote;
    private string $name;
    private int $size;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $connection = $this->productionEngineSchemaConnection();
        $this->base = sys_get_temp_dir() . '/fetch_remote_' . bin2hex(random_bytes(6));
        $this->storagePath = $this->base . '/site/storage';
        $this->remote = $this->base . '/destination';
        @mkdir($this->storagePath . '/temp', 0700, true);
        @mkdir($this->remote, 0700, true);

        $origin = $this->base . '/origin';
        @mkdir($origin . '/core', 0700, true);
        file_put_contents($origin . '/core/App.php', '<?php // origine');
        @mkdir($origin . '/storage/keys', 0700, true);
        file_put_contents($origin . '/storage/keys/master.key', random_bytes(32));
        (new SecretManager($origin . '/storage/keys/master.key', $origin . '/storage/config/secrets.enc'))
            ->writeSecrets(['encryption_key' => 'cle-de-l-origine']);
        $service = new BackupService($connection, $origin . '/storage', $origin);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption.');
        }
        $built = $service->createPortableBackup(self::PASSPHRASE, PortableHints::sample('0.0.1'), str_repeat('a', 32));
        @unlink($built['dbDumpPath']);
        $this->name = RemoteRetention::nameFor(new \DateTimeImmutable('2026-10-01 03:00:00'), 1);
        rename($built['zipPath'], $this->remote . '/' . $this->name);
        $this->size = (int) filesize($this->remote . '/' . $this->name);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            $connection,
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
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testACopyComesBackInSlicesThenItsRestoreIsQueued(): void
    {
        $path = FetchRemoteBackupHandler::newTempPath($this->storagePath);
        $payload = $this->payload($path, self::PASSPHRASE);

        // A slice per run: the clock jumps past the budget after each one.
        for ($runs = 1; $runs <= 50; $runs++) {
            $this->handler(intdiv($this->size, 3) + 1)->handle($payload, $this->context);
            $next = $this->pending(FetchRemoteBackupHandler::TASK_KEY);
            if ($next === null) {
                break;
            }
            $payload = $next;
        }

        $this->assertGreaterThanOrEqual(3, $runs, 'brought back over several runs, not in one read');
        $restore = $this->pending('restore_backup');
        $this->assertIsArray($restore, 'the portable restore follows');
        $this->assertSame('upload', $restore['source']);
        $this->assertSame($path, $restore['uploaded_temp_path']);
        $this->assertSame($this->size, filesize($path));
        $this->assertContains('remote_copy_fetched', $this->journalTypes());
    }

    /** A wrong phrase modifies nothing and keeps the copy, which a later try reuses. */
    public function testAWrongPhraseKeepsTheCopyForAnotherTry(): void
    {
        $path = FetchRemoteBackupHandler::newTempPath($this->storagePath);

        $this->handler()->handle($this->payload($path, 'pas la bonne phrase'), $this->context);

        $this->assertNull($this->pending('restore_backup'), 'nothing was started');
        $this->assertFileExists($path, 'kept for another try');
        $held = FetchRemoteBackupHandler::held($this->storagePath);
        $this->assertSame(['path' => $path, 'name' => $this->name], array_slice((array) $held, 0, 2));
        $this->assertContains('remote_copy_wrong_passphrase', $this->journalTypes());
        $discard = $this->pending(FetchRemoteBackupHandler::DISCARD_TASK_KEY);
        $this->assertSame(['discard_path' => $path], $discard, 'removed a day later at the latest');

        // Typed again, from what was already brought back: no new download.
        $this->handler()->handle(
            ['fetched' => true] + $this->payload($path, self::PASSPHRASE),
            $this->context
        );
        $this->assertIsArray($this->pending('restore_backup'));
        $this->assertNull(FetchRemoteBackupHandler::held($this->storagePath));
    }

    /** A restore queued meanwhile wins: the copy is kept, and no second restore starts. */
    public function testARestoreAlreadyQueuedKeepsTheCopyRatherThanStartingASecond(): void
    {
        $this->pdo->exec(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status)
             VALUES ('core', 'restore_backup', NOW(), 'pending')"
        );
        $path = FetchRemoteBackupHandler::newTempPath($this->storagePath);

        $this->handler()->handle($this->payload($path, self::PASSPHRASE), $this->context);

        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'restore_backup'")
                ->fetchColumn()
        );
        $this->assertFileExists($path);
        $this->assertSame($path, FetchRemoteBackupHandler::held($this->storagePath)['path'] ?? null);
    }

    public function testAKeptCopyIsDiscardedWhenItsHoldRunsOut(): void
    {
        $path = FetchRemoteBackupHandler::newTempPath($this->storagePath);
        $this->handler()->handle($this->payload($path, 'pas la bonne phrase'), $this->context);

        $this->handler()->handle(['discard_path' => $path], $this->context);

        $this->assertFileDoesNotExist($path);
        $this->assertNull(FetchRemoteBackupHandler::held($this->storagePath));
    }

    public function testACopyWhoseSizeIsNotTheListedOneIsRefusedAndRemoved(): void
    {
        $path = FetchRemoteBackupHandler::newTempPath($this->storagePath);

        $payload = ['size' => $this->size + 10] + $this->payload($path, self::PASSPHRASE);
        for ($runs = 0; $payload !== null && $runs < 5; $runs++) {
            $this->handler()->handle($payload, $this->context);
            $payload = $this->pending(FetchRemoteBackupHandler::TASK_KEY);
        }

        $this->assertNull($this->pending('restore_backup'));
        $this->assertContains('remote_copy_fetch_failed', $this->journalTypes());
        $this->assertFileDoesNotExist($path);
    }

    public function testAPathThatIsNotOneOfOursIsNeverWritten(): void
    {
        $outside = $this->base . '/ailleurs.zip';

        $this->handler()->handle($this->payload($outside, self::PASSPHRASE), $this->context);

        $this->assertFileDoesNotExist($outside);
        $this->assertNull($this->pending('restore_backup'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $path, string $phrase): array
    {
        return [
            'name' => $this->name,
            'size' => $this->size,
            'path' => $path,
            'location_id' => 0,
            'encrypted_password' => base64_encode($this->context->encryption->encrypt($phrase, 'backup_password')),
        ];
    }

    private function handler(int $chunk = FetchRemoteBackupHandler::CHUNK_BYTES): FetchRemoteBackupHandler
    {
        $clock = 0.0;

        return new FetchRemoteBackupHandler(
            new LocalStorageBackend($this->remote),
            static function () use (&$clock): float {
                $now = $clock;
                $clock += FetchRemoteBackupHandler::TIME_BUDGET_SECONDS / 2 + 1;

                return $now;
            },
            $chunk
        );
    }

    /**
     * The payload of the newest pending row of a task, or null.
     *
     * @return array<string, mixed>|null
     */
    private function pending(string $taskKey): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT payload FROM scheduled_actions WHERE task_key = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$taskKey]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return null;
        }
        $this->pdo->prepare("UPDATE scheduled_actions SET status = 'done' WHERE task_key = ?")->execute([$taskKey]);
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function journalTypes(): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string) $row['event_type'],
            (new JournalRepository($this->pdo))->search()
        ));
    }
}
