<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\PwaController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class PwaControllerTest extends TestCase
{
    private PwaController $controller;
    private SettingService $settingService;
    private string $storagePath;
    private string $defaultIconPath;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($pdo));
        $this->settingService->register('site_name', 'Unité des Bois Joyeux', 'text', 'x', 'x');
        $this->settingService->register('short_name', 'Bois Joyeux', 'text', 'x', 'x');
        $this->settingService->register('pwa_theme_color', '#123456', 'color', 'x', 'x');
        $this->settingService->register('pwa_background_color', '#abcdef', 'color', 'x', 'x');
        $this->settingService->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);

        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_pwa_ctrl_storage_' . uniqid();
        $this->defaultIconPath = sys_get_temp_dir() . '/scoutmagic_pwa_ctrl_defaults_' . uniqid();
        mkdir($this->defaultIconPath, 0755, true);

        $iconService = new UnitLogoService(
            new UnitLogoProcessor(),
            $this->settingService,
            $this->storagePath,
            $this->defaultIconPath
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Unité des Bois Joyeux');

        $this->controller = new PwaController($twig, $this->settingService, $iconService);
    }

    protected function tearDown(): void
    {
        foreach ([$this->storagePath, $this->defaultIconPath] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }
    }

    private function manifestJson(): array
    {
        $request = new Request('GET', '/manifest.webmanifest', [], [], [], []);
        $response = $this->controller->manifest($request, []);
        return json_decode($response->getBody(), true);
    }

    public function testManifestUsesSettingsValuesNeverHardcoded(): void
    {
        $manifest = $this->manifestJson();

        $this->assertSame('Unité des Bois Joyeux', $manifest['name']);
        $this->assertSame('Bois Joyeux', $manifest['short_name']);
        $this->assertSame('#123456', $manifest['theme_color']);
        $this->assertSame('#abcdef', $manifest['background_color']);
    }

    public function testManifestHasExpectedShape(): void
    {
        $manifest = $this->manifestJson();

        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/?source=pwa', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
    }

    public function testManifestIconsCarryTheCurrentVersionQueryString(): void
    {
        $manifest = $this->manifestJson();

        foreach ($manifest['icons'] as $icon) {
            $this->assertStringEndsWith('?v=1', $icon['src']);
        }
    }

    public function testManifestIconsAreOnlyTheThreeManifestSizesNotTheAppleTouchIcon(): void
    {
        $manifest = $this->manifestJson();

        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertCount(3, $manifest['icons']);
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);

        $maskable = array_values(array_filter($manifest['icons'], fn(array $i) => ($i['purpose'] ?? null) === 'maskable'));
        $this->assertCount(1, $maskable);
    }

    public function testManifestContentTypeAndCacheHeaders(): void
    {
        $request = new Request('GET', '/manifest.webmanifest', [], [], [], []);
        $response = $this->controller->manifest($request, []);

        $this->assertSame('application/manifest+json', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('public', $response->getHeaders()['Cache-Control']);
    }

    public function testManifestFallsBackToDefaultsWhenOptionalSettingsAreMissing(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settingService = new SettingService(new SettingRepository($pdo));
        $settingService->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);

        $iconService = new UnitLogoService(new UnitLogoProcessor(), $settingService, $this->storagePath, $this->defaultIconPath);
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $controller = new PwaController($twig, $settingService, $iconService);

        $request = new Request('GET', '/manifest.webmanifest', [], [], [], []);
        $manifest = json_decode($controller->manifest($request, [])->getBody(), true);

        $this->assertSame('Unité scoute', $manifest['name']);
        $this->assertSame('#0d6efd', $manifest['theme_color']);
        $this->assertSame('#ffffff', $manifest['background_color']);
    }

    public function testIconReturns404ForAnUnknownSize(): void
    {
        $request = new Request('GET', '/pwa/icon-999.png', [], [], [], []);
        $response = $this->controller->icon($request, ['size' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIconReturns404WhenNeitherOverrideNorShippedDefaultExists(): void
    {
        $request = new Request('GET', '/pwa/icon-192.png', [], [], [], []);
        $response = $this->controller->icon($request, ['size' => '192']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIconServesTheShippedDefaultWhenNoUploadOverrideExists(): void
    {
        file_put_contents($this->defaultIconPath . '/icon-192.png', 'shipped-default-bytes');

        $request = new Request('GET', '/pwa/icon-192.png', [], [], [], []);
        $response = $this->controller->icon($request, ['size' => '192']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('shipped-default-bytes', $response->getBody());
        $this->assertSame('image/png', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('immutable', $response->getHeaders()['Cache-Control']);
    }

    public function testIconServesUploadedOverrideInsteadOfShippedDefault(): void
    {
        file_put_contents($this->defaultIconPath . '/icon-512.png', 'shipped-default-bytes');
        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/icon-512.png', 'custom-uploaded-bytes');

        $request = new Request('GET', '/pwa/icon-512.png', [], [], [], []);
        $response = $this->controller->icon($request, ['size' => '512']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('custom-uploaded-bytes', $response->getBody());
    }

    /**
     * @dataProvider faviconAndFooterLogoSizesProvider
     */
    public function testIconServesTheNewFaviconAndFooterLogoSizes(string $size, string $filename): void
    {
        file_put_contents($this->defaultIconPath . '/' . $filename, 'default-bytes-for-' . $size);

        $request = new Request('GET', "/pwa/icon-{$size}.png", [], [], [], []);
        $response = $this->controller->icon($request, ['size' => $size]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('default-bytes-for-' . $size, $response->getBody());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function faviconAndFooterLogoSizesProvider(): array
    {
        return [
            'favicon 16' => ['16', 'favicon-16.png'],
            'favicon 32' => ['32', 'favicon-32.png'],
            'favicon 48' => ['48', 'favicon-48.png'],
            'footer logo 64' => ['64', 'logo-64.png'],
        ];
    }

    public function testFaviconReturns404WhenNeitherOverrideNorShippedDefaultExists(): void
    {
        $request = new Request('GET', '/favicon.ico', [], [], [], []);
        $response = $this->controller->favicon($request, []);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFaviconServesTheShippedDefaultIcoWithTheRightMimeType(): void
    {
        file_put_contents($this->defaultIconPath . '/favicon.ico', 'shipped-ico-bytes');

        $request = new Request('GET', '/favicon.ico', [], [], [], []);
        $response = $this->controller->favicon($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('shipped-ico-bytes', $response->getBody());
        $this->assertSame('image/x-icon', $response->getHeaders()['Content-Type']);
    }

    public function testFaviconServesUploadedOverrideInsteadOfShippedDefault(): void
    {
        file_put_contents($this->defaultIconPath . '/favicon.ico', 'shipped-ico-bytes');
        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/favicon.ico', 'custom-ico-bytes');

        $request = new Request('GET', '/favicon.ico', [], [], [], []);
        $response = $this->controller->favicon($request, []);

        $this->assertSame('custom-ico-bytes', $response->getBody());
    }

    /**
     * Unlike icon()'s year-long immutable cache, /favicon.ico has no
     * query-string versioning available (a browser's own implicit request
     * for it never carries one) — its Cache-Control must stay short enough
     * that a re-upload doesn't stay invisible on this legacy path for a
     * year.
     */
    public function testFaviconCacheControlIsShortNotImmutable(): void
    {
        file_put_contents($this->defaultIconPath . '/favicon.ico', 'bytes');

        $request = new Request('GET', '/favicon.ico', [], [], [], []);
        $response = $this->controller->favicon($request, []);

        $this->assertStringNotContainsString('immutable', $response->getHeaders()['Cache-Control']);
    }

    public function testOfflinePageRendersFrenchNoPersonalOrDynamicData(): void
    {
        $request = new Request('GET', '/offline', [], [], [], []);
        $response = $this->controller->offline($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        $this->assertStringNotContainsString('current_user', $body);
    }

    /**
     * Mirrors public/index.php's actual route registrations: role_min
     * "public" for all four PWA/favicon routes, so an unauthenticated
     * request must reach the controller (200/404 territory) rather than
     * being redirected to /login like every other route in the app
     * defaults to.
     */
    public function testManifestIconFaviconAndOfflineRoutesAreReachableWithoutAuthentication(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $router = new Router();
        $router->addRoute('GET', '/manifest.webmanifest', PwaController::class, 'manifest', 'public');
        $router->addRoute('GET', '/pwa/icon-{size}.png', PwaController::class, 'icon', 'public');
        $router->addRoute('GET', '/favicon.ico', PwaController::class, 'favicon', 'public');
        $router->addRoute('GET', '/offline', PwaController::class, 'offline', 'public');

        $fc = new FrontController($router, $this->createMock(Environment::class), $config);
        $fc->registerController(PwaController::class, $this->controller);

        $manifestResponse = $fc->handle(new Request('GET', '/manifest.webmanifest', [], [], [], []));
        $this->assertSame(200, $manifestResponse->getStatusCode());

        $iconResponse = $fc->handle(new Request('GET', '/pwa/icon-999.png', [], [], [], []));
        $this->assertSame(404, $iconResponse->getStatusCode(), 'unauthenticated request must reach the controller, not be redirected to /login');

        $faviconResponse = $fc->handle(new Request('GET', '/favicon.ico', [], [], [], []));
        $this->assertSame(404, $faviconResponse->getStatusCode(), 'unauthenticated request must reach the controller, not be redirected to /login');

        $offlineResponse = $fc->handle(new Request('GET', '/offline', [], [], [], []));
        $this->assertSame(200, $offlineResponse->getStatusCode());

        unlink($configFile);
    }
}
