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
use Core\Http\FlashMessage;
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\IcsCalendarStack;
use Tests\TestTwig;
use Tests\UsesProductionEngine;

/**
 * « Calendrier (fichier ICS) » through the real Router, RBAC guard,
 * controller and templates: the form of step 1, a refused source sent
 * back to it, then the shared preview with the import's own notes, the
 * confirmation and the report. DataMigrationControllerTest holds the
 * routes' RBAC and the 404 of an import whose module is off; this holds
 * the ICS import's screens.
 */
#[Group('database')]
final class IcsCalendarImportPageTest extends TestCase
{
    use UsesProductionEngine;

    private const PAGE = '/admin/migration-donnees/calendrier';

    private \PDO $pdo;
    private string $storage;
    private IcsCalendarStack $stack;
    private bool $calendarEnabled = true;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_ics_page_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $this->stack = new IcsCalendarStack(
            $this->pdo,
            $this->productionEngineSchemaConnection(),
            new EncryptionService(str_repeat('k', 32), str_repeat('b', 32))
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        AuthSession::login($this->stack->adminId, 'chef.unite@exemple.test', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testStepOneIsTheCalendarFormOfTheMockup(): void
    {
        $body = $this->page(self::PAGE);

        $this->assertStringContainsString('Calendrier (fichier ICS)', $body);
        $this->assertStringContainsString('Copie unique, pas un abonnement.', $body);
        $this->assertStringContainsString('action="' . self::PAGE . '/source"', $body);
        $option = '<option value="' . $this->stack->calendarId . '">Animateurs</option>';
        $this->assertStringContainsString($option, $body);
        $this->assertStringContainsString('Les événements récurrents ne sont pas importés', $body);
        $this->assertStringContainsString('Lire le calendrier', $body);
        $this->assertStringNotContainsString('id="data-migration-drop-zone"', $body);
    }

    public function testARefusedAddressComesBackToTheFormWithItsReason(): void
    {
        $response = $this->submit('https://agenda.exemple.test/absent.ics');

        $this->assertSame(302, $response->getStatusCode());
        $flash = FlashMessage::get() ?? $this->fail('No message for the form.');
        $this->assertSame('error', $flash['type']);
        $this->assertStringContainsString('Le calendrier n\'a pas pu être téléchargé.', $flash['message']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM data_migration_runs')->fetchColumn());
    }

    public function testTheCalendarIsReadPreviewedImportedAndReported(): void
    {
        $this->assertSame(302, $this->submit(IcsCalendarStack::ADDRESS)->getStatusCode());
        $this->slicesUntil('analysed');

        $preview = $this->page(self::PAGE);
        $this->assertStringContainsString('Le calendrier de agenda.exemple.test est valable.', $preview);
        $this->assertStringContainsString('7 événements ajoutés au calendrier « Animateurs ».', $preview);
        $this->assertStringContainsString('Les heures sont converties à l&#039;heure de Bruxelles.', $preview);
        $this->assertStringContainsString('1 événement antérieur à la date minimale : non importé.', $preview);
        $this->assertStringContainsString('Aucune notification, aucun rappel pour les événements passés.', $preview);
        $this->assertStringContainsString('Importer 7 événements', $preview);
        $this->assertStringNotContainsString('private-abc', $preview);

        $this->handle('POST', self::PAGE . '/confirmer', ['_csrf_token' => CsrfGuard::generateToken()]);
        $this->slicesUntil('done');

        $report = $this->page(self::PAGE);
        $this->assertStringContainsString('Import terminé le', $report);
        $this->assertStringContainsString('Antérieur au 11/11/2024 (date minimale)', $report);
        $this->assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn());
        $this->assertSame([], $this->stack->dispatched->getArrayCopy());
    }

    public function testTheImportDoesNotExistWhileTheCalendarModuleIsOff(): void
    {
        $this->calendarEnabled = false;

        $this->assertSame(404, $this->handle('GET', self::PAGE)->getStatusCode());
        $this->assertSame(404, $this->submit(IcsCalendarStack::ADDRESS)->getStatusCode());
    }

    private function submit(string $address): Response
    {
        return $this->handle('POST', self::PAGE . '/source', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'address' => $address,
            'calendar' => (string) $this->stack->calendarId,
            'min_date' => '2024-11-11',
        ]);
    }

    private function page(string $path): string
    {
        $response = $this->handle('GET', $path);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    private function slicesUntil(string $state): void
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $response = $this->handle('POST', self::PAGE . '/avancer', ['_csrf_token' => CsrfGuard::generateToken()]);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = json_decode((string) $response->getBody(), true);
            if ($data['state'] === $state) {
                return;
            }
        }
        $this->fail('The run never reached ' . $state . ': ' . json_encode($data));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handle(string $method, string $path, array $body = []): Response
    {
        $router = new Router();
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/data_migration/module.json');
        foreach ($manifest->routes as $route) {
            $router->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min']
            );
        }
        $configFile = sys_get_temp_dir() . '/dm_ics_config_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $twig = TestTwig::create(['data_migration']);
        foreach ([
            'site_name' => 'Test', 'is_authenticated' => true, 'current_user_email' => 'chef.unite@exemple.test',
            'current_user_role' => 'superadmin', 'config_mode' => false, 'cookie_consent_given' => true,
            'menus' => null, 'current_path' => '/', 'csp_nonce' => 'test-nonce',
        ] as $name => $value) {
            $twig->addGlobal($name, $value);
        }
        $frontController = new FrontController($router, $twig, new AppConfig($configFile));

        $registry = new ImporterRegistry(fn (string $module): bool => $module === 'calendar' && $this->calendarEnabled);
        $registry->add($this->stack->importer);
        $runs = new RunRepository($this->pdo, new EncryptionService(str_repeat('k', 32), str_repeat('b', 32)));
        $journal = new JournalService(new JournalRepository($this->pdo));
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
                ['calendar' => 'Calendrier']
            )
        );

        try {
            return $frontController->handle(new Request($method, $path, [], $body, [], []));
        } finally {
            @unlink($configFile);
        }
    }
}
