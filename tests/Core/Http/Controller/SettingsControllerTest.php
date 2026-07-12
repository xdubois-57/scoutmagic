<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SettingsController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class SettingsControllerTest extends TestCase
{
    private SettingsController $controller;
    private SettingService $settingService;
    private JournalService $journalService;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $settingRepo = new SettingRepository($this->pdo);
        $this->settingService = new SettingService($settingRepo);
        $journalRepo = new JournalRepository($this->pdo);
        $this->journalService = new JournalService($journalRepo);

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

        $this->controller = new SettingsController($twig, $this->settingService, $this->journalService);
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
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/settings/update', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
