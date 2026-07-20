<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Config\SettingService;
use Core\Http\Controller\PageController;
use Core\Http\Request;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\SectionRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RgpdPageTest extends TestCase
{
    private Environment $twig;
    private EditableContentService $editableService;
    private SectionRepository $sectionRepo;
    private \DateTimeImmutable $defaultContentModifiedAt;

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
        $pdo->exec("CREATE TABLE sections (id INTEGER PRIMARY KEY, age_branch_id INTEGER, desk_code TEXT, name TEXT, email TEXT, is_visible INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT)");

        $repo = new EditableContentRepository($pdo);
        $editableService = new EditableContentService($repo);
        $twig->addGlobal('_editable_content_service', $editableService);
        $sectionRepo = new SectionRepository($pdo);

        $this->twig = $twig;
        $this->editableService = $editableService;
        $this->sectionRepo = $sectionRepo;
        $this->defaultContentModifiedAt = new \DateTimeImmutable('2026-01-01T10:30:00+00:00');
    }

    /**
     * @param 'default'|'custom'|'ai' $mode
     */
    private function makeController(string $mode): PageController
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn($mode);

        $rgpdContentService = $this->createMock(RgpdContentService::class);
        $rgpdContentService->method('getDefaultContent')->willReturn('<h2>Protection des données</h2><p><span id="rgpd-last-updated">Date de publication</span></p>');
        $rgpdContentService->method('getDefaultContentLastModified')->willReturn($this->defaultContentModifiedAt);

        return new PageController($this->twig, $this->editableService, $this->sectionRepo, $settingService, $rgpdContentService);
    }

    public function testRgpdPageContainsReferenceToCookiesPreferencesPage(): void
    {
        // Since the cookie consent refactor, the RGPD page no longer embeds
        // a dynamic cookie list — it links to the dedicated /cookies page.
        // The default content mock above doesn't include this link, so we
        // exercise the real default content here instead.
        $realRgpdContentService = new RgpdContentService(
            $this->createMock(\Core\Module\ModuleManager::class),
            $this->createMock(SettingService::class)
        );
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn('default');

        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $settingService, $realRgpdContentService);

        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('href="/cookies"', $body);
    }

    public function testDefaultModeUsesDefaultContentFileModificationDate(): void
    {
        $controller = $this->makeController('default');

        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('01/01/2026 10:30 UTC', $body);
    }

    public function testDefaultModeDoesNotUseTodaysDate(): void
    {
        // Regression test: the date must NOT be recomputed as "today" on
        // every page load — it must reflect the actual last content change.
        $controller = $this->makeController('default');

        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringNotContainsString(date('d/m/Y'), $body);
    }

    public function testCustomModeUsesActualContentChangeTimestamp(): void
    {
        $this->editableService->set(
            'rgpd.text',
            '<p><span id="rgpd-last-updated">Date de publication</span></p><p>Contenu personnalisé</p>',
            'rich_text',
            1
        );

        $controller = $this->makeController('custom');

        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Contenu personnalisé', $body);
        $this->assertStringNotContainsString('01/01/2026 10:30 UTC', $body);
    }

    public function testCustomModeFallsBackToDefaultDateWhenNoContentSaved(): void
    {
        $controller = $this->makeController('custom');

        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $controller->rgpd($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('01/01/2026 10:30 UTC', $body);
    }

    public function testSavingIdenticalContentDoesNotBumpLastUpdatedDate(): void
    {
        // Regression test: re-saving unchanged content (e.g. auto-save on
        // mode switch) must not shift the "last updated" date to now.
        $this->editableService->set('rgpd.text', '<p>Contenu stable</p>', 'rich_text', 1);
        $firstUpdate = $this->editableService->getLastUpdated('rgpd.text');

        sleep(1);
        $this->editableService->set('rgpd.text', '<p>Contenu stable</p>', 'rich_text', 1);
        $secondUpdate = $this->editableService->getLastUpdated('rgpd.text');

        $this->assertSame($firstUpdate, $secondUpdate);
    }
}
