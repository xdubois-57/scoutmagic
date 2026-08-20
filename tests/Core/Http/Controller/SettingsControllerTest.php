<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SettingsController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class SettingsControllerTest extends TestCase
{
    private SettingsController $controller;
    private SettingService $settingService;
    private JournalService $journalService;
    private UnitLogoService $unitLogoService;
    private NotificationService $notificationService;
    private UserAccountRepository $userAccountRepository;
    private string $logoStoragePath;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $settingRepo = new SettingRepository($this->pdo);
        $this->settingService = new SettingService($settingRepo);
        $journalRepo = new JournalRepository($this->pdo);
        $this->journalService = new JournalService($journalRepo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $this->notificationService = new NotificationService(
            new NotificationRepository($this->pdo, $encryption),
            new PushSubscriptionRepository($this->pdo, $encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $this->settingService,
            $this->journalService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->userAccountRepository
        );

        $this->settingService->register('pwa_background_color', '#ffffff', 'color', 'L', 'D');
        $this->settingService->register('pwa_icon_version', '1', 'number', 'L', 'D', null, null, null, false);
        $this->logoStoragePath = sys_get_temp_dir() . '/scoutmagic_settings_ctrl_logo_' . uniqid();
        $defaultIconPath = sys_get_temp_dir() . '/scoutmagic_settings_ctrl_logo_defaults_' . uniqid();
        mkdir($defaultIconPath, 0755, true);
        $this->unitLogoService = new UnitLogoService(
            new UnitLogoProcessor(),
            $this->settingService,
            $this->logoStoragePath,
            $defaultIconPath
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => (string) ($this->settingService->get($k) ?? '')));

        $this->controller = new SettingsController(
            $twig,
            $this->settingService,
            $this->journalService,
            $this->unitLogoService,
            $this->notificationService,
            $this->userAccountRepository
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logoStoragePath)) {
            foreach (glob($this->logoStoragePath . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->logoStoragePath);
        }
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

    public function testIndexRendersPage(): void
    {
        $this->settingService->register('test_key', 'val', 'text', 'Test Key', 'A test setting');
        $this->settingService->clearCache();

        $request = new Request('GET', '/config/settings', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Paramètres', $response->getBody());
        $this->assertStringContainsString('Test Key', $response->getBody());
    }

    /**
     * The logo upload block moved to Configuration avancée's "Paramètres
     * généraux" card (SetupControllerTest covers its presence there) — it
     * must no longer appear on this page at all, custom logo or not.
     */
    public function testIndexNoLongerShowsTheLogoUploadBlock(): void
    {
        $request = new Request('GET', '/config/settings', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringNotContainsString('Logo de l\'unité', $body);
        $this->assertStringNotContainsString('context=unit_logo', $body);
        $this->assertStringNotContainsString('unit-logo-delete-btn', $body);
    }

    public function testIndexNoLongerShowsTheLogoUploadBlockEvenWithACustomLogo(): void
    {
        $this->unitLogoService->storeUploadedLogo($this->solidPngBytes(400, 1, 2, 3), 'image/png');

        $request = new Request('GET', '/config/settings', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('unit-logo-delete-btn', $response->getBody());
    }

    public function testIndexExcludesAutoUpdateSettingsFromTheGenericRendering(): void
    {
        $this->settingService->register('auto_update_enabled', '0', 'boolean', 'Mises à jour automatiques activées', 'D');
        $this->settingService->register('auto_update_level', 'patch', 'select', 'Niveau', 'D', null, null, ['patch', 'minor', 'major', 'dev']);
        $this->settingService->register('auto_update_day', 'monday', 'select', 'Jour', 'D', null, null, ['monday']);
        $this->settingService->register('auto_update_time', '03:00', 'text', 'Heure', 'D');
        $this->settingService->register('dev_update_branch', 'main', 'text', 'Branche', 'D');
        $this->settingService->register('an_ordinary_setting', 'val', 'text', 'Ordinaire', 'D');
        $this->settingService->clearCache();

        $request = new Request('GET', '/config/settings', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringNotContainsString('Mises à jour automatiques activées', $body);
        $this->assertStringContainsString('Ordinaire', $body);
    }

    public function testIndexExcludesStatisticsAndSupportSettingsFromTheGenericRendering(): void
    {
        $this->settingService->register('statistics_enabled', '1', 'boolean', 'Envoi automatique des statistiques d\'utilisation', 'D');
        $this->settingService->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'Destination des statistiques', 'D');
        $this->settingService->register('statistics_installation_id', '', 'text', 'Identifiant de cette installation', 'D', null, null, null, false);
        $this->settingService->register('support_email', 'support@scoutmagic.be', 'email', 'Adresse du support ScoutMagic', 'D', null, null, null, false);
        $this->settingService->register('installed_at', '', 'text', 'Date d\'installation de ScoutMagic', 'D', null, null, null, false);
        $this->settingService->register('an_ordinary_setting', 'val', 'text', 'Ordinaire', 'D');
        $this->settingService->clearCache();

        $request = new Request('GET', '/config/settings', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringNotContainsString('Envoi automatique des statistiques', $body);
        $this->assertStringNotContainsString('Destination des statistiques', $body);
        $this->assertStringNotContainsString('Identifiant de cette installation', $body);
        $this->assertStringNotContainsString('Adresse du support ScoutMagic', $body);
        $this->assertStringNotContainsString('Date d\'installation de ScoutMagic', $body);
        $this->assertStringContainsString('Ordinaire', $body);
    }

    public function testUpdateWithInvalidCsrf(): void
    {
        $this->settingService->register('editable', 'old', 'text', 'L', 'D');

        $request = $this->createJsonRequest(['key' => 'editable', 'value' => 'new', '_csrf_token' => 'invalid']);
        $response = $this->controller->update($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateWithValidCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->settingService->register('editable', 'old', 'text', 'L', 'D');
        $this->settingService->clearCache();

        $request = $this->createJsonRequest(['key' => 'editable', 'value' => 'new_value', '_csrf_token' => $token]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $this->settingService->clearCache();
        $this->assertSame('new_value', $this->settingService->get('editable'));
    }

    public function testUpdateNonEditableReturnsError(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->settingService->register('readonly', 'fixed', 'text', 'L', 'D', null, null, null, false);
        $this->settingService->clearCache();

        $request = $this->createJsonRequest(['key' => 'readonly', 'value' => 'hacked', '_csrf_token' => $token]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    /**
     * The real point of retaining the uploaded source (Core\Photo\
     * UnitLogoService::rederiveFromSource()): changing this one setting
     * must re-bake the maskable icon immediately, without asking the
     * superadmin to re-upload anything.
     */
    public function testUpdatingBackgroundColorRederivesTheMaskableIconFromTheRetainedSource(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->unitLogoService->storeUploadedLogo($this->solidPngBytes(400, 255, 0, 0), 'image/png');
        $versionAfterUpload = $this->unitLogoService->currentVersion();

        $request = $this->createJsonRequest(['key' => 'pwa_background_color', 'value' => '#00ff00', '_csrf_token' => $token]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertGreaterThan($versionAfterUpload, $this->unitLogoService->currentVersion());

        $maskable = $this->unitLogoService->resolveIconContent('512-maskable');
        $decodedImg = imagecreatefromstring($maskable);
        $corner = imagecolorsforindex($decodedImg, imagecolorat($decodedImg, 2, 2));
        $this->assertSame(0, $corner['red']);
        $this->assertSame(255, $corner['green']);
        $this->assertSame(0, $corner['blue']);
        imagedestroy($decodedImg);
    }

    public function testUpdatingAnUnrelatedSettingDoesNotTriggerRederivation(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $this->settingService->register('editable', 'old', 'text', 'L', 'D');
        $this->settingService->clearCache();

        $request = $this->createJsonRequest(['key' => 'editable', 'value' => 'new', '_csrf_token' => $token]);
        $this->controller->update($request, []);

        $this->assertSame(1, $this->unitLogoService->currentVersion());
    }

    public function testDeleteLogoRequiresValidCsrf(): void
    {
        $request = $this->createJsonRequest(['_csrf_token' => 'invalid'], '/config/settings/logo-delete');
        $response = $this->controller->deleteLogo($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteLogoRemovesTheCustomLogoAndJournalsIt(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->unitLogoService->storeUploadedLogo($this->solidPngBytes(400, 1, 2, 3), 'image/png');
        $this->assertTrue($this->unitLogoService->hasCustomLogo());

        $request = $this->createJsonRequest(['_csrf_token' => $token], '/config/settings/logo-delete');
        $response = $this->controller->deleteLogo($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->unitLogoService->hasCustomLogo());

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'unit_logo_deleted'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testNotifyIosLogoUpdateRequiresValidCsrf(): void
    {
        $request = $this->createJsonRequest(['_csrf_token' => 'invalid'], '/config/settings/logo-notify-ios');
        $response = $this->controller->notifyIosLogoUpdate($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testNotifyIosLogoUpdateRejectsWhenNoCustomLogoExists(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['_csrf_token' => $token], '/config/settings/logo-notify-ios');
        $response = $this->controller->notifyIosLogoUpdate($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    /**
     * Broadcasts to every user account (dispatch(), not the single-
     * recipient notify() — see the controller's own docblock) and
     * journals the action.
     */
    public function testNotifyIosLogoUpdateDispatchesToEveryAccountAndJournalsIt(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->unitLogoService->storeUploadedLogo($this->solidPngBytes(400, 1, 2, 3), 'image/png');

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc1', 'idx1']);
        $recipientId = (int) $this->pdo->lastInsertId();

        $request = $this->createJsonRequest(['_csrf_token' => $token], '/config/settings/logo-notify-ios');
        $response = $this->controller->notifyIosLogoUpdate($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $notifStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_account_id = ? AND type_id = 'core.unit_logo_updated_ios'"
        );
        $notifStmt->execute([$recipientId]);
        $this->assertSame(1, (int) $notifStmt->fetchColumn());

        $journalStmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'unit_logo_ios_notify_sent'");
        $this->assertSame(1, (int) $journalStmt->fetchColumn());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data, string $path = '/config/settings/update'): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
