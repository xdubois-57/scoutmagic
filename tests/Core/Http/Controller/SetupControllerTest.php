<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SetupController;
use Core\Http\Request;
use Core\Mail\DkimManager;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use Core\Security\SecretManager;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SetupControllerTest extends TestCase
{
    private string $tempDir;
    private SecretManager $secretManager;
    private DkimManager $dkimManager;
    private \Twig\Environment $twig;
    private string $schemaPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/setup_test_' . uniqid();
        mkdir($this->tempDir . '/keys', 0700, true);
        mkdir($this->tempDir . '/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->tempDir . '/keys/master.key',
            $this->tempDir . '/config/secrets.enc'
        );
        $this->dkimManager = new DkimManager($this->tempDir . '/keys');
        $this->schemaPath = dirname(__DIR__, 4) . '/schema/core.sql';

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = TwigFactory::create($templateDir, true);
        $this->twig->addGlobal('site_name', 'Test Unit');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // A known-empty session per test. session_destroy() in tearDown() ends
        // the session but does NOT clear the $_SESSION superglobal inside the
        // running process, so without this reset every test inherits whatever
        // the previous one left behind, and the file's behaviour depends on
        // execution order.
        $_SESSION = [];

        // Most tests here drive the wizard's actual work (save, test-db,
        // install-database, backup-and-empty-db, download-backup, ...), and
        // every one of those endpoints requires a verified installation token
        // while the site is uninitialized. Granting it by default is what the
        // gate tests already assume: they unset() it explicitly to assert the
        // refusal, which only means something if something set it first.
        $_SESSION['setup_token_verified'] = true;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    /**
     * Real UnitLogoService backed by an in-memory SQLite SettingService,
     * matching public/index.php's normal-boot wiring (setUnitLogoService()
     * is only ever called there once secrets.enc/the database exist —
     * this mirrors that state for the "is_initialized" tests below).
     */
    private function buildUnitLogoService(): UnitLogoService
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settingService = new SettingService(new SettingRepository($pdo));
        $settingService->register('pwa_background_color', '#ffffff', 'color', 'L', 'D');
        $settingService->register('pwa_icon_version', '1', 'number', 'L', 'D', null, null, null, false);

        $logoStoragePath = sys_get_temp_dir() . '/setup_ctrl_logo_' . uniqid();
        $defaultIconPath = sys_get_temp_dir() . '/setup_ctrl_logo_defaults_' . uniqid();
        mkdir($defaultIconPath, 0755, true);

        return new UnitLogoService(new UnitLogoProcessor(), $settingService, $logoStoragePath, $defaultIconPath);
    }

    private function solidPngBytes(int $side, int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor($side, $side);
        $color = imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    public function testIndexRendersSetupForm(): void
    {
        // Bypasses the token gate (bootstrap.php's token.php mechanism,
        // tested separately below) — this test is about the form itself.
        $_SESSION['setup_token_verified'] = true;

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Installation', $body);
        $this->assertStringContainsString('db_host', $body);
        $this->assertStringContainsString('db_name', $body);
        $this->assertStringContainsString('site_name', $body);
        $this->assertStringContainsString('mail_mode', $body);
        $this->assertStringContainsString('admin_email', $body);
        $this->assertStringContainsString('_csrf_token', $body);
    }

    /**
     * The FTP-uploaded installer only ever runs where DNS/hosting already
     * point, so the base URL is knowable from the request itself — no
     * reason to make the operator type it by hand. Still an ordinary
     * editable field, just pre-filled.
     */
    public function testIndexPrefillsBaseUrlFromRequestHost(): void
    {
        $_SESSION['setup_token_verified'] = true;

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup', [], [], [], [
            'HTTP_HOST' => 'www.unite-exemple.be',
            'HTTPS' => 'on',
        ]);

        $response = $controller->index($request, []);

        $this->assertStringContainsString('value="https://www.unite-exemple.be"', $response->getBody());
    }

    /**
     * The cron guidance card must show the real, absolute path to
     * public/cron.php — not a placeholder — whenever the controller knows
     * where it's running from, so the operator can copy-paste the
     * crontab line directly instead of having to work out the path
     * themselves.
     */
    public function testIndexShowsCronScriptPathWhenPublicDirIsKnown(): void
    {
        $_SESSION['setup_token_verified'] = true;

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, '/home/user/htdocs/public');
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $this->assertStringContainsString('/home/user/htdocs/public/cron.php', $response->getBody());
    }

    public function testIndexShowsTokenMissingScreenWhenNotInitializedAndNoTokenFile(): void
    {
        unset($_SESSION['setup_token_verified']);
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString("Jeton d'installation requis", $body);
        $this->assertStringNotContainsString('db_host', $body);
        // The example content shown is a well-formed token.php the operator
        // can literally copy-paste — it never generates/persists a "real"
        // secret server-side.
        $this->assertMatchesRegularExpression('/TOKEN:\s*[0-9a-f]{64}/', $body);
    }

    public function testIndexShowsTokenFormWhenTokenFileExistsAndNotYetVerified(): void
    {
        unset($_SESSION['setup_token_verified'], $_SESSION['setup_token_locked_until'], $_SESSION['setup_token_attempts']);
        file_put_contents($this->tempDir . '/token.php', "<?php /* TOKEN: " . str_repeat('a', 64) . " */\n");

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('name="token"', $body);
        $this->assertStringNotContainsString('db_host', $body);
    }

    public function testVerifyTokenGrantsAccessWithCorrectTokenThenIndexShowsForm(): void
    {
        unset($_SESSION['setup_token_verified'], $_SESSION['setup_token_locked_until'], $_SESSION['setup_token_attempts']);
        $realToken = str_repeat('b', 64);
        file_put_contents($this->tempDir . '/token.php', "<?php /* TOKEN: {$realToken} */\n");

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);
        $csrf = $this->issueCsrfToken();
        $request = new Request('POST', '/setup/verify-token', [], ['_csrf_token' => $csrf, 'token' => $realToken], [], []);

        $verifyResponse = $controller->verifyToken($request, []);
        $this->assertSame(302, $verifyResponse->getStatusCode());
        $this->assertTrue($_SESSION['setup_token_verified'] ?? false);

        $indexResponse = $controller->index(new Request('GET', '/setup', [], [], [], []), []);
        $this->assertStringContainsString('db_host', $indexResponse->getBody());
    }

    public function testVerifyTokenRejectsWrongTokenAndIncrementsAttempts(): void
    {
        unset($_SESSION['setup_token_verified'], $_SESSION['setup_token_locked_until'], $_SESSION['setup_token_attempts']);
        file_put_contents($this->tempDir . '/token.php', "<?php /* TOKEN: " . str_repeat('c', 64) . " */\n");

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);
        $csrf = $this->issueCsrfToken();
        $request = new Request('POST', '/setup/verify-token', [], ['_csrf_token' => $csrf, 'token' => 'wrong-value'], [], []);

        $controller->verifyToken($request, []);

        $this->assertNotTrue($_SESSION['setup_token_verified'] ?? false);
        $this->assertSame(1, $_SESSION['setup_token_attempts'] ?? 0);
    }

    /**
     * Hard stop after ~10 attempts — session-based since there is no
     * database yet at this point (Core\Security\LoginThrottler's pattern
     * doesn't apply pre-init).
     */
    public function testVerifyTokenLocksOutAfterTenFailedAttempts(): void
    {
        unset($_SESSION['setup_token_verified'], $_SESSION['setup_token_locked_until'], $_SESSION['setup_token_attempts']);
        file_put_contents($this->tempDir . '/token.php', "<?php /* TOKEN: " . str_repeat('d', 64) . " */\n");

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);

        for ($i = 0; $i < 10; $i++) {
            $csrf = $this->issueCsrfToken();
            $request = new Request('POST', '/setup/verify-token', [], ['_csrf_token' => $csrf, 'token' => 'still-wrong'], [], []);
            $controller->verifyToken($request, []);
        }

        $this->assertGreaterThan(time(), $_SESSION['setup_token_locked_until'] ?? 0);

        $response = $controller->index(new Request('GET', '/setup', [], [], [], []), []);
        $this->assertStringContainsString('Trop de tentatives', $response->getBody());
    }

    public function testIndexShowsConfigurationWhenInitialized(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_name' => 'test',
            'db_user' => 'root',
            'db_password' => 'pass',
            'site_name' => 'Mon Unité',
            'short_name' => '25SV',
            'base_url' => 'https://example.com',
            'mail_mode' => 'smtp',
            'smtp_host' => 'smtp.test.com',
            'smtp_port' => 587,
            'smtp_user' => 'user',
            'smtp_password' => 'pass',
            'mail_from_address' => 'a@b.com',
            'mail_from_name' => 'Test',
            'dkim_selector' => 'mail',
            'dmarc_report_email' => 'dmarc@b.com',
        ]);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Configuration du site', $body);
        $this->assertStringContainsString('Mon Unité', $body);
        // Admin section should appear with update wording
        $this->assertStringContainsString('admin_email', $body);
        $this->assertStringContainsString('Compte administrateur', $body);
    }

    /**
     * The single remaining activation point for the configuration mode
     * lives on the Espace chefs d'U page (config/general.html.twig) —
     * this page must not carry a second one anymore.
     */
    public function testIndexNoLongerShowsTheModeConfigurationCard(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['site_name' => 'Mon Unité']);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringNotContainsString('Mode configuration', $body);
        $this->assertStringNotContainsString('/config-mode/', $body);
    }

    public function testIndexOrdersParametresGenerauxCardBeforeBaseDeDonnees(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['site_name' => 'Mon Unité']);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup', [], [], [], []);

        $body = $controller->index($request, [])->getBody();

        $generalPos = strpos($body, 'Paramètres généraux');
        $dbPos = strpos($body, 'Base de données');
        $this->assertNotFalse($generalPos);
        $this->assertNotFalse($dbPos);
        $this->assertLessThan($dbPos, $generalPos);
    }

    public function testIndexShowsLogoUploadBlockWhenInitializedAndServiceWired(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['site_name' => 'Mon Unité']);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $controller->setUnitLogoService($this->buildUnitLogoService());
        $request = new Request('GET', '/setup', [], [], [], []);

        $body = $controller->index($request, [])->getBody();

        $this->assertStringContainsString('Logo de l\'unité', $body);
        $this->assertStringContainsString('context=unit_logo&return=/setup', $body);
        $this->assertStringNotContainsString('unit-logo-delete-btn', $body);
    }

    public function testIndexShowsDeleteAndNotifyIosButtonsOnceACustomLogoExists(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['site_name' => 'Mon Unité']);

        $unitLogoService = $this->buildUnitLogoService();
        $unitLogoService->storeUploadedLogo($this->solidPngBytes(400, 1, 2, 3), 'image/png');

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $controller->setUnitLogoService($unitLogoService);
        $request = new Request('GET', '/setup', [], [], [], []);

        $body = $controller->index($request, [])->getBody();

        $this->assertStringContainsString('unit-logo-delete-btn', $body);
        $this->assertStringContainsString('unit-logo-notify-ios-btn', $body);
    }

    /**
     * First-run safety (before secrets.enc exists): SetupController's
     * UnitLogoService is never wired in this state (public/index.php only
     * calls setUnitLogoService() from the normal-boot branch) — the logo
     * block must not render at all rather than link into an upload flow
     * that has no database/session to work against yet, and the page must
     * render without error.
     */
    public function testIndexHidesLogoUploadBlockWhenNotInitialized(): void
    {
        $_SESSION['setup_token_verified'] = true;

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup', [], [], [], []);

        $response = $controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Installation', $body);
        $this->assertStringNotContainsString('Logo de l\'unité', $body);
        $this->assertStringNotContainsString('context=unit_logo', $body);
    }

    public function testTestDatabaseReturnsJsonErrorWithInvalidCredentials(): void
    {
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/test-db', [], [
            'db_host' => 'invalid.invalid.host',
            'db_port' => '9999',
            'db_name' => 'nonexistent',
            'db_user' => 'nobody',
            'db_password' => 'wrong',
        ], [], []);

        $response = $controller->testDatabase($request, []);

        $json = json_decode($response->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertNotEmpty($json['message']);
    }

    /**
     * Regression: a database left over from a prior install (files
     * replaced, database untouched) generates a brand-new encryption key
     * against old encrypted rows — a guaranteed DecryptionException down
     * the line. testDatabase() must flag this so the wizard can warn
     * before install proceeds, rather than only failing much later.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testTestDatabaseReportsExistingTablesOnAFreshSetup(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $pdo->exec('CREATE TABLE leftover_table (id INT PRIMARY KEY)');

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $_POST['_csrf_token'] = \Core\Security\CsrfGuard::generateToken();
        $request = new Request('POST', '/setup/test-db', [], $params, [], []);

        $json = json_decode($controller->testDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success']);
        $this->assertTrue($json['has_existing_tables']);
        $this->assertSame(1, $json['table_count']);

        $this->dropAllTables($pdo);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testTestDatabaseReportsNoExistingTablesOnAnEmptyDatabase(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $this->dropAllTables($pdo);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $_POST['_csrf_token'] = \Core\Security\CsrfGuard::generateToken();
        $request = new Request('POST', '/setup/test-db', [], $params, [], []);

        $json = json_decode($controller->testDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success']);
        $this->assertFalse($json['has_existing_tables']);
        $this->assertSame(0, $json['table_count']);
    }

    /**
     * testDatabase() is also reused by the already-initialized "update
     * settings" flow, where the site's own database is never empty by
     * definition — has_existing_tables must not be reported there, or
     * every settings save would show a spurious "empty the database"
     * warning.
     */
    public function testTestDatabaseOmitsExistingTablesFlagWhenAlreadyInitialized(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['db_host' => 'x', 'db_port' => 3306, 'db_name' => 'x', 'db_user' => 'x', 'db_password' => 'x']);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/test-db', [], [
            'db_host' => 'invalid.invalid.host',
            'db_port' => '9999',
            'db_name' => 'nonexistent',
            'db_user' => 'nobody',
            'db_password' => 'wrong',
        ], [], []);

        $json = json_decode($controller->testDatabase($request, [])->getBody(), true);

        $this->assertArrayNotHasKey('has_existing_tables', $json);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testInstallDatabaseMigratesAGenuinelyEmptyDatabase(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $token = \Core\Security\CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request('POST', '/setup/install-database', [], array_merge($params, ['_csrf_token' => $token]), [], []);

        $json = json_decode($controller->installDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success'], $json['message'] ?? '');
        $this->assertFalse($json['has_existing_tables']);
        $this->assertTrue($json['migrated']);
        $this->assertGreaterThan(0, $json['statements_executed']);
        $this->assertGreaterThan(0, $json['table_count']);
        $this->assertContains('scout_years', $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));

        $this->dropAllTables($pdo);
    }

    /**
     * The whole point of checking emptiness first: installing straight
     * over a database with leftover data from a previous install would
     * generate a fresh encryption key against old encrypted rows.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testInstallDatabaseReportsExistingTablesWithoutMigrating(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $pdo->exec('CREATE TABLE leftover_table (id INT PRIMARY KEY)');

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $token = \Core\Security\CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request('POST', '/setup/install-database', [], array_merge($params, ['_csrf_token' => $token]), [], []);

        $json = json_decode($controller->installDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success'], $json['message'] ?? '');
        $this->assertTrue($json['has_existing_tables']);
        $this->assertSame(1, $json['table_count']);
        $this->assertArrayNotHasKey('migrated', $json);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['leftover_table'], $tables);

        $this->dropAllTables($pdo);
    }

    public function testInstallDatabaseRejectsWhenAlreadyInitialized(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['db_host' => 'x', 'db_port' => 3306, 'db_name' => 'x', 'db_user' => 'x', 'db_password' => 'x']);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $token = \Core\Security\CsrfGuard::generateToken();
        $request = new Request('POST', '/setup/install-database', [], ['_csrf_token' => $token], [], []);

        $response = $controller->installDatabase($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['success']);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testBackupAndEmptyDatabaseDumpsThenDropsEveryTable(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $pdo->exec('CREATE TABLE leftover_table (id INT PRIMARY KEY)');
        $pdo->exec('INSERT INTO leftover_table (id) VALUES (1)');

        $publicDir = $this->tempDir . '/public';
        mkdir($publicDir, 0755, true);
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $publicDir);
        $token = \Core\Security\CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request('POST', '/setup/backup-and-empty-db', [], array_merge($params, ['_csrf_token' => $token]), [], []);

        $json = json_decode($controller->backupAndEmptyDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success'], $json['message'] ?? '');
        $this->assertSame(1, $json['table_count']);
        $this->assertSame('/setup/download-backup', $json['download_url']);
        $this->assertEmpty($pdo->query('SHOW TABLES')->fetchAll());
        $this->assertNotEmpty($_SESSION['setup_backup_download'] ?? null);

        $dumpPath = $this->tempDir . '/storage/maintenance/' . $_SESSION['setup_backup_download'];
        $this->assertFileExists($dumpPath);
        $this->assertStringContainsString('leftover_table', (string) file_get_contents($dumpPath));
    }

    /**
     * Regression: a plain .sql dump is not restorable on its own — every
     * personal-data column in it is still an encrypted BLOB, and the key
     * that produced it is about to be replaced by a fresh one later in
     * this same setup flow. If master.key/secrets.enc exist alongside the
     * database being emptied, the download must be a zip bundling all
     * three, not just the bare dump.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testBackupAndEmptyDatabaseBundlesTheEncryptionKeyWhenPresent(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $pdo->exec('CREATE TABLE leftover_table (id INT PRIMARY KEY)');

        $publicDir = $this->tempDir . '/public';
        mkdir($publicDir, 0755, true);
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        mkdir($this->tempDir . '/storage/config', 0755, true);
        file_put_contents($this->tempDir . '/storage/keys/master.key', random_bytes(32));
        file_put_contents($this->tempDir . '/storage/config/secrets.enc', 'fake-encrypted-secrets');

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $publicDir);
        $token = \Core\Security\CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request('POST', '/setup/backup-and-empty-db', [], array_merge($params, ['_csrf_token' => $token]), [], []);

        $json = json_decode($controller->backupAndEmptyDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success'], $json['message'] ?? '');
        $filename = $_SESSION['setup_backup_download'];
        $this->assertStringEndsWith('.zip', $filename);

        $zip = new \ZipArchive();
        $zip->open($this->tempDir . '/storage/maintenance/' . $filename);
        $this->assertSame('fake-encrypted-secrets', $zip->getFromName('secrets.enc'));
        $this->assertNotFalse($zip->getFromName('master.key'));
        $this->assertStringContainsString('leftover_table', (string) $zip->getFromName('database.sql'));
        $zip->close();
    }

    /**
     * Regression: if the backup step fails (mysqldump unavailable, wrong
     * plugin, whatever) the operator must not be stuck unable to install
     * at all — force_without_backup=1 must always empty the database and
     * let install proceed, whether or not the backup itself succeeded.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testBackupAndEmptyDatabaseWithForceAlwaysEmptiesRegardlessOfBackupOutcome(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $pdo->exec('CREATE TABLE leftover_table (id INT PRIMARY KEY)');

        $publicDir = $this->tempDir . '/public';
        mkdir($publicDir, 0755, true);
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $publicDir);
        $token = \Core\Security\CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request('POST', '/setup/backup-and-empty-db', [], array_merge($params, [
            '_csrf_token' => $token,
            'force_without_backup' => '1',
        ]), [], []);

        $json = json_decode($controller->backupAndEmptyDatabase($request, [])->getBody(), true);

        $this->assertTrue($json['success'], $json['message'] ?? '');
        $this->assertSame(1, $json['table_count']);
        $this->assertEmpty($pdo->query('SHOW TABLES')->fetchAll());
    }

    public function testBackupAndEmptyDatabaseRejectsWhenAlreadyInitialized(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['db_host' => 'x', 'db_port' => 3306, 'db_name' => 'x', 'db_user' => 'x', 'db_password' => 'x']);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $token = \Core\Security\CsrfGuard::generateToken();
        $request = new Request('POST', '/setup/backup-and-empty-db', [], ['_csrf_token' => $token], [], []);

        $response = $controller->backupAndEmptyDatabase($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['success']);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testDownloadBackupServesTheDumpThenDeletesIt(): void
    {
        [$pdo, $params] = $this->connectToTestDatabase();
        $pdo->exec('CREATE TABLE leftover_table (id INT PRIMARY KEY)');

        $publicDir = $this->tempDir . '/public';
        mkdir($publicDir, 0755, true);
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $publicDir);
        $token = \Core\Security\CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $backupRequest = new Request('POST', '/setup/backup-and-empty-db', [], array_merge($params, ['_csrf_token' => $token]), [], []);
        $controller->backupAndEmptyDatabase($backupRequest, []);

        $dumpPath = $this->tempDir . '/storage/maintenance/' . $_SESSION['setup_backup_download'];

        $response = $controller->downloadBackup(new Request('GET', '/setup/download-backup', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('leftover_table', $response->getBody());
        $this->assertFileDoesNotExist($dumpPath);
        $this->assertArrayNotHasKey('setup_backup_download', $_SESSION);
    }

    public function testDownloadBackupReturns404WithoutAPendingBackup(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);

        $response = $controller->downloadBackup(new Request('GET', '/setup/download-backup', [], [], [], []), []);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * @return array{0: \PDO, 1: array<string, string>}
     */
    private function connectToTestDatabase(): array
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
            $pdo = new \PDO($dsn, $user, $password);
            $this->dropAllTables($pdo);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        return [$pdo, [
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbName,
            'db_user' => $user,
            'db_password' => $password,
        ]];
    }

    /**
     * The pre-init token gate has to cover the endpoints that actually DO
     * something, not just the wizard page.
     *
     * A CSRF token is no barrier to a stranger here: the gate screen itself
     * issues one to any anonymous visitor so its own form can post. Before
     * this, that was enough to walk straight past the gate into
     * /setup/save — configuring a freshly-deployed site with an attacker's
     * admin email and database, without ever knowing what is in token.php.
     */
    public function testSaveIsRefusedBeforeTheInstallationTokenIsVerified(): void
    {
        unset($_SESSION['setup_token_verified']);
        file_put_contents($this->tempDir . '/token.php', "<?php /* TOKEN: " . str_repeat('e', 64) . " */\n");

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);

        // A CSRF token obtained exactly the way the gate page hands one out.
        $csrf = $this->issueCsrfToken();
        $request = new Request('POST', '/setup/save', [], [
            '_csrf_token' => $csrf,
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => 'attacker_db',
            'db_user' => 'attacker',
            'db_password' => 'attacker',
            'site_name' => 'Pwned',
            'short_name' => 'PWN',
            'base_url' => 'https://example.com',
            'mail_mode' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_user' => 'u',
            'smtp_password' => 'p',
            'mail_from_address' => 'attacker@example.com',
            'mail_from_name' => 'A',
            'dkim_selector' => 's2026',
            'dmarc_report_email' => '',
            'admin_email' => 'attacker@example.com',
            'admin_password' => 'Attacker-Password-1!',
        ], [], []);

        $response = $controller->save($request, []);

        $this->assertSame(403, $response->getStatusCode());
        // Nothing was installed: no master key, no secrets file.
        $this->assertFalse($this->secretManager->isInitialized());
        $this->assertFileDoesNotExist($this->tempDir . '/keys/master.key');
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function gatedSetupEndpointProvider(): array
    {
        return [
            ['testDatabase'],
            ['installDatabase'],
            ['backupAndEmptyDatabase'],
            ['downloadBackup'],
            ['checkDns'],
            ['generateDkimKey'],
            ['testEmail'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gatedSetupEndpointProvider')]
    public function testEverySetupEndpointIsRefusedBeforeTheInstallationTokenIsVerified(string $action): void
    {
        unset($_SESSION['setup_token_verified']);
        file_put_contents($this->tempDir . '/token.php', "<?php /* TOKEN: " . str_repeat('f', 64) . " */\n");

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);
        $csrf = $this->issueCsrfToken();

        $request = new Request('POST', '/setup/' . $action, [
            'domain' => 'example.com',
            'selector' => 's2026',
        ], [
            '_csrf_token' => $csrf,
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => 'probe',
            'db_user' => 'probe',
            'db_password' => 'probe',
            'recipient' => 'attacker@example.com',
        ], [], []);

        $response = $controller->$action($request, []);

        $this->assertSame(403, $response->getStatusCode(), $action . ' must be gated');
        $this->assertFalse($this->secretManager->isInitialized());
    }

    /**
     * The gate is a PRE-initialization barrier only — token.php is deleted
     * once setup completes, and from then on /setup is an ordinary
     * superadmin-only page guarded by RBAC (see the routes in
     * public/index.php). It must not brick the configuration page.
     */
    public function testGatedEndpointStillWorksOnceTheSiteIsInitialized(): void
    {
        unset($_SESSION['setup_token_verified']);
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['db_host' => 'localhost']);
        $this->assertTrue($this->secretManager->isInitialized());

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath, $this->tempDir);
        $this->issueCsrfToken();
        $request = new Request('POST', '/setup/generate-dkim-key', [], [], [], []);

        $response = $controller->generateDkimKey($request, []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSaveRejectsInvalidCsrfToken(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/save', [], [
            '_csrf_token' => 'invalid_token',
        ], [], []);

        $response = $controller->save($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSaveRejectsInvalidData(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        // Generate a valid CSRF token first
        $token = \Core\Security\CsrfGuard::generateToken();

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/save', [], [
            '_csrf_token' => $token,
            'db_host' => '',
            'db_port' => '0',
            'db_name' => '',
            'db_user' => '',
            'db_password' => '',
            'site_name' => '',
            'short_name' => 'TOO_LONG_NAME',
            'base_url' => 'not a url',
            'mail_mode' => 'smtp',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_user' => '',
            'smtp_password' => '',
            'mail_from_address' => 'invalid',
            'mail_from_name' => '',
            'dkim_selector' => 'INVALID!',
            'dmarc_report_email' => 'invalid',
            'admin_email' => 'invalid',
            'admin_password' => 'short',
        ], [], []);

        $response = $controller->save($request, []);

        $body = $response->getBody();
        // Should show validation errors (re-render form)
        $this->assertStringContainsString('is-invalid', $body);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testSaveWithValidDataCreatesAllFiles(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        // Clean up tables from previous test runs
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
            $pdo = new \PDO($dsn, $user, $password);
            $this->dropAllTables($pdo);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        $token = \Core\Security\CsrfGuard::generateToken();

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/save', [], [
            '_csrf_token' => $token,
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbName,
            'db_user' => $user,
            'db_password' => $password,
            'site_name' => 'Test Unité',
            'short_name' => '25SV',
            'base_url' => 'https://test.example.com',
            'mail_mode' => 'local',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_user' => '',
            'smtp_password' => '',
            'mail_from_address' => 'unit@example.com',
            'mail_from_name' => 'Test Unité',
            'dkim_selector' => 'mail',
            'dmarc_report_email' => 'dmarc@example.com',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'securepassword123',
        ], [], []);

        $response = $controller->save($request, []);

        // Should redirect to home (302)
        $this->assertSame(302, $response->getStatusCode());

        // Check files created
        $this->assertFileExists($this->tempDir . '/keys/master.key');
        $this->assertSame(32, strlen(file_get_contents($this->tempDir . '/keys/master.key')));
        $this->assertFileExists($this->tempDir . '/config/secrets.enc');
        $this->assertFileExists($this->tempDir . '/keys/dkim/private.pem');

        // Check secrets are encrypted
        $rawSecrets = file_get_contents($this->tempDir . '/config/secrets.enc');
        $this->assertStringNotContainsString('admin@example.com', $rawSecrets);

        // VAPID keys (Web Push) generated at first-time setup, same as the
        // encryption keys.
        $secrets = $this->secretManager->readSecrets();
        $this->assertNotEmpty($secrets['vapid_public_key']);
        $this->assertNotEmpty($secrets['vapid_private_key']);

        // Check admin account exists in DB with password hash
        $stmt = $pdo->query('SELECT * FROM user_accounts WHERE is_super_admin = 1');
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($admin);
        $this->assertSame(1, (int) $admin['is_super_admin']);
        $this->assertNotNull($admin['password_hash']);
        $this->assertTrue(password_verify('securepassword123', $admin['password_hash']));

        // Cleanup
        $this->dropAllTables($pdo);
    }

    /**
     * Regression: a key generated ahead of time via the "Générer
     * maintenant" button (POST /setup/generate-dkim-key) used to make the
     * final save crash with "DKIM key already exists. Delete it first to
     * regenerate." — handleFirstTimeSetup() called generateKey()
     * unconditionally, not knowing a key might already be sitting there
     * from the earlier on-demand generation.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testSaveSucceedsWhenDkimKeyWasAlreadyGeneratedAheadOfTime(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
            $pdo = new \PDO($dsn, $user, $password);
            $this->dropAllTables($pdo);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        $preGeneratedPublicKey = $this->dkimManager->generateKey();

        $token = \Core\Security\CsrfGuard::generateToken();

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/save', [], [
            '_csrf_token' => $token,
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbName,
            'db_user' => $user,
            'db_password' => $password,
            'site_name' => 'Test Unité',
            'short_name' => '25SV',
            'base_url' => 'https://test.example.com',
            'mail_mode' => 'local',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_user' => '',
            'smtp_password' => '',
            'mail_from_address' => 'unit@example.com',
            'mail_from_name' => 'Test Unité',
            'dkim_selector' => 'mail',
            'dmarc_report_email' => 'dmarc@example.com',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'securepassword123',
        ], [], []);

        $response = $controller->save($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($preGeneratedPublicKey, $this->dkimManager->getPublicKey());

        $this->dropAllTables($pdo);
    }

    /**
     * The full schema this test's migration creates has FK relationships
     * beyond user_accounts/members/scout_years (e.g. editable_contents →
     * user_accounts) — a curated DROP list drifts out of sync as core.sql
     * grows, so drop unconditionally instead. Same SHOW TABLES +
     * FK-checks-off approach as Tests\Core\Database\MigrationRunnerTest and
     * Core\Maintenance\Task\FullResetHandler::truncateAllTables().
     */
    private function dropAllTables(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Regression for the bug fixed alongside public/index.php and
     * public/cron.php: mail_from_address (and mail_from_name,
     * dkim_selector) are migrated out of secrets.enc into the settings
     * table on any install past the one-time migration — secrets.enc's
     * own copy is then permanently empty/stale. Without merging the
     * settings-table value back in before MailServiceFactory::create(),
     * PHPMailer rejects the send outright with "Invalid address: (From): ",
     * which looks like a transport/SMTP problem but never even reaches
     * the transport.
     */
    public function testTestEmailMergesMailFromAddressFromSettingsWhenSecretsCopyIsEmpty(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([
            'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => 'test',
            'db_user' => 'root', 'db_password' => 'pass',
            'site_name' => 'Mon Unité',
            // Simulates a post-migration install: these are permanently
            // empty in secrets.enc, the settings table is now the source
            // of truth.
            'short_name' => '',
            'base_url' => 'https://example.com',
            'mail_mode' => 'local',
            'mail_from_address' => '',
            'mail_from_name' => '',
            'dkim_selector' => '',
            'dmarc_report_email' => '',
        ]);

        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo));
        $settingService->register('short_name', '25SV', 'text', 'x', 'x');
        $settingService->register('mail_from_address', 'unit@example.com', 'email', 'x', 'x');
        $settingService->register('mail_from_name', 'Mon Unité', 'text', 'x', 'x');
        $settingService->register('dkim_selector', 'mail', 'text', 'x', 'x');

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $controller->setSettingService($settingService);

        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/setup/test-email', [], [
            '_csrf_token' => $token,
            'recipient' => 'someone@example.com',
        ], [], []);

        $response = $controller->testEmail($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertNotSame('Jeton CSRF invalide.', $decoded['message'] ?? null, 'test setup itself is broken, not the assertion below');
        // 'local' mode calls PHP's real mail() — whether that succeeds
        // depends on the test environment's MTA, which isn't what this
        // test cares about. What matters is that it got PAST PHPMailer's
        // own From-address validation, which throws synchronously before
        // any transport is touched — proving mail_from_address was
        // actually merged in from settings.
        $this->assertStringNotContainsString('Invalid address', (string) ($decoded['message'] ?? ''));
    }

    /**
     * Baseline proving the test above actually exercises the bug: with no
     * SettingService wired at all (pre-migration codepath, or a caller
     * that never calls setSettingService()), an empty secrets.enc
     * mail_from_address must still fail exactly the way the user's real
     * report did.
     */
    public function testTestEmailFailsWithInvalidAddressWhenNeitherSecretsNorSettingsHaveAFromAddress(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([
            'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => 'test',
            'db_user' => 'root', 'db_password' => 'pass',
            'site_name' => 'Mon Unité', 'short_name' => '', 'base_url' => 'https://example.com',
            'mail_mode' => 'local', 'mail_from_address' => '', 'mail_from_name' => '',
            'dkim_selector' => '', 'dmarc_report_email' => '',
        ]);

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        // Deliberately no setSettingService() call.

        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/setup/test-email', [], [
            '_csrf_token' => $token,
            'recipient' => 'someone@example.com',
        ], [], []);

        $response = $controller->testEmail($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('Invalid address', (string) $decoded['message']);
    }

    /**
     * Before this fix, testEmail() only worked once the site was already
     * initialized (it read persisted secrets, which don't exist yet
     * during first-time setup) — there was no way to test SMTP settings
     * from the setup wizard at all. It must now read straight from the
     * request body, the same "test the in-progress values, not what's
     * saved" approach as testDatabase().
     */
    public function testTestEmailUsesFormValuesDirectlyWhenNotYetInitialized(): void
    {
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        // Deliberately not initialized — no writeSecrets() call at all.

        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/setup/test-email', [], [
            '_csrf_token' => $token,
            'recipient' => 'someone@example.com',
            'mail_mode' => 'local',
            'mail_from_address' => 'unit@example.com',
            'mail_from_name' => 'Mon Unité',
            'short_name' => '25SV',
            'dkim_selector' => 's2026',
        ], [], []);

        $response = $controller->testEmail($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertNotSame(
            'Le site n\'est pas encore initialisé.',
            $decoded['message'] ?? null,
            'testEmail() must work before first-time setup is complete, using the values in the form'
        );
        // Same reasoning as testTestEmailMergesMailFromAddressFromSettingsWhenSecretsCopyIsEmpty:
        // getting past PHPMailer's synchronous From-address validation
        // proves mail_from_address was read from the request body.
        $this->assertStringNotContainsString('Invalid address', (string) ($decoded['message'] ?? ''));
    }

    public function testGenerateDkimKeyRejectsInvalidCsrfToken(): void
    {
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/generate-dkim-key', [], [], [], []);

        $response = $controller->generateDkimKey($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->dkimManager->hasKey());
    }

    public function testGenerateDkimKeyCreatesKeyWhenNoneExists(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $this->issueCsrfToken();
        $request = new Request('POST', '/setup/generate-dkim-key', [], [], [], []);

        $response = $controller->generateDkimKey($request, []);
        $decoded = json_decode($response->getBody(), true);

        $this->assertTrue($decoded['success']);
        $this->assertNotEmpty($decoded['public_key']);
        $this->assertTrue($this->dkimManager->hasKey());
    }

    /**
     * Regeneration must stay an explicit, separate action (the existing
     * "Régénérer" checkbox + full form save) — this endpoint exists so an
     * operator can get the public key early, not to silently rotate it on
     * a second click.
     */
    public function testGenerateDkimKeyIsIdempotentWhenKeyAlreadyExists(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        $existingPublicKey = $this->dkimManager->generateKey();

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $this->issueCsrfToken();
        $request = new Request('POST', '/setup/generate-dkim-key', [], [], [], []);

        $response = $controller->generateDkimKey($request, []);
        $decoded = json_decode($response->getBody(), true);

        $this->assertTrue($decoded['success']);
        $this->assertSame($existingPublicKey, $decoded['public_key']);
    }

    public function testCheckDnsReturnsKeyMissingMarkerForDkimWhenNoKeyExists(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup/dns', [
            'domain' => 'example.com',
            'selector' => 's2026',
            'mode' => 'local',
        ], [], [], []);

        $response = $controller->checkDns($request, []);
        $decoded = json_decode($response->getBody(), true);

        $this->assertTrue($decoded['dkim']['key_missing']);
        $this->assertNull($decoded['dkim']['expected']);
        $this->assertFalse($decoded['dkim']['exists']);
    }

    public function testCheckDnsReturnsRealDkimExpectedValueOnceKeyExists(): void
    {
        // Past the pre-init token gate — this test is about what comes after it.
        $_SESSION['setup_token_verified'] = true;
        $publicKey = $this->dkimManager->generateKey();

        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('GET', '/setup/dns', [
            'domain' => 'example.com',
            'selector' => 's2026',
            'mode' => 'local',
        ], [], [], []);

        $response = $controller->checkDns($request, []);
        $decoded = json_decode($response->getBody(), true);

        $this->assertArrayNotHasKey('key_missing', $decoded['dkim']);
        $this->assertSame("v=DKIM1; k=rsa; p={$publicKey}", $decoded['dkim']['expected']);
    }

    public function testValidateFormDataAllowsEmptyDmarcReportEmail(): void
    {
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $method = new \ReflectionMethod(SetupController::class, 'validateFormData');
        $method->setAccessible(true);

        $data = $this->validFormData();
        $data['dmarc_report_email'] = '';

        $errors = $method->invoke($controller, $data, false);

        $this->assertArrayNotHasKey('dmarc_report_email', $errors);
    }

    public function testValidateFormDataStillRejectsAnInvalidNonEmptyDmarcReportEmail(): void
    {
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $method = new \ReflectionMethod(SetupController::class, 'validateFormData');
        $method->setAccessible(true);

        $data = $this->validFormData();
        $data['dmarc_report_email'] = 'not-an-email';

        $errors = $method->invoke($controller, $data, false);

        $this->assertArrayHasKey('dmarc_report_email', $errors);
    }

    /**
     * @return array<string, string>
     */
    private function validFormData(): array
    {
        return [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'db',
            'db_user' => 'user', 'db_password' => 'pass',
            'site_name' => 'Test Unit', 'short_name' => 'TU1', 'base_url' => 'https://example.com',
            'mail_mode' => 'local', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_user' => '', 'smtp_password' => '',
            'mail_from_address' => 'unit@example.com', 'mail_from_name' => 'Test Unit',
            'dkim_selector' => 's2026', 'dmarc_report_email' => 'dmarc@example.com',
            'admin_email' => 'admin@example.com', 'admin_password' => 'password123',
        ];
    }

    /**
     * CsrfGuard::validateRequest() reads the raw $_POST superglobal, not
     * the Request object's own body array — the Request constructor's
     * $body param alone (used everywhere else in this file for
     * ->getBody() reads) doesn't populate it.
     */
    private function issueCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token'] = $token;
        return $token;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
