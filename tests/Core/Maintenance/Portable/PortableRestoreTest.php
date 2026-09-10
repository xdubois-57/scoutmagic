<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableArchive;
use Core\Maintenance\Portable\PortableManifest;
use Core\Maintenance\Portable\PortableRestore;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * One installation's archive, restored onto a different installation.
 *
 * **Two real install roots, never one.** The failure this feature exists to
 * prevent, and the one D5 is written against, only appears when the machine
 * being restored ONTO has credentials of its own to lose. A test that
 * restores an archive over the installation that produced it would pass
 * with the credential handling deleted entirely, because both sides would
 * hold the same values.
 *
 * @group database
 */
#[Group('database')]
final class PortableRestoreTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const ORIGIN_ID = 'aaaabbbbccccddddeeeeffff00001111';

    /** What only the origin has, and what the restore exists to carry. */
    private const ORIGIN_ENCRYPTION_KEY = 'la-clef-de-colonne-de-l-origine=';

    private string $originBase;
    private string $targetBase;
    private ?string $zipPath = null;
    private ?string $dbDumpPath = null;
    private string $originMasterKey;

    protected function setUp(): void
    {
        $this->originBase = sys_get_temp_dir() . '/portable_origin_' . uniqid();
        $this->targetBase = sys_get_temp_dir() . '/portable_target_' . uniqid();
        $this->originMasterKey = random_bytes(32);

        // The origin: content, and secrets naming ITS database.
        $this->makeFile($this->originBase, 'core/App.php', '<?php // origin app');
        $this->makeFile($this->originBase, 'public/index.php', '<?php // origin entry');
        $this->makeFile($this->originBase, 'storage/uploads/tresorerie.pdf', 'les comptes de l\'unite');
        $this->writeSecrets($this->originBase, $this->originMasterKey, [
            'db_host' => 'ancien-hebergeur.example',
            'db_port' => 3306,
            'db_name' => 'ancienne_base',
            'db_user' => 'ancien_user',
            'db_password' => 'ancien-mot-de-passe',
            'base_url' => 'https://ancien.example',
            'encryption_key' => self::ORIGIN_ENCRYPTION_KEY,
            'smtp_host' => 'smtp.example',
            'vapid_private_key' => 'la-clef-vapid-de-l-origine',
        ]);

        // The target: a different machine, with credentials of its own that
        // the restore must not touch.
        $this->makeFile($this->targetBase, 'storage/uploads/.gitkeep', '');
        $this->writeSecrets($this->targetBase, random_bytes(32), $this->targetOwnedSecrets() + [
            'encryption_key' => 'la-clef-du-site-neuf-a-remplacer',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->zipPath, $this->dbDumpPath] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->originBase);
        $this->removeDirectory($this->targetBase);
    }

    /** @return array<string, mixed> */
    private function targetOwnedSecrets(): array
    {
        return [
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_name' => 'base_du_site_neuf',
            'db_user' => 'user_du_site_neuf',
            'db_password' => 'mot-de-passe-du-site-neuf',
            'base_url' => 'https://nouveau.example',
        ];
    }

    /**
     * **The one that matters: the new machine keeps its own database.**
     *
     * Writing the origin's `secrets.enc` wholesale is the obvious
     * implementation and the one that bricks a fresh installation — it
     * points the new site at the old site's database host, name and
     * password. Either those are wrong and the site will not start, or
     * they are right and the new site is quietly writing into a database
     * that still belongs to somebody else.
     */
    public function testTheTargetKeepsItsOwnDatabaseCredentials(): void
    {
        $this->restoreOntoTarget();

        $restored = $this->readSecrets($this->targetBase);

        foreach ($this->targetOwnedSecrets() as $key => $expected) {
            $this->assertSame($expected, $restored[$key] ?? null, $key . ' was overwritten by the archive.');
        }
    }

    /**
     * And the half that would make keeping them pointless: everything the
     * archive exists to carry does arrive.
     *
     * A restore that preserved the target's credentials by preserving the
     * whole file would pass the test above and be completely useless — the
     * new site would start, and would not read a single encrypted column.
     */
    public function testTheEncryptionKeysOfTheOriginDoArrive(): void
    {
        $this->restoreOntoTarget();

        $restored = $this->readSecrets($this->targetBase);

        $this->assertSame(self::ORIGIN_ENCRYPTION_KEY, $restored['encryption_key'] ?? null);
        $this->assertSame('la-clef-vapid-de-l-origine', $restored['vapid_private_key'] ?? null);
        $this->assertSame('smtp.example', $restored['smtp_host'] ?? null);
        $this->assertSame(
            $this->originMasterKey,
            file_get_contents($this->targetBase . '/storage/keys/master.key'),
            'the master key on the target is not the origin\'s, so nothing it wrote can be read'
        );
    }

    public function testTheFilesOfTheOriginArrive(): void
    {
        $this->restoreOntoTarget();

        $this->assertFileExists($this->targetBase . '/storage/uploads/tresorerie.pdf');
        $this->assertSame(
            'les comptes de l\'unite',
            file_get_contents($this->targetBase . '/storage/uploads/tresorerie.pdf')
        );
    }

    /**
     * The archive's own bookkeeping never lands on the restored site.
     *
     * A `secrets/` directory in the site root would hold the SEALED bytes
     * next to the live key — the archive's whole protection undone by the
     * restore that used it.
     */
    public function testNeitherTheSealedSecretsNorTheManifestAreLeftOnDisk(): void
    {
        $this->restoreOntoTarget();

        $this->assertDirectoryDoesNotExist($this->targetBase . '/secrets');
        $this->assertFileDoesNotExist($this->targetBase . '/' . PortableManifest::MEMBER);
    }

    /**
     * D6 and its neighbours, on a real database.
     *
     * The identifier is blanked rather than replaced so that
     * `InstallationIdentityService` mints the next one through its own
     * concurrency-safe claim — so what is asserted here is that the site
     * no longer answers with the origin's identity, and that asking for one
     * produces something new.
     */
    public function testTheRestoredInstallationBecomesANewOneRatherThanASecondCopy(): void
    {
        $connection = $this->realDbConnection();
        $pdo = $connection->getPdo();

        $this->seedSetting($pdo, InstallationIdentityService::INSTALLATION_ID_SETTING, self::ORIGIN_ID);
        $this->seedSetting($pdo, PortableRestore::RESTORED_FROM_SETTING, '');
        $this->seedSetting($pdo, 'base_url', 'https://ancien.example');

        (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))
            ->adoptNewIdentity($pdo, self::ORIGIN_ID, 'https://nouveau.example');

        $this->assertSame(
            '',
            $this->readSetting($pdo, InstallationIdentityService::INSTALLATION_ID_SETTING),
            'the restored site still answers with the identity of the site it came from'
        );
        $this->assertSame(self::ORIGIN_ID, $this->readSetting($pdo, PortableRestore::RESTORED_FROM_SETTING));
        $this->assertSame('https://nouveau.example', $this->readSetting($pdo, 'base_url'));
    }

    /**
     * The push subscriptions of the old domain go.
     *
     * A service worker is bound to its origin: carried elsewhere, every
     * endpoint is already dead, and keeping them means every send spends a
     * request collecting a 410 before pruning what was never going to work.
     */
    public function testThePushSubscriptionsOfTheOldDomainAreEmptied(): void
    {
        $connection = $this->realDbConnection();
        $pdo = $connection->getPdo();

        $pdo->exec('DELETE FROM push_subscriptions');
        $userId = $this->seedUserAccount($pdo);
        $pdo->prepare(
            'INSERT INTO push_subscriptions (user_account_id, endpoint, endpoint_blind_index, auth_key, p256dh_key) '
            . 'VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, 'x', str_repeat('a', 64), 'y', 'z']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());

        (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))
            ->adoptNewIdentity($pdo, null, null);

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
    }

    /** Builds the origin's archive and restores it onto the target root. */
    private function restoreOntoTarget(): void
    {
        $service = new BackupService($this->realDbConnection(), $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);
        $archive->verifyDeclaredMembers();

        $restore = new PortableRestore($this->targetBase, $this->targetBase . '/storage');
        $restore->extractFiles($archive);
        $restore->installSecrets($archive, $this->targetOwnedSecrets());

        $archive->close();
    }

    /** @param array<string, mixed> $secrets */
    private function writeSecrets(string $base, string $masterKey, array $secrets): void
    {
        $this->makeFile($base, 'storage/keys/master.key', $masterKey);
        (new SecretManager($base . '/storage/keys/master.key', $base . '/storage/config/secrets.enc'))
            ->writeSecrets($secrets);
    }

    /** @return array<string, mixed> */
    private function readSecrets(string $base): array
    {
        return (new SecretManager($base . '/storage/keys/master.key', $base . '/storage/config/secrets.enc'))
            ->readSecrets();
    }

    private function seedSetting(\PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, label, description) VALUES (?, ?, ?, ?, ?)'
        )->execute([$key, $value, 'text', $key, '']);
    }

    /**
     * A subscription needs an account to belong to — the foreign key is the
     * point, not an obstacle: it is what makes emptying this table on a
     * restore a decision rather than a side effect of the accounts arriving.
     */
    private function seedUserAccount(\PDO $pdo): int
    {
        $blindIndex = hash('sha256', 'portable-restore-test-' . uniqid());
        $pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['chiffre', $blindIndex]);

        return (int) $pdo->lastInsertId();
    }

    private function readSetting(\PDO $pdo, string $key): ?string
    {
        $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function makeFile(string $base, string $relativePath, string $content): void
    {
        $path = $base . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
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
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database not available: ' . (is_string($result) ? $result : 'unknown error'));
        }

        $introspector = new SchemaIntrospector($connection->getPdo());
        $runner = new MigrationRunner($connection, $introspector, new SchemaComparator(), new SqlParser());
        $runner->migrate([dirname(__DIR__, 4) . '/schema/core.sql']);

        return $connection;
    }
}
