<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\File\ChunkedUploadStore;
use Core\Http\Controller\SetupController;
use Core\Http\Request;
use Core\Mail\DkimManager;
use Core\Maintenance\BackupService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Who may reach the wizard's portable restore, and who may not.
 *
 * **This is the most dangerous pair of endpoints in the application**, and
 * saying so plainly is the point of this class. `POST /setup/restore-portable`
 * takes an uploaded file and replaces the database with it. It is reachable
 * without logging in — it has to be, because it exists for the moment when
 * there is no account to log into and no site to log into it with. What
 * stands in its place is the installation token, the same gate that guards
 * the wizard itself, plus the fact that the whole route block disappears the
 * instant the site is initialised.
 *
 * So the two refusals below are not paperwork. Each of them is the only
 * thing between a stranger who found `/setup` and a database of members'
 * addresses, dates of birth and telephone numbers.
 */
final class SetupPortableRestoreTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const ORIGIN_ID = 'aaaabbbbccccddddeeeeffff00001111';
    private const ORIGIN_ENCRYPTION_KEY = 'la-clef-de-colonne-de-l-origine=';

    private string $tempDir;

    /**
     * The install root the controller is pointed at.
     *
     * A real temporary directory with a `public/` inside, never the
     * repository: `SetupController` derives the install root from its
     * `publicDir`, and a restore run against the checkout would write the
     * archive's `storage/` over the working tree.
     */
    private string $installRoot;

    /** @var string[] archives and dumps to remove afterwards */
    private array $cleanupPaths = [];

    private SecretManager $secretManager;
    private DkimManager $dkimManager;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/setup_portable_test_' . uniqid();
        $this->installRoot = $this->tempDir . '/site';
        mkdir($this->installRoot . '/public', 0755, true);
        mkdir($this->installRoot . '/storage/keys', 0700, true);
        mkdir($this->installRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->installRoot . '/storage/keys/master.key',
            $this->installRoot . '/storage/config/secrets.enc'
        );
        $this->dkimManager = new DkimManager($this->installRoot . '/storage/keys');
        $this->twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', true);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->tempDir);
    }

    private function controller(): SetupController
    {
        return new SetupController(
            $this->twig,
            $this->secretManager,
            $this->dkimManager,
            dirname(__DIR__, 4) . '/schema/core.sql',
            $this->installRoot . '/public'
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path): array
    {
        $response = $this->controller()->{$method}(new Request('POST', $path, [], [], [], []), []);
        $decoded = json_decode($response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /**
     * **Without the installation token, nothing.**
     *
     * The token file is what proves whoever is at `/setup` has filesystem
     * access to the server — the only credential that exists before the
     * site does. Without it this endpoint would let anybody who reached an
     * uninstalled ScoutMagic upload a database and own the result.
     */
    public function testTheRestoreIsRefusedWithoutAVerifiedInstallationToken(): void
    {
        unset($_SESSION['setup_token_verified']);

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Jeton', (string) ($body['message'] ?? ''));
    }

    /**
     * And the upload endpoint too, which is the half people forget.
     *
     * Gating only the restore would leave a route that writes attacker-chosen
     * bytes to the server's disk, in chunks, to a path derived from an id
     * they choose — protected by nothing at all.
     */
    public function testTheChunkedUploadIsRefusedWithoutAVerifiedInstallationTokenEither(): void
    {
        unset($_SESSION['setup_token_verified']);

        [$status, $body] = $this->call('restorePortableChunk', '/setup/restore-portable-chunk');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
    }

    /**
     * **Once the site is configured, this way in closes.**
     *
     * From that moment a restore belongs to Configuration > Maintenance,
     * behind a login and a role. The wizard's token is long gone, and
     * `denyUnlessTokenVerified()` deliberately stops refusing anything —
     * so if this second check were missing, the endpoint would go from
     * token-gated to completely open on the day the site started working.
     */
    public function testTheRestoreIsRefusedOnceTheSiteIsConfigured(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $this->initialiseTheSite();

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('déjà configuré', (string) ($body['message'] ?? ''));
    }

    public function testTheChunkedUploadIsRefusedOnceTheSiteIsConfiguredToo(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $this->initialiseTheSite();

        [$status, $body] = $this->call('restorePortableChunk', '/setup/restore-portable-chunk');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
    }

    /**
     * With the token but without a valid CSRF token, still nothing — and
     * this is what says the order of the two gates is deliberate.
     */
    public function testAValidTokenStillNeedsAValidCsrfToken(): void
    {
        $_SESSION['setup_token_verified'] = true;

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringNotContainsString('Jeton d\'installation', (string) ($body['message'] ?? ''));
    }

    /**
     * **The wizard's restore, end to end, on a real database.**
     *
     * The roadmap's headline case: « restauration sur une base vide depuis
     * l'assistant ». Everything real except the browser — the archive is
     * assembled through the same chunk store the page uploads to, the
     * request carries the credentials the operator just typed, and the
     * endpoint does the rest.
     *
     * What it proves beyond the parts already covered elsewhere is the
     * wiring: that the credentials in the REQUEST are the ones that
     * survive, that the schema is migrated afterwards, and that the
     * restored site stops answering with the identity of the one it came
     * from.
     */
    #[Group('database')]
    public function testTheWizardRestoresAnArchiveOntoAFreshInstallation(): void
    {
        $connection = $this->realDbConnection();
        $origin = $this->buildOriginArchive($connection);

        $_SESSION['setup_token_verified'] = true;
        $uploadId = $this->assembleUpload($origin['zipPath']);

        $body = $this->targetCredentials() + [
            '_csrf_token' => $this->issueCsrfToken(),
            'upload_id' => $uploadId,
            'passphrase' => self::PASSPHRASE,
        ];

        $response = $this->controller()->restorePortable(
            new Request('POST', '/setup/restore-portable', [], $body, [], ['HTTP_HOST' => 'nouveau.example']),
            []
        );
        $decoded = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($decoded['success'] ?? false, (string) ($decoded['message'] ?? 'no message'));

        // The origin's data arrived.
        $this->assertFileExists($this->installRoot . '/storage/uploads/tresorerie.pdf');
        $this->assertSame(
            $origin['masterKey'],
            file_get_contents($this->installRoot . '/storage/keys/master.key')
        );

        // The credentials the operator typed survived the archive (D5).
        $restored = (new SecretManager(
            $this->installRoot . '/storage/keys/master.key',
            $this->installRoot . '/storage/config/secrets.enc'
        ))->readSecrets();
        // The request's own credentials, and emphatically not the archive's
        // `ancienne_base` — which is the whole of D5.
        $this->assertSame($this->targetCredentials()['db_name'], $restored['db_name'] ?? null);
        $this->assertSame($this->targetCredentials()['db_host'], $restored['db_host'] ?? null);
        $this->assertNotSame('ancienne_base', $restored['db_name'] ?? null);
        $this->assertNotSame('ancien-hebergeur.example', $restored['db_host'] ?? null);
        // And the origin's column key did arrive, or none of that matters.
        $this->assertSame(self::ORIGIN_ENCRYPTION_KEY, $restored['encryption_key'] ?? null);

        // And the restored site is a new installation, not a second copy of
        // the old one (D6).
        $identity = $this->readSetting($connection->getPdo(), InstallationIdentityService::INSTALLATION_ID_SETTING);
        $this->assertNotSame(self::ORIGIN_ID, $identity);
        $this->assertSame(
            self::ORIGIN_ID,
            $this->readSetting($connection->getPdo(), InstallationIdentityService::RESTORED_FROM_SETTING)
        );
    }

    /** @return array<string, string> */
    private function targetCredentials(): array
    {
        return [
            'db_host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'db_port' => getenv('TEST_DB_PORT') ?: '3306',
            'db_name' => getenv('TEST_DB_NAME') ?: 'test_db',
            'db_user' => getenv('TEST_DB_USER') ?: 'root',
            'db_password' => getenv('TEST_DB_PASSWORD') ?: '',
        ];
    }

    /**
     * A real portable archive, from an install root that is not the target.
     *
     * @return array{zipPath: string, masterKey: string}
     */
    private function buildOriginArchive(Connection $connection): array
    {
        $originBase = $this->tempDir . '/origin';
        $masterKey = random_bytes(32);

        foreach ([
            'core/App.php' => '<?php // origin',
            'public/index.php' => '<?php // origin',
            'storage/uploads/tresorerie.pdf' => 'les comptes de l\'unite',
        ] as $relative => $content) {
            @mkdir(dirname($originBase . '/' . $relative), 0755, true);
            file_put_contents($originBase . '/' . $relative, $content);
        }
        @mkdir($originBase . '/storage/keys', 0700, true);
        file_put_contents($originBase . '/storage/keys/master.key', $masterKey);
        (new SecretManager(
            $originBase . '/storage/keys/master.key',
            $originBase . '/storage/config/secrets.enc'
        ))->writeSecrets([
            'db_host' => 'ancien-hebergeur.example',
            'db_name' => 'ancienne_base',
            'encryption_key' => self::ORIGIN_ENCRYPTION_KEY,
        ]);

        $service = new BackupService($connection, $originBase . '/storage', $originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $this->seedSetting($connection->getPdo(), InstallationIdentityService::INSTALLATION_ID_SETTING, self::ORIGIN_ID);
        $this->seedSetting($connection->getPdo(), InstallationIdentityService::RESTORED_FROM_SETTING, '');

        $result = $service->createPortableBackup(self::PASSPHRASE, '0.0.1', self::ORIGIN_ID);
        $this->cleanupPaths[] = $result['dbDumpPath'];
        $this->cleanupPaths[] = $result['zipPath'];

        return ['zipPath' => $result['zipPath'], 'masterKey' => $masterKey];
    }

    /** Puts the archive through the same store the page uploads to. */
    private function assembleUpload(string $zipPath): string
    {
        $uploadId = bin2hex(random_bytes(16));
        $copy = $this->tempDir . '/chunk.bin';
        copy($zipPath, $copy);

        (new ChunkedUploadStore($this->installRoot . '/storage'))
            ->appendChunk($uploadId, session_id(), 0, $copy, true, 2 * 1024 * 1024 * 1024);

        return $uploadId;
    }

    private function issueCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    private function seedSetting(\PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, label, description) VALUES (?, ?, ?, ?, ?)'
        )->execute([$key, $value, 'text', $key, '']);
    }

    private function readSetting(\PDO $pdo, string $key): ?string
    {
        $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function realDbConnection(): Connection
    {
        $credentials = $this->targetCredentials();
        $connection = new Connection(
            $credentials['db_host'],
            (int) $credentials['db_port'],
            $credentials['db_name'],
            $credentials['db_user'],
            $credentials['db_password']
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

    /** The cheapest thing that makes SecretManager::isInitialized() true. */
    private function initialiseTheSite(): void
    {
        file_put_contents($this->installRoot . '/storage/keys/master.key', random_bytes(32));
        $this->secretManager->writeSecrets(['db_host' => 'localhost']);
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
}
