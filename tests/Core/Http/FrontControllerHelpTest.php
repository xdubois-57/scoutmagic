<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\AppConfig;
use Core\Help\HelpPageLinkResolver;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\HelpTopicFileFixtures;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The `route_help` Twig global (ARCHITECTURE.md §8.64) — the help
 * counterpart of FrontControllerTest's route_breadcrumb tests, in its own
 * file so it can grow without touching that one.
 */
class FrontControllerHelpTest extends TestCase
{
    use HelpTopicFileFixtures;

    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTopicDirs();
    }

    private function frontController(?HelpService $helpService, bool $withPageLinks = false): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/covered-page', HelpStubController::class, 'index', 'public', ['label' => 'Page couverte', 'parents' => []]);
        $router->addRoute('POST', '/covered-page', HelpStubController::class, 'index', 'public');
        $router->addRoute('GET', '/other-page', HelpStubController::class, 'index', 'public', ['label' => 'Autre page', 'parents' => []]);

        $fc = new FrontController(
            $router,
            $this->twig,
            $this->config,
            null,
            null,
            $helpService,
            $withPageLinks ? new HelpPageLinkResolver($router) : null
        );
        $fc->registerController(HelpStubController::class, new HelpStubController($this->twig));

        return $fc;
    }

    private function helpServiceWithTopics(): HelpService
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-page', ['paths' => '/covered-page'], 'Contenu **rendu**.');
        $this->writeTopic($dir, 'sujet-chefs', ['paths' => '/covered-page', 'role_min' => 'chief']);

        return new HelpService(new HelpRegistry($dir));
    }

    private function renderedPageLinks(): string
    {
        return $this->twig->createTemplate(
            '{% for e in route_help %}{% if e.page_link %}{{ e.page_link.path }}:{{ e.page_link.label }}|{% endif %}{% endfor %}'
        )->render();
    }

    private function renderedRouteHelp(): string
    {
        return $this->twig->createTemplate(
            '{% for e in route_help %}{{ e.id }}:{{ e.html|raw }}|{% endfor %}'
        )->render();
    }

    public function testRouteHelpCarriesTheRenderedTopicsCoveringThePath(): void
    {
        $fc = $this->frontController($this->helpServiceWithTopics());

        $response = $fc->handle(new Request('GET', '/covered-page', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());

        $rendered = $this->renderedRouteHelp();
        $this->assertStringContainsString('sujet-page:', $rendered);
        $this->assertStringContainsString('Contenu <strong>rendu</strong>.', $rendered);
    }

    public function testRouteHelpIsFilteredByTheVisitorsCurrentRole(): void
    {
        // Anonymous visitor: the chief-only topic must not exist in the
        // global — the panel is server-rendered, so leaking it here would
        // put its full text in the page source.
        $fc = $this->frontController($this->helpServiceWithTopics());
        $fc->handle(new Request('GET', '/covered-page', [], [], [], []));

        $rendered = $this->renderedRouteHelp();
        $this->assertStringContainsString('sujet-page:', $rendered);
        $this->assertStringNotContainsString('sujet-chefs', $rendered);
    }

    public function testRouteHelpIsEmptyOnAPostRequest(): void
    {
        $fc = $this->frontController($this->helpServiceWithTopics());
        $fc->handle(new Request('POST', '/covered-page', [], [], [], []));

        $this->assertSame('', $this->renderedRouteHelp());
    }

    public function testAPanelTopicNeverLinksToThePageThePanelIsOpenOn(): void
    {
        // The panel opens ON the documented page almost every time, and
        // « aller sur la page » there is noise.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-page', ['paths' => '/covered-page']);
        $fc = $this->frontController(new HelpService(new HelpRegistry($dir)), true);

        $fc->handle(new Request('GET', '/covered-page', [], [], [], []));

        $this->assertSame('', $this->renderedPageLinks());
    }

    public function testAPanelTopicLinksToAnotherPageItDocuments(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-page', ['paths' => '/covered-page, /other-page']);
        $fc = $this->frontController(new HelpService(new HelpRegistry($dir)), true);

        $fc->handle(new Request('GET', '/covered-page', [], [], [], []));

        $this->assertSame('/other-page:Autre page|', $this->renderedPageLinks());
    }

    public function testRouteHelpIsEmptyWithoutAHelpService(): void
    {
        // Backward-compatible default — most FrontController call sites
        // in this suite never pass a 6th argument at all.
        $fc = $this->frontController(null);
        $fc->handle(new Request('GET', '/covered-page', [], [], [], []));

        $this->assertSame('', $this->renderedRouteHelp());
    }
}

class HelpStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('help-stub-ok', 200);
    }
}
