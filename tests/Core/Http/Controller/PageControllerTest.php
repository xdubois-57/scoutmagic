<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\PageController;
use Core\Http\Request;
use Core\Module\HomeBannerProvider;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\SectionRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class PageControllerTest extends TestCase
{
    private PageController $controller;
    private Environment $twig;
    private EditableContentService $editableService;
    private SectionRepository $sectionRepo;
    private SettingService $settingService;
    private RgpdContentService $rgpdContentService;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test';
        }));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));
        $twig->addFunction(new \Twig\TwigFunction('param', function (string $key): string {
            $params = ['contact_email' => 'test@example.com', 'site_name' => 'Test'];
            return $params[$key] ?? '';
        }));

        // Create mock editable content service
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            modified_at TEXT,
            modified_by INTEGER
        )");
        $repo = new EditableContentRepository($pdo);
        $editableService = new EditableContentService($repo);
        $twig->addGlobal('_editable_content_service', $editableService);

        $twig->addFunction(new \Twig\TwigFunction('editable', function (string $key, string $default = ''): string {
            return $default;
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));

        // Create mock section repository (no sections)
        $pdo->exec("CREATE TABLE age_branches (id INTEGER PRIMARY KEY, desk_code TEXT, label TEXT, sort_order INTEGER)");
        $pdo->exec("CREATE TABLE sections (id INTEGER PRIMARY KEY, age_branch_id INTEGER, desk_code TEXT, name TEXT, email TEXT, is_visible INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT)");
        $sectionRepo = new SectionRepository($pdo);

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn('default');

        $rgpdContentService = $this->createMock(RgpdContentService::class);
        $rgpdContentService->method('getDefaultContent')->willReturn('<h2>Protection des données</h2>');
        $rgpdContentService->method('getDefaultContentLastModified')->willReturn(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $this->twig = $twig;
        $this->editableService = $editableService;
        $this->sectionRepo = $sectionRepo;
        $this->settingService = $settingService;
        $this->rgpdContentService = $rgpdContentService;
        $this->controller = new PageController($twig, $editableService, $sectionRepo, $settingService, $rgpdContentService);
    }

    public function testHomePageRenders(): void
    {
        $request = new Request('GET', '/', [], [], [], []);
        $response = $this->controller->home($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Bienvenue', $response->getBody());
    }

    public function testHomePageRendersNoBannerWhenNoProviderWired(): void
    {
        // Banner module disabled — PageController's bannerProvider defaults
        // to null, and the homepage must render normally, without error.
        $request = new Request('GET', '/', [], [], [], []);
        $response = $this->controller->home($request, []);

        $this->assertStringNotContainsString('alert-info', $response->getBody());
    }

    public function testHomePageRendersBannerWhenProviderReturnsContent(): void
    {
        $provider = new class implements HomeBannerProvider {
            public function getRandomBannerHtml(): ?string
            {
                return '<p>Message important</p>';
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $provider);

        $request = new Request('GET', '/', [], [], [], []);
        $response = $controller->home($request, []);

        $this->assertStringContainsString('Message important', $response->getBody());
    }

    public function testHomePageRendersNothingWhenProviderReturnsNull(): void
    {
        $provider = new class implements HomeBannerProvider {
            public function getRandomBannerHtml(): ?string
            {
                return null;
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $provider);

        $request = new Request('GET', '/', [], [], [], []);
        $response = $controller->home($request, []);

        $this->assertStringNotContainsString('alert-info', $response->getBody());
    }

    public function testContactPageRenders(): void
    {
        $request = new Request('GET', '/contact', [], [], [], []);
        $response = $this->controller->contact($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Contact', $response->getBody());
    }

    public function testSectionsPageRendersEmptyState(): void
    {
        $request = new Request('GET', '/sections', [], [], [], []);
        $response = $this->controller->sections($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('premier import', $response->getBody());
    }

    public function testRgpdPageRenders(): void
    {
        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $this->controller->rgpd($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Protection des données', $response->getBody());
    }
}
