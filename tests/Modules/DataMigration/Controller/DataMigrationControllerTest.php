<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\File\ChunkedUploadStore;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManifest;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\DataMigration\Controller\DataMigrationController;
use Modules\DataMigration\Importer\ImporterRegistry;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\UploadStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\TestArchive;
use Tests\Modules\DataMigration\Support\TestFormImporter;
use Tests\Modules\DataMigration\Support\TestImporter;
use Tests\TestTwig;
use Tests\UsesProductionEngine;

/**
 * Every route of the module through the real Router, RBAC guard and
 * FrontController, and the real templates — the routes read from the
 * module's own manifest, so a route added there is exercised here.
 *
 * Three things are held: the RBAC boundary of each route (`superadmin`
 * in, an `admin` — a chef d'unité — refused: the page lives in
 * Configuration), an import whose module is off answering 404 at its
 * address AND at its AJAX endpoints, and the shared page drawing each of
 * the six steps as a run goes through them.
 */
#[Group('database')]
final class DataMigrationControllerTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private string $storage;
    /** @var array<string, bool> */
    private array $enabledModules = ['gallery' => false];

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        // The superadmin every test logs in as: runs record their author.
        $this->pdo->prepare('INSERT INTO user_accounts (id, email_encrypted, email_blind_index) VALUES (1, ?, ?)')
            ->execute(['x', str_repeat('a', 64)]);
        $this->storage = sys_get_temp_dir() . '/dm_ctrl_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_FILES = [];
        foreach (array_reverse(self::tree($this->storage)) as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->storage);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        $routes = [];
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/data_migration/module.json');
        foreach ($manifest->routes as $route) {
            $path = str_replace('{importer}', 'essai', $route['path']);
            $routes[$route['method'] . ' ' . $path] = [$route['method'], $path];
        }

        return $routes;
    }

    #[DataProvider('routes')]
    public function testAChefDUniteIsRefusedOnEveryRoute(string $method, string $path): void
    {
        AuthSession::login(2, 'chef@test.be', 'admin');

        $response = $this->handle($method, $path, ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
    }

    #[DataProvider('routes')]
    public function testAnAnonymousVisitorIsSentToTheLoginPage(string $method, string $path): void
    {
        $response = $this->handle($method, $path);

        $this->assertSame(302, $response->getStatusCode(), $method . ' ' . $path);
        $this->assertStringStartsWith('/login', (string) $response->getHeaders()['Location']);
    }

    #[DataProvider('routes')]
    public function testASuperadminIsLetThroughEveryRoute(string $method, string $path): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $response = $this->handle($method, $path, ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->assertNotSame(403, $response->getStatusCode(), $method . ' ' . $path);
        $this->assertStringStartsNotWith('/login', (string) ($response->getHeaders()['Location'] ?? ''), $method . ' ' . $path);
        if ($method === 'GET') {
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    public function testTheHomePageListsOnlyTheImportsWhoseModuleIsActive(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $body = (string) $this->handle('GET', '/config/migration-donnees')->getBody();

        $this->assertStringContainsString('Imports disponibles', $body);
        $this->assertStringContainsString('Éléments d&#039;essai', $body);
        $this->assertStringContainsString('Jamais lancé', $body);
        $this->assertStringNotContainsString('/config/migration-donnees/galeries', $body);

        $this->enabledModules['gallery'] = true;
        $body = (string) $this->handle('GET', '/config/migration-donnees')->getBody();
        $this->assertStringContainsString('/config/migration-donnees/galeries', $body);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inactiveModuleRoutes(): array
    {
        return [
            'the page' => ['GET', '/config/migration-donnees/galeries'],
            'the deposit (AJAX)' => ['POST', '/config/migration-donnees/galeries/depot'],
            'a slice (AJAX)' => ['POST', '/config/migration-donnees/galeries/avancer'],
            'the confirmation' => ['POST', '/config/migration-donnees/galeries/confirmer'],
            'an import that does not exist' => ['GET', '/config/migration-donnees/inconnu'],
        ];
    }

    #[DataProvider('inactiveModuleRoutes')]
    public function testAnImportWhoseModuleIsOffIsNotFoundByItsAddressNorByAjax(string $method, string $path): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $response = $this->handle($method, $path, ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
    }

    public function testASliceWithoutAValidTokenIsRefused(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $response = $this->handle('POST', '/config/migration-donnees/essai/avancer', ['_csrf_token' => 'faux']);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The six steps of the shared page, driven the way the browser does:
     * a deposit, slices, the confirmation with a year ticked, slices, the
     * report.
     */
    public function testTheSharedPageWalksThroughTheSixSteps(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
        $page = '/config/migration-donnees/essai';

        $body = $this->page($page);
        $this->assertStringContainsString('id="data-migration-drop-zone"', $body);
        $this->assertStringContainsString("Rien n'est écrit tant que vous n'avez pas confirmé", $body);

        $start = (int) substr(ScoutYearService::labelForDate(new \DateTimeImmutable()), 0, 4);
        $pastYear = sprintf('%d-%d', $start - 9, $start - 8);
        $this->deposit($page, TestArchive::items([
            ['id' => '1', 'name' => 'Un'],
            ['id' => '2', 'name' => 'Deux', 'year' => $pastYear],
            ['id' => '3', 'name' => ''],
        ]));

        $this->assertStringContainsString('Analyse du fichier', $this->page($page));

        $last = $this->slicesUntil($page, 'analysed');
        $this->assertSame('analysed', $last['state']);

        $body = $this->page($page);
        $this->assertStringContainsString('export.zip est valable.', $body);
        $this->assertStringContainsString('Années scoutes manquantes', $body);
        $this->assertStringContainsString('value="' . $pastYear . '"', $body);
        $this->assertStringContainsString('Importer 2 éléments', $body);
        $this->assertStringContainsString('2 éléments portent un nom.', $body);
        $this->assertStringContainsString('Sans nom', $body);

        $response = $this->handle('POST', $page . '/confirmer', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'years' => [$pastYear],
        ]);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('Import en cours', $this->page($page));

        $this->handle('POST', $page . '/avancer', ['_csrf_token' => CsrfGuard::generateToken()]);
        $this->handle('POST', $page . '/pause', ['_csrf_token' => CsrfGuard::generateToken()]);
        $this->assertStringContainsString("Reprendre l'import", $this->page($page));
        $this->assertStringContainsString(
            'Interrompu à',
            (string) $this->handle('GET', '/config/migration-donnees')->getBody()
        );

        $this->handle('POST', $page . '/reprendre', ['_csrf_token' => CsrfGuard::generateToken()]);
        $this->slicesUntil($page, 'done');

        $body = $this->page($page);
        $this->assertStringContainsString('Import terminé le', $body);
        $this->assertStringContainsString('Ignorés : données manquantes ou non reconnues', $body);
        $this->assertStringContainsString('Relancer le même fichier', $body);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM data_migration_items')->fetchColumn());
        $this->assertNotNull((new ScoutYearService($this->pdo))->findByLabel($pastYear));
    }

    public function testARejectedArchiveSaysWhyOnTheSourceStep(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
        $page = '/config/migration-donnees/essai';

        $this->deposit($page, TestArchive::build(['items.jsonl' => "{}\n"], ['format_version' => '3.0.0']));

        $body = $this->page($page);
        $this->assertStringContainsString("Ce fichier a été refusé. Rien n'a été importé.", $body);
        $this->assertStringContainsString('version 3.0.0', $body);
        $this->assertStringContainsString('id="data-migration-drop-zone"', $body);
    }

    /**
     * An import whose source is a form (lot B's shape) gets its own step 1
     * and then the shared steps, without the shared page knowing anything
     * about it.
     */
    public function testAFormSourceImportUsesItsOwnFirstStepAndTheSharedOthers(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
        $page = '/config/migration-donnees/formulaire';

        $body = $this->page($page);
        $this->assertStringContainsString('name="adresse"', $body);
        $this->assertStringNotContainsString('id="data-migration-drop-zone"', $body);

        $refused = $this->handle('POST', $page . '/source', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'adresse' => 'http://exemple.test/agenda.ics',
        ]);
        $this->assertSame(302, $refused->getStatusCode());

        $this->handle('POST', $page . '/source', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'adresse' => 'https://exemple.test/agenda.ics',
            'nombre' => '3',
        ]);
        $this->slicesUntil($page, 'analysed');
        $this->assertStringContainsString('Importer 3 événements', $this->page($page));
    }

    // --- harness --------------------------------------------------------

    private function page(string $path): string
    {
        $response = $this->handle('GET', $path);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    private function deposit(string $page, string $zip): void
    {
        $chunk = sys_get_temp_dir() . '/dm_ctrl_chunk_' . bin2hex(random_bytes(6));
        rename($zip, $chunk);
        $_FILES['file'] = [
            'name' => 'chunk',
            'type' => 'application/octet-stream',
            'tmp_name' => $chunk,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($chunk),
        ];

        try {
            $response = $this->handle('POST', $page . '/depot', [
                '_csrf_token' => CsrfGuard::generateToken(),
                'upload_id' => bin2hex(random_bytes(16)),
                'chunk_offset' => '0',
                'last' => '1',
                'file_name' => 'export.zip',
            ]);
            $data = json_decode((string) $response->getBody(), true);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $this->assertTrue($data['complete']);
        } finally {
            $_FILES = [];
            @unlink($chunk);
        }
    }

    /**
     * @return array<string, mixed> the last answer
     */
    private function slicesUntil(string $page, string $state): array
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $response = $this->handle('POST', $page . '/avancer', ['_csrf_token' => CsrfGuard::generateToken()]);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = json_decode((string) $response->getBody(), true);
            if ($data['state'] === $state) {
                return $data;
            }
        }
        $this->fail('The run never reached ' . $state . ': ' . json_encode($data));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handle(string $method, string $path, array $body = []): Response
    {
        return $this->frontController()->handle(new Request($method, $path, [], $body, [], []));
    }

    private function frontController(): FrontController
    {
        $router = new Router();
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/data_migration/module.json');
        foreach ($manifest->routes as $route) {
            $router->addRoute($route['method'], $route['path'], $route['controller'], $route['action'], $route['role_min']);
        }

        $configFile = sys_get_temp_dir() . '/dm_config_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $twig = TestTwig::withTemplates(
            [TestFormImporter::TEMPLATE => '<label for="adresse">Adresse</label>'
                . '<input id="adresse" name="adresse" value="{{ default_address }}">'
                . '<button type="submit" class="btn btn-primary">Lire la source</button>'],
            ['data_migration']
        );
        foreach ([
            'site_name' => 'Test', 'is_authenticated' => true, 'current_user_email' => 'superadmin@test.be',
            'current_user_role' => 'superadmin', 'config_mode' => false, 'cookie_consent_given' => true,
            'menus' => null, 'current_path' => '/', 'csp_nonce' => 'test-nonce',
        ] as $name => $value) {
            $twig->addGlobal($name, $value);
        }
        $frontController = new FrontController($router, $twig, new AppConfig($configFile));

        $registry = new ImporterRegistry(fn (string $module): bool => $this->enabledModules[$module] ?? false);
        $registry->add(new TestImporter($this->pdo, 'essai'));
        $registry->add(new TestImporter($this->pdo, 'galeries', 'gallery'));
        $registry->add(new TestFormImporter());

        $encryption = new EncryptionService(str_repeat('k', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $runs = new RunRepository($this->pdo, $encryption);
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo));

        $frontController->registerController(
            DataMigrationController::class,
            new DataMigrationController(
                $twig,
                $registry,
                new RunService(
                    $runs,
                    new ImportEngine(
                        $this->pdo,
                        $runs,
                        new ItemMappingRepository($this->pdo),
                        $years,
                        $uploads,
                        new ManifestReader(),
                        $journal,
                        0.0
                    ),
                    $years,
                    $uploads,
                    new ChunkedUploadStore($this->storage),
                    $journal
                ),
                ['gallery' => 'Photos']
            )
        );

        return $frontController;
    }

    /**
     * @return list<string>
     */
    private static function tree(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $entries[] = $entry->getPathname();
        }

        return $entries;
    }
}
