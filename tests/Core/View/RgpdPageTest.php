<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Cookie\CookieConsentService;
use Core\Http\Controller\PageController;
use Core\Http\Request;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\SectionRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RgpdPageTest extends TestCase
{
    private PageController $controller;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('contact_email', 'test@example.com');
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
        $twig->addFunction(new \Twig\TwigFunction('editable', function (string $key, string $default = ''): string {
            return $default;
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));

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
        $pdo->exec("CREATE TABLE age_branches (id INTEGER PRIMARY KEY, desk_code TEXT, label TEXT, sort_order INTEGER)");
        $pdo->exec("CREATE TABLE sections (id INTEGER PRIMARY KEY, age_branch_id INTEGER, desk_code TEXT, name TEXT, email TEXT, created_at TEXT)");

        $repo = new EditableContentRepository($pdo);
        $editableService = new EditableContentService($repo);
        $twig->addGlobal('_editable_content_service', $editableService);
        $sectionRepo = new SectionRepository($pdo);
        $cookieService = new CookieConsentService([]);

        $this->controller = new PageController($twig, $editableService, $sectionRepo, $cookieService);
    }

    public function testRgpdPageIncludesDynamicCookieListSection(): void
    {
        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $this->controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Cookies utilisés', $body);
    }

    public function testRgpdPageShowsAllDeclaredCoreCookies(): void
    {
        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $this->controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('PHPSESSID', $body);
        $this->assertStringContainsString('_csrf_token', $body);
        $this->assertStringContainsString('cookie_consent', $body);
    }

    public function testRgpdPageContainsPreferencesLink(): void
    {
        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $this->controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('href="/cookies"', $body);
        $this->assertStringContainsString('préférences cookies', $body);
    }
}
