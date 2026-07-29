<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Http\Controller\MaintenanceController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class MaintenanceControllerTest extends TestCase
{
    private \PDO $pdo;
    private MaintenanceController $controller;
    private BackupRepository $backupRepository;
    private UpdateHistoryRepository $updateHistoryRepository;
    private SchedulerRepository $schedulerRepository;
    private SettingService $settingService;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->backupRepository = new BackupRepository($this->pdo);
        $this->updateHistoryRepository = new UpdateHistoryRepository($this->pdo);
        $fileRepository = new FileRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        foreach (['update_latest_version', 'update_checked_at', 'update_release_notes', 'update_release_html_url', 'update_download_url'] as $key) {
            $this->settingService->register($key, '', 'text', $key, $key);
        }
        $this->settingService->register('update_dependencies_changed', '0', 'boolean', 'update_dependencies_changed', 'update_dependencies_changed');
        $this->settingService->register('backup_auto_frequency', 'monthly', 'select', 'L', 'D', null, null, ['none', 'daily', 'weekly', 'biweekly', 'monthly']);
        $this->settingService->register('backup_auto_last_run', '', 'text', 'L', 'D');

        $connection = new Connection('127.0.0.1', 3306, 'nonexistent_db', 'nobody', '');
        $storagePath = sys_get_temp_dir() . '/maintenance_controller_test_' . uniqid();
        mkdir($storagePath, 0755, true);
        $backupService = new BackupService($connection, $storagePath, dirname($storagePath));

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn([]);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        $this->controller = new MaintenanceController(
            $this->twig, $backupService, $this->backupRepository, $fileRepository, $this->updateHistoryRepository, $schedulerService,
            $moduleManager, $encryption, $journalService, $this->settingService, $storagePath
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/maintenance/backup/full', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testIndexRendersEmptyState(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Maintenance', $response->getBody());
        $this->assertStringContainsString('Aucune sauvegarde', $response->getBody());
    }

    public function testDatabaseBackupButtonIsLabeledGenerer(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('Générer', $response->getBody());
    }

    /**
     * The restore dropdown and the recent-backups table must both show the
     * human-readable type label ("Base de données seule"), never the raw
     * internal type string ("database").
     */
    public function testBackupTypeIsShownAsAHumanReadableLabelEverywhere(): void
    {
        $id = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($id, 42, null);

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Base de données seule', $body);
        $this->assertStringNotContainsString('>database<', $body);
        $this->assertStringNotContainsString('>database —', $body);
    }

    public function testCreateDatabaseBackupValidatesCsrf(): void
    {
        $request = new Request('POST', '/config/maintenance/backup/database', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->createDatabaseBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->backupRepository->findRecent(5));
    }

    public function testCreateFullBackupValidatesCsrf(): void
    {
        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', 'password' => 'secret123', '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateFullBackupRejectsInvalidScope(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'not_a_scope', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateFullBackupRejectsGalleryScopeWhenModuleDisabled(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_with_gallery', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testCreateFullBackupRejectsEmptyPassword(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', 'password' => '', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testCreateFullBackupSchedulesTheBackgroundTaskAndReturnsBackupId(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['backup_id']);

        $backup = $this->backupRepository->findById($decoded['backup_id']);
        $this->assertNotNull($backup);
        $this->assertSame('pending', $backup->status);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup');
        $this->assertCount(1, $scheduled);
    }

    public function testBackupStatusReturns404ForUnknownId(): void
    {
        $response = $this->controller->backupStatus(new Request('GET', '/api/maintenance/backup-status/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testBackupStatusReturnsCurrentState(): void
    {
        $id = $this->backupRepository->create('database', 1);

        $response = $this->controller->backupStatus(new Request('GET', '/api/maintenance/backup-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('pending', $decoded['status']);
        $this->assertNull($decoded['download_url']);
    }

    public function testBackupStatusReturnsDownloadUrlWhenCompleted(): void
    {
        $id = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($id, 42, null);

        $response = $this->controller->backupStatus(new Request('GET', '/api/maintenance/backup-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('/files/42', $decoded['download_url']);
    }

    public function testIndexShowsUpdateAvailableWhenNewerVersionIsCached(): void
    {
        $this->settingService->setInternal('update_latest_version', '99.0.0');
        $this->settingService->setInternal('update_release_html_url', 'https://github.com/x/y/releases/tag/v99.0.0');

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('99.0.0', $response->getBody());
        $this->assertStringContainsString('Installer la mise à jour', $response->getBody());
    }

    public function testIndexHidesUpdateSectionWhenAlreadyUpToDate(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('Le site est à jour', $response->getBody());
    }

    public function testInstallUpdateValidatesCsrf(): void
    {
        $response = $this->controller->installUpdate($this->jsonRequest([
            '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInstallUpdateRejectsWhenNoUpdateIsAvailable(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->updateHistoryRepository->findRecent(5));
    }

    public function testInstallUpdateSchedulesTheBackgroundTaskAndReturnsHistoryId(): void
    {
        $this->settingService->setInternal('update_latest_version', '99.0.0');
        $this->settingService->setInternal('update_download_url', 'https://example.test/artifact.zip');
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['history_id']);

        $history = $this->updateHistoryRepository->findById($decoded['history_id']);
        $this->assertNotNull($history);
        $this->assertSame('pending', $history->status);
        $this->assertSame('99.0.0', $history->versionTo);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update');
        $this->assertCount(1, $scheduled);
    }

    public function testUpdateStatusReturns404ForUnknownId(): void
    {
        $response = $this->controller->updateStatus(new Request('GET', '/api/maintenance/update-status/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateStatusReturnsCurrentState(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, 1);

        $response = $this->controller->updateStatus(new Request('GET', '/api/maintenance/update-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('pending', $decoded['status']);
    }

    public function testUpdateAutoBackupFrequencyValidatesCsrf(): void
    {
        $response = $this->controller->updateAutoBackupFrequency($this->jsonRequest(['frequency' => 'weekly', '_csrf_token' => 'bad']), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateAutoBackupFrequencyRejectsInvalidValue(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateAutoBackupFrequency($this->jsonRequest(['frequency' => 'yearly', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->settingService->clearCache();
        $this->assertSame('monthly', $this->settingService->get('backup_auto_frequency'));
    }

    public function testUpdateAutoBackupFrequencySavesTheValue(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateAutoBackupFrequency($this->jsonRequest(['frequency' => 'weekly', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->settingService->clearCache();
        $this->assertSame('weekly', $this->settingService->get('backup_auto_frequency'));
    }

    public function testResetSettingsValidatesCsrf(): void
    {
        $response = $this->controller->resetSettings($this->jsonRequest(['confirm_keyword' => 'REINITIALISER', '_csrf_token' => 'bad']), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testResetSettingsRejectsWrongKeyword(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->resetSettings($this->jsonRequest(['confirm_keyword' => 'nope', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'reset_settings'));
    }

    public function testResetSettingsSchedulesTheBackgroundTask(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->resetSettings($this->jsonRequest(['confirm_keyword' => 'REINITIALISER', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['action_id']);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'reset_settings'));
    }

    public function testFullResetValidatesCsrf(): void
    {
        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testFullResetRejectsWrongKeyword(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'nope', 'confirm_checkbox' => true, '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testFullResetRejectsWithoutCheckbox(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => false, '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    public function testFullResetSchedulesTheBackgroundTask(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    public function testRestoreBackupValidatesCsrf(): void
    {
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => 'bad', 'confirm_keyword' => 'RESTAURER', 'source' => 'server', 'backup_id' => '1',
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testRestoreBackupRejectsWrongKeyword(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'nope', 'source' => 'server', 'backup_id' => '1',
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testRestoreBackupRejectsUnknownServerBackup(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'RESTAURER', 'source' => 'server', 'backup_id' => '999',
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testRestoreBackupSchedulesTheBackgroundTaskForAnExistingServerBackup(): void
    {
        $backupId = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($backupId, 1, 1);
        $token = $this->csrfToken();

        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'RESTAURER', 'source' => 'server', 'backup_id' => (string) $backupId,
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaders()['Location'] ?? '';
        $this->assertStringContainsString('restore_id=', $location);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testResetStatusReturns404ForUnknownId(): void
    {
        $response = $this->controller->resetStatus(new Request('GET', '/api/maintenance/reset-status/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testResetStatusReturnsCurrentState(): void
    {
        $actionId = $this->schedulerRepository->create('core', 'reset_settings', date('Y-m-d H:i:s'), null, null, null);

        $response = $this->controller->resetStatus(new Request('GET', '/api/maintenance/reset-status/' . $actionId, [], [], [], []), ['id' => (string) $actionId]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('pending', $decoded['status']);
    }

    /**
     * RBAC boundary: role_min admin — chief denied, admin allowed.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/maintenance', MaintenanceController::class, 'index', 'admin');

        $configFile = sys_get_temp_dir() . '/test_maintenance_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(MaintenanceController::class, $this->controller);

        return $fc;
    }

    public function testChiefIsDenied(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/maintenance', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminIsAllowed(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/config/maintenance', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}
