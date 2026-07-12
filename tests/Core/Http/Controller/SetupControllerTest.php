<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\SetupController;
use Core\Http\Request;
use Core\Mail\DkimManager;
use Core\Security\SecretManager;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

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
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testIndexRendersSetupForm(): void
    {
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
        // Admin section should not appear
        $this->assertStringNotContainsString('admin_email', $body);
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

    public function testSaveRejectsInvalidCsrfToken(): void
    {
        $controller = new SetupController($this->twig, $this->secretManager, $this->dkimManager, $this->schemaPath);
        $request = new Request('POST', '/setup/save', [], [
            '_csrf_token' => 'invalid_token',
        ], [], []);

        $response = $controller->save($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSaveRejectsInvalidData(): void
    {
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
        ], [], []);

        $response = $controller->save($request, []);

        $body = $response->getBody();
        // Should show validation errors (re-render form)
        $this->assertStringContainsString('is-invalid', $body);
    }

    /**
     * @group database
     */
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
            $pdo->exec('DROP TABLE IF EXISTS user_accounts');
            $pdo->exec('DROP TABLE IF EXISTS members');
            $pdo->exec('DROP TABLE IF EXISTS scout_years');
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

        // Check admin account exists in DB
        $stmt = $pdo->query('SELECT * FROM user_accounts WHERE is_super_admin = 1');
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($admin);
        $this->assertSame(1, (int) $admin['is_super_admin']);

        // Cleanup
        $pdo->exec('DROP TABLE IF EXISTS user_accounts');
        $pdo->exec('DROP TABLE IF EXISTS members');
        $pdo->exec('DROP TABLE IF EXISTS scout_years');
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
