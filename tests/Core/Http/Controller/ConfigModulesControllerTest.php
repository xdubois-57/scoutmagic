<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Controller\ConfigModulesController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ConfigModulesControllerTest extends TestCase
{
    private ConfigModulesController $controller;
    private ModuleManager $moduleManager;
    private ModuleRegistryRepository $registryRepo;
    private \PDO $pdo;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $fixturesDir = dirname(__DIR__, 3) . '/fixtures/modules';

        $settingRepo = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepo);
        $cookieConsentService = new CookieConsentService([]);
        $menuBuilder = new MenuBuilder(Role::fromString('admin'));
        $this->registryRepo = new ModuleRegistryRepository($this->pdo);
        $router = new Router();

        $migrationRunner = $this->createMock(MigrationRunner::class);
        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);

        $this->moduleManager = new ModuleManager(
            $fixturesDir,
            $settingService,
            $cookieConsentService,
            $menuBuilder,
            $this->registryRepo,
            $journalService,
            $router
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
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
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));

        $this->twig = $twig;
        $this->controller = new ConfigModulesController($twig, $this->moduleManager, $journalService);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testIndexRendersWithModuleList(): void
    {
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Modules', $response->getBody());
        // Should show valid module from fixtures
        $this->assertStringContainsString('Module de test valide', $response->getBody());
    }

    public function testIndexDoesNotRenderBadgesContent(): void
    {
        // The whole point of the split — no leftover badges markup on the
        // modules page.
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('badge-list', $response->getBody());
        $this->assertStringNotContainsString('Mode configuration', $response->getBody());
    }

    public function testToggleModuleActivates(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['enabled']);
    }

    public function testToggleModuleDeactivates(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        // First activate
        $this->registryRepo->upsert('valid_module', true, '1.0.0', 1);

        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => false,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertFalse($entry['enabled']);
    }

    public function testToggleModuleWithInvalidModuleReturnsError(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'invalid_module',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testToggleModuleWithInvalidCsrfReturns403(): void
    {
        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => true,
            '_csrf_token' => 'invalid',
        ]);
        $response = $this->controller->toggleModule($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }
    public function testIndexListsAModulesHardDependenciesByName(): void
    {
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        // dependent_module requires valid_module — the page names the
        // dependency by its manifest name, never by its id.
        $this->assertStringContainsString('Nécessite : Module de test valide', $body);
    }

    public function testIndexNamesAnUninstallableDependencyByIdWhenItIsNotOnDisk(): void
    {
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('Nécessite : not_on_disk_module', $body);
    }

    /**
     * **The page draws no control it cannot honour.**
     *
     * It used to embed `partials/list_editor.html.twig` for its
     * drag-to-reorder chrome. A module's position decides nothing any
     * more — every menu entry declares its own order — so the embed went
     * with the endpoint. That partial draws its drag handle and its
     * mobile move buttons unconditionally, so keeping it would have left
     * a handle that moves a row and saves nothing, which is worse than no
     * handle at all.
     */
    public function testIndexDrawsNoReorderingAffordance(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        foreach ([
            'list-editor-drag-handle',
            'list-editor-move-up',
            'list-editor-move-down',
            'data-reorder-url',
            '/config/modules/reorder',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $body, "The page still draws '{$gone}'.");
        }
    }

    /**
     * **The modules are drawn on titled shelves**, in the order
     * `ModuleManifest::CATEGORIES` declares them — not in the order the
     * directory happens to be scanned.
     */
    public function testIndexDrawsOneTitledShelfPerCategoryInUse(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('data-module-shelf="communication"', $body);
        $this->assertStringContainsString('data-module-shelf="argent"', $body);
        $this->assertStringContainsString('data-module-shelf="technique"', $body);

        // Declaration order, whatever order the modules were discovered in.
        $this->assertMatchesRegularExpression(
            '/data-module-shelf="communication".*data-module-shelf="argent".*data-module-shelf="technique"/s',
            $body
        );
    }

    /**
     * **A shelf nobody is on is not drawn at all.** None of these
     * fixtures declares « Services de l'unité », and a titled section
     * with nothing under it reads as something broken rather than as
     * something absent — the rule the mega-menu follows for an empty
     * column.
     */
    public function testIndexDrawsNoShelfForACategoryNoModuleDeclares(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('data-module-shelf="services"', $body);
        $this->assertStringNotContainsString("Services de l'unité", $body);
    }

    /**
     * Each row carries the server's answer about whether it is on, which
     * is what the filter reads. Client-side, so it must not invent the
     * state: a successful toggle reloads the page rather than patching
     * it, which keeps this attribute truthful.
     */
    public function testIndexMarksEachRowWithTheStateTheServerKnows(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('data-module-row', $body);
        $this->assertMatchesRegularExpression('/data-module-enabled="(yes|no)"/', $body);
    }

    /**
     * The three filter chips, each with its own count. The counts come
     * from the server rather than from JavaScript so they are right on
     * first paint and right without JavaScript at all.
     */
    public function testIndexOffersTheThreeFiltersWithTheirCounts(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('data-module-filter="all"', $body);
        $this->assertStringContainsString('data-module-filter="on"', $body);
        $this->assertStringContainsString('data-module-filter="off"', $body);

        $total = count($this->moduleManager->discoverModules());
        $this->assertStringContainsString("Tous ({$total})", $body);
    }

    /**
     * An installation with no module on disk has nothing to filter, so it
     * gets no chips and no « aucun module ne correspond » — only the
     * message that says why the list is empty. The chips used to render
     * anyway; clicking « Tous » then made the script find zero rows and
     * reveal its own empty message under the page's, two contradictory
     * explanations of the same nothing.
     */
    public function testIndexOffersNoFilterWhenThereIsNoModuleToFilter(): void
    {
        $emptyDir = sys_get_temp_dir() . '/scoutmagic_no_modules_' . uniqid();
        mkdir($emptyDir);
        try {
            $settingService = new SettingService(new SettingRepository($this->pdo));
            $journalService = new JournalService(new JournalRepository($this->pdo));
            $manager = new ModuleManager(
                $emptyDir,
                $settingService,
                new CookieConsentService([]),
                new MenuBuilder(Role::fromString('admin')),
                $this->registryRepo,
                $journalService,
                new Router()
            );
            $body = (new ConfigModulesController($this->twig, $manager, $journalService))
                ->index(new Request('GET', '/config/modules', [], [], [], []), [])
                ->getBody();
        } finally {
            rmdir($emptyDir);
        }

        $this->assertStringContainsString('Aucun module disponible', $body);
        $this->assertStringNotContainsString('data-module-filter', $body);
        $this->assertStringNotContainsString('data-module-empty', $body);
    }

    /**
     * **Deactivating a module keeps its data**, and the page says so.
     *
     * The sentence is the whole reason somebody dares touch a switch: an
     * intro that only says the pages disappear reads as a threat to
     * whatever is behind them.
     */
    public function testIndexPromisesThatDeactivatingKeepsTheData(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('données sont conservées', $body);
    }

    /**
     * And it keeps the one control it does honour: activation, as the
     * switch it has always been rather than a checkbox.
     */
    public function testIndexKeepsTheActivationSwitchForEveryModuleOnDisk(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('form-check form-switch', $body);
        $this->assertStringContainsString('class="form-check-input module-toggle"', $body);
        $this->assertStringContainsString('data-module="valid_module"', $body);
    }

    /**
     * The script that binds that switch is still loaded — and the one
     * that only ever drove the list chrome no longer is.
     */
    public function testIndexLoadsTheScriptThatBindsItsSwitchAndNoOther(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/modules', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('/assets/js/config-modules.js', $body);
        $this->assertStringNotContainsString('/assets/js/list-editor.js', $body);
        $this->assertStringNotContainsString('/assets/js/sortable.js', $body);
    }

    public function testIndexBlocksTheToggleOfAModuleWithUnmetRequirements(): void
    {
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('disabled', $this->toggleTagFor($body, 'dependent_module'));
        $this->assertStringContainsString('Activation impossible', $body);
    }

    public function testIndexLeavesTheToggleUsableOnceRequirementsAreMet(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', 1);

        $request = new Request('GET', '/config/modules', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringNotContainsString('disabled', $this->toggleTagFor($body, 'dependent_module'));
    }
    public function testToggleModuleReturnsTheRefusalMessageForAnUnmetRequirement(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'dependent_module',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        // A refusal, not a failure: Module\ModuleRefusalException IS a
        // Core\Exception\UserFacingException, so the sentence survives —
        // and with it the one thing that makes it actionable, the NAME of
        // the module standing in the way. The generic fallback would have
        // said "vérifiez que les modules dont il dépend sont activés",
        // leaving the admin to work out which.
        $this->assertStringContainsString('nécessite le module', $decoded['error']);
        $this->assertStringContainsString('Module de test valide', $decoded['error']);
        // Still no developer text: no manifest path, no English.
        $this->assertStringNotContainsString('manifest', $decoded['error']);
        $this->assertStringNotContainsString('/', $decoded['error']);
        $this->assertNull($this->registryRepo->findByModuleId('dependent_module'));
    }

    public function testToggleModuleReturnsTheRefusalMessageWhenADependentIsStillEnabled(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $this->registryRepo->upsert('valid_module', true, '1.0.0', 1);
        $this->registryRepo->upsert('dependent_module', true, '1.0.0', 1);

        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => false,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        // Same rule the other way round: the refusal names the dependent.
        $this->assertStringContainsString('est requis par le module', $decoded['error']);
        $this->assertStringContainsString('Module dépendant', $decoded['error']);
        $this->assertStringNotContainsString('/', $decoded['error']);
        $this->assertTrue($this->registryRepo->findByModuleId('valid_module')['enabled']);
    }

    /**
     * The leak this whole gate exists for: ModuleManifest::fromFile()
     * reports a missing manifest as "Module manifest not found:
     * /var/www/html/modules/x/module.json", and that string used to be
     * returned verbatim as the JSON `error` of this endpoint. A server
     * filesystem path is not something a chef d'unité's browser gets to
     * see — Core\Exception\UserFacingMessage substitutes the sentence the
     * controller wrote instead.
     */
    public function testToggleModuleNeverReturnsAFilesystemPathWhenTheManifestIsMissing(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'no_such_module_on_disk',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);

        $error = (string) $decoded['error'];
        $this->assertStringNotContainsString('module.json', $error);
        $this->assertStringNotContainsString('/', $error, 'No path separator may appear in a displayed message');
        $this->assertStringNotContainsString('Module manifest', $error);
        $this->assertStringContainsString('n\'a pas pu être activé', $error);
    }

    /**
     * The detail is not lost — it moves to the journal, where AGENTS.md
     * § Security checklist still governs it.
     */
    public function testToggleModuleJournalsTheRealReasonItRefusedToDisplay(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'no_such_module_on_disk',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $this->controller->toggleModule($request, []);

        $entry = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'module_activation_failed' ORDER BY id DESC LIMIT 1"
        )->fetch();

        $this->assertNotFalse($entry, 'The refusal must leave a journal entry');
        $this->assertStringContainsString('Module manifest not found', (string) $entry['context']);
    }

    private function toggleTagFor(string $body, string $moduleId): string
    {
        $matched = preg_match('/<input[^>]*data-module="' . preg_quote($moduleId, '/') . '"[^>]*>/', $body, $matches);
        $this->assertSame(1, $matched, "No toggle rendered for module '{$moduleId}'");

        return $matches[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/modules/toggle', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
