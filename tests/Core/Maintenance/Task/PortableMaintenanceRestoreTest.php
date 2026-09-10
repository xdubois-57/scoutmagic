<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupService;
use Core\Maintenance\Task\RestoreBackupHandler;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The other entry point: a portable archive uploaded to a site that is
 * already running.
 *
 * **Its sibling `RestoreBackupHandlerTest` cannot reach here**, and that is
 * why this class exists rather than three more cases over there. That one
 * drives the handler against a connection the safety backup fails fast
 * on — deliberately, because what it asserts is refusals and failure
 * paths. The whole success path of a portable restore therefore lives
 * beyond it: the safety copy that actually completes, the archive applied,
 * the migration queued.
 *
 * So this is the roadmap's « restauration depuis Maintenance », end to end
 * on a real server, and the counterpart of the wizard's own test.
 *
 * @group database
 */
#[Group('database')]
final class PortableMaintenanceRestoreTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const ORIGIN_ID = 'aaaabbbbccccddddeeeeffff00001111';
    private const ORIGIN_ENCRYPTION_KEY = 'la-clef-de-colonne-de-l-origine=';

    private \PDO $pdo;
    private Connection $connection;
    private TaskContext $context;
    private RestoreBackupHandler $handler;
    private string $siteBase;
    private string $originBase;
    private string $originMasterKey;
    private int $userId;

    /** @var string[] */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->connection = $this->realDbConnection();
        $this->pdo = $this->connection->getPdo();
        $this->handler = new RestoreBackupHandler();

        $this->siteBase = sys_get_temp_dir() . '/portable_maintenance_' . uniqid();
        $this->originBase = $this->siteBase . '_origin';
        $this->originMasterKey = random_bytes(32);

        // The site being restored ONTO: its own keys, its own credentials.
        $this->write($this->siteBase, 'core/App.php', '<?php // le site en place');
        $this->write($this->siteBase, 'storage/uploads/ancien.pdf', 'un document deja la');
        $this->write($this->siteBase, 'storage/keys/master.key', random_bytes(32));
        (new SecretManager(
            $this->siteBase . '/storage/keys/master.key',
            $this->siteBase . '/storage/config/secrets.enc'
        ))->writeSecrets($this->targetSecrets() + ['encryption_key' => 'la-clef-du-site-en-place-a-jeter']);

        // The site the archive came from.
        $this->write($this->originBase, 'core/App.php', '<?php // origine');
        $this->write($this->originBase, 'storage/uploads/tresorerie.pdf', 'les comptes de l\'unite');
        $this->write($this->originBase, 'storage/keys/master.key', $this->originMasterKey);
        (new SecretManager(
            $this->originBase . '/storage/keys/master.key',
            $this->originBase . '/storage/config/secrets.enc'
        ))->writeSecrets([
            'db_host' => 'ancien-hebergeur.example',
            'db_name' => 'ancienne_base',
            'db_password' => 'ancien-mot-de-passe',
            'encryption_key' => self::ORIGIN_ENCRYPTION_KEY,
        ]);

        $statement = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $statement->execute(['enc', hash('sha256', 'portable-maintenance-' . uniqid())]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->context = new TaskContext(
            $this->connection,
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->siteBase . '/storage'
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->siteBase);
        $this->removeDirectory($this->originBase);
    }

    /** @return array<string, mixed> */
    private function targetSecrets(): array
    {
        return [
            'db_host' => '127.0.0.1',
            'db_name' => 'la_base_du_site_en_place',
            'db_password' => 'le-mot-de-passe-du-site-en-place',
        ];
    }

    /**
     * **The whole Maintenance path, on a real server.**
     *
     * A safety copy that actually completes, an archive applied over a
     * running site, and the migration queued for the pass that follows.
     * What is asserted is what an operator would check: their data is
     * there, their database credentials were not replaced by the old
     * host's, and the site can read what it was handed.
     */
    public function testAPortableUploadIsRestoredOverARunningSite(): void
    {
        $archivePath = $this->buildOriginArchive();

        $this->handler->handle([
            'source' => 'upload',
            'uploaded_temp_path' => $archivePath,
            'encrypted_password' => $this->encryptedPassphrase(self::PASSPHRASE),
            'requested_by_user_account_id' => $this->userId,
        ], $this->context);

        $this->assertSame(
            [],
            $this->journalEntries('backup_restore_failed'),
            'the safety backup or the restore failed: ' . $this->lastJournalMessage()
        );
        $this->assertSame([], $this->journalEntries('portable_restore_refused'));

        // The origin's data arrived.
        $this->assertFileExists($this->siteBase . '/storage/uploads/tresorerie.pdf');
        $this->assertSame(
            $this->originMasterKey,
            file_get_contents($this->siteBase . '/storage/keys/master.key'),
            'the site cannot read the data it was just handed'
        );

        // And its own credentials survived (D5).
        $restored = (new SecretManager(
            $this->siteBase . '/storage/keys/master.key',
            $this->siteBase . '/storage/config/secrets.enc'
        ))->readSecrets();
        $this->assertSame(self::ORIGIN_ENCRYPTION_KEY, $restored['encryption_key'] ?? null);
        foreach ($this->targetSecrets() as $key => $expected) {
            $this->assertSame($expected, $restored[$key] ?? null, $key . ' was taken from the archive.');
        }

        // The uploaded archive does not outlive the operation.
        $this->assertFileDoesNotExist($archivePath);
    }

    /**
     * The migration is queued rather than run, and the pass that will run
     * it is told this restore was portable.
     *
     * Forgetting that flag would not fail: the restore would finish, and
     * the site would go on reporting itself as the installation it
     * replaced.
     */
    public function testTheResumePassIsToldTheRestoreWasPortable(): void
    {
        $this->handler->handle([
            'source' => 'upload',
            'uploaded_temp_path' => $this->buildOriginArchive(),
            'encrypted_password' => $this->encryptedPassphrase(self::PASSPHRASE),
            'requested_by_user_account_id' => $this->userId,
        ], $this->context);

        $rows = $this->pdo->query(
            "SELECT payload FROM scheduled_actions WHERE task_key = 'restore_backup' ORDER BY id DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotSame([], $rows, 'no follow-up pass was queued at all');

        $payload = json_decode((string) $rows[0]['payload'], true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['resume_migration'] ?? false);
        $this->assertTrue($payload['portable'] ?? false, 'the resumed pass will not adopt a new identity');
        $this->assertSame(self::ORIGIN_ID, $payload['portable_origin_installation_id'] ?? null);
    }

    /**
     * **The pass that finishes the job**, and the only place D6 actually
     * happens on this route.
     *
     * The restore itself cannot adopt the new identity: at that moment the
     * database is the ORIGIN's, and an origin running an older ScoutMagic
     * has no `statistics_restored_from` row to write into. So the flag
     * travels in the queued payload and the work happens here, after the
     * migration — which is exactly the arrangement that would go unnoticed
     * if it broke, because a restore that forgets it finishes cleanly and
     * simply goes on reporting itself as the site it replaced.
     *
     * The resumed pass is run from the payload the previous one queued,
     * not from a payload written here: a test that composed its own would
     * agree with itself and prove nothing about what was handed over.
     */
    public function testTheResumedPassGivesTheRestoredSiteANewIdentity(): void
    {
        // Seeded into the database the archive is about to be made from,
        // so the dump genuinely carries the origin's identity — the shared
        // test database holds whatever an earlier run left there, which
        // would let this test pass on a restore that adopted nothing.
        $this->seedSetting(InstallationIdentityService::INSTALLATION_ID_SETTING, self::ORIGIN_ID);
        $this->seedSetting(InstallationIdentityService::RESTORED_FROM_SETTING, '');
        // Cleared before the archive is made, so the dump carries none and
        // the one counted at the end can only have been written by the
        // resumed pass. The journal is never truncated between runs, so an
        // unscoped count would inherit every earlier run's.
        $this->pdo->exec("DELETE FROM event_log WHERE event_type = 'portable_restore_completed'");

        $this->handler->handle([
            'source' => 'upload',
            'uploaded_temp_path' => $this->buildOriginArchive(),
            'encrypted_password' => $this->encryptedPassphrase(self::PASSPHRASE),
            'requested_by_user_account_id' => $this->userId,
        ], $this->context);

        $rows = $this->pdo->query(
            "SELECT payload FROM scheduled_actions WHERE task_key = 'restore_backup' ORDER BY id DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotSame([], $rows, 'no follow-up pass was queued at all');
        $resumePayload = json_decode((string) $rows[0]['payload'], true);
        $this->assertIsArray($resumePayload);

        // The rows the restored database carries at this point are the
        // ORIGIN's — including its identifier, which is the thing that
        // must not survive.
        $this->assertSame(
            self::ORIGIN_ID,
            $this->readSetting(InstallationIdentityService::INSTALLATION_ID_SETTING)
        );

        $this->handler->handle($resumePayload, $this->context);

        $this->assertSame(
            '',
            $this->readSetting(InstallationIdentityService::INSTALLATION_ID_SETTING),
            'the restored site still answers with the identity of the site it came from'
        );
        $this->assertSame(
            self::ORIGIN_ID,
            $this->readSetting(InstallationIdentityService::RESTORED_FROM_SETTING),
            'the move reads as an abandonment: nothing records where this site came from'
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'portable_restore_completed'"
            )->fetchColumn(),
            'the move was not journalled, so nothing on the site says it happened'
        );
    }

    /**
     * A passphrase that opens nothing is refused, and the site is left
     * exactly as it was — no safety copy taken, nothing replaced.
     */
    public function testAWrongPassphraseChangesNothingOnTheRunningSite(): void
    {
        $before = file_get_contents($this->siteBase . '/storage/keys/master.key');

        $this->handler->handle([
            'source' => 'upload',
            'uploaded_temp_path' => $this->buildOriginArchive(),
            'encrypted_password' => $this->encryptedPassphrase('une phrase qui n\'ouvre rien'),
            'requested_by_user_account_id' => $this->userId,
        ], $this->context);

        $this->assertNotSame([], $this->journalEntries('portable_restore_refused'));
        $this->assertSame([], $this->journalEntries('backup_restore_failed'));
        $this->assertSame($before, file_get_contents($this->siteBase . '/storage/keys/master.key'));
        $this->assertFileDoesNotExist($this->siteBase . '/storage/uploads/tresorerie.pdf');
    }

    private function seedSetting(string $key, string $value): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, label, description) VALUES (?, ?, ?, ?, ?)'
        )->execute([$key, $value, 'text', $key, '']);
    }

    private function readSetting(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /** Builds the origin's archive and puts it where an upload would be. */
    private function buildOriginArchive(): string
    {
        $service = new BackupService($this->connection, $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '0.0.1', self::ORIGIN_ID);
        $this->cleanupPaths[] = $result['dbDumpPath'];

        $uploaded = $this->siteBase . '/storage/temp/uploaded_' . bin2hex(random_bytes(6)) . '.zip';
        @mkdir(dirname($uploaded), 0755, true);
        rename($result['zipPath'], $uploaded);
        $this->cleanupPaths[] = $uploaded;

        return $uploaded;
    }

    private function encryptedPassphrase(string $passphrase): string
    {
        return base64_encode($this->context->encryption->encrypt($passphrase, 'backup_password'));
    }

    /**
     * This test's own journal entries, and nobody else's.
     *
     * Scoped to the account created in `setUp()`: the test database is
     * shared and `event_log` is never truncated, so an unscoped read picks
     * up rows left by earlier classes — and, worse, by earlier runs of
     * this one, which is how a passing assertion can be made to fail by
     * its own history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function journalEntries(string $eventType): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM event_log WHERE event_type = ? AND user_account_id = ?'
        );
        $statement->execute([$eventType, $this->userId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function lastJournalMessage(): string
    {
        $statement = $this->pdo->prepare(
            'SELECT description, context FROM event_log WHERE user_account_id = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$this->userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? (string) $row['description'] . ' ' . (string) $row['context'] : '(no journal entry)';
    }

    private function write(string $base, string $relativePath, string $contents): void
    {
        $path = $base . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
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
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function realDbConnection(): Connection
    {
        $connection = new Connection(
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: '3306'),
            getenv('TEST_DB_NAME') ?: 'test_db',
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: ''
        );
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database not available: ' . (is_string($result) ? $result : 'unknown error'));
        }

        (new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser()
        ))->migrate([dirname(__DIR__, 4) . '/schema/core.sql']);

        return $connection;
    }
}
