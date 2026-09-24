<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\TwigFactory;
use Modules\Documents\Controller\DocumentsAdminController;
use Modules\Documents\Controller\DocumentsPublicController;
use Modules\Documents\File\DirectLinkGrants;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper as H;
use Twig\Environment;

/**
 * Every route of the module through the REAL Router/RbacGuard pipeline at
 * the role_min module.json declares — allowed at that floor, refused one
 * level below — then what the two pages and the stable address say.
 */
final class DocumentsControllerTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;
    private Environment $twig;
    private DocumentService $service;
    private DocumentsPublicController $public;
    private DocumentsAdminController $admin;
    private int $documentId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->storage = H::storage();
        $this->service = H::service($this->pdo, $this->storage);
        $this->documentId = H::create($this->service, 'Règlement', DocumentVisibility::PUBLIC);

        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['documents' => $root . '/modules/documents/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addFunction(new \Twig\TwigFunction('param', static fn(string $key): string => 'Test Unit'));
        $this->twig = $twig;

        $this->public = new DocumentsPublicController($twig, $this->service, new DirectLinkGrants());
        $this->admin = new DocumentsAdminController($twig, $this->service, 'https://unite.example/');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        H::removeTree($this->storage);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/documents/module.json'),
            true
        );
        $cases = [];
        foreach ($manifest['routes'] as $route) {
            $cases[$route['method'] . ' ' . $route['path']] = [
                $route['method'],
                $route['path'],
                $route['action'],
                $route['role_min'],
            ];
        }

        return $cases;
    }

    /**
     * The routes that have a level below their floor.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function restrictedRoutes(): array
    {
        return array_filter(self::routes(), static fn(array $case): bool => $case[3] !== 'public');
    }

    #[DataProvider('routes')]
    public function testTheDeclaredFloorGetsThrough(string $method, string $path, string $action, string $floor): void
    {
        $this->loginAs($floor);

        $response = $this->handle($method, $path, $action, $floor, $this->resolve($path));

        $this->assertNotSame(403, $response->getStatusCode(), "{$floor} refused on {$method} {$path}");
        if ($method === 'GET') {
            $this->assertLessThan(400, $response->getStatusCode(), "{$method} {$path}: " . substr($response->getBody(), 0, 400));
        }
    }

    #[DataProvider('restrictedRoutes')]
    public function testOneLevelBelowIsRefused(string $method, string $path, string $action, string $floor): void
    {
        $below = ['admin' => 'chief'][$floor];
        $this->loginAs($below);

        $response = $this->handle($method, $path, $action, $floor, $this->resolve($path));

        // 403 exactly: a 302 would also be what guardCsrf() answers a POST
        // that got PAST the guard, and could hide a lowered role_min.
        $this->assertSame(403, $response->getStatusCode(), "{$below} reached {$method} {$path}");
    }

    /**
     * The whole matrix: five visibilities against every role a reader can
     * hold. An unlisted document is on nobody's list, not even the chef
     * d'unité's, and never counted as hidden.
     */
    public function testWhoSeesWhatOnThePublicPage(): void
    {
        $this->pdo->exec('DELETE FROM documents');
        foreach (DocumentVisibility::cases() as $visibility) {
            H::create($this->service, 'Doc ' . $visibility->value, $visibility);
        }

        $expected = [
            'public' => [['Doc public'], 3],
            'identified' => [['Doc public', 'Doc identified'], 2],
            'intendant' => [['Doc public', 'Doc identified'], 2],
            'chief' => [['Doc public', 'Doc identified', 'Doc chief'], 1],
            'admin' => [['Doc public', 'Doc identified', 'Doc chief', 'Doc admin'], 0],
        ];
        foreach ($expected as $role => [$titles, $hidden]) {
            $listing = $this->service->listFor(Role::from($role));
            $this->assertSame($titles, array_map(static fn($d) => $d->title, $listing['documents']), $role);
            $this->assertSame($hidden, $listing['hidden'], $role);
        }
    }

    public function testThePublicPageForAnAnonymousVisitor(): void
    {
        H::create($this->service, 'Liste des chefs', DocumentVisibility::CHIEF);
        H::create($this->service, 'PV secret', DocumentVisibility::DIRECT_LINK);

        $body = $this->handle('GET', '/documents', 'index', 'public', '/documents')->getBody();

        $this->assertStringContainsString('Règlement', $body);
        $this->assertStringContainsString('aria-label="Télécharger Règlement"', $body);
        $this->assertStringContainsString('href="/files/', $body);
        $this->assertStringNotContainsString('Liste des chefs', $body);
        $this->assertStringNotContainsString('PV secret', $body);
        $this->assertStringContainsString('D\'autres documents sont réservés aux membres de l\'unité', $body);
        // No pill on a public document.
        $this->assertStringNotContainsString('badge text-bg-light', $body);
    }

    public function testThePublicPageForAMemberSaysHowManyItKeepsBack(): void
    {
        H::create($this->service, 'Liste des chefs', DocumentVisibility::CHIEF);
        H::create($this->service, 'Budget', DocumentVisibility::ADMIN);
        H::create($this->service, 'Pour les membres', DocumentVisibility::IDENTIFIED);
        $this->loginAs('identified');

        $body = $this->handle('GET', '/documents', 'index', 'public', '/documents')->getBody();

        $this->assertStringContainsString('Pour les membres', $body);
        $this->assertStringContainsString('Membres connectés', $body);
        $this->assertStringContainsString('2 documents ne vous sont pas destinés', $body);
    }

    public function testAnEmptyPageOffersToSignIn(): void
    {
        $this->pdo->exec('DELETE FROM documents');

        $body = $this->handle('GET', '/documents', 'index', 'public', '/documents')->getBody();

        $this->assertStringContainsString('Aucun document public pour le moment', $body);
        $this->assertStringContainsString('href="/login"', $body);
    }

    public function testTheStableAddressRedirectsToTheCurrentFile(): void
    {
        $document = $this->service->findById($this->documentId);
        \assert($document !== null);

        $response = $this->handle('GET', '/documents/{slug}', 'open', 'public', '/documents/' . $document->slug);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/files/' . $document->fileId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);
        $this->assertArrayNotHasKey('X-Robots-Tag', $response->getHeaders());
    }

    /**
     * @return array<string, array{DocumentVisibility}>
     */
    public static function nonIndexable(): array
    {
        return [
            'identified' => [DocumentVisibility::IDENTIFIED],
            'chief' => [DocumentVisibility::CHIEF],
            'admin' => [DocumentVisibility::ADMIN],
            'direct_link' => [DocumentVisibility::DIRECT_LINK],
        ];
    }

    #[DataProvider('nonIndexable')]
    public function testOnlyAPublicDocumentMayBeIndexed(DocumentVisibility $visibility): void
    {
        $document = $this->service->findById(H::create($this->service, 'Autre', $visibility));
        \assert($document !== null);

        $response = $this->handle('GET', '/documents/{slug}', 'open', 'public', '/documents/' . $document->slug);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('noindex', $response->getHeaders()['X-Robots-Tag'] ?? null);
    }

    public function testTheAddressOfAnUnlistedDocumentOpensItForThisSession(): void
    {
        $document = $this->service->findById(H::create($this->service, 'PV AG', DocumentVisibility::DIRECT_LINK));
        \assert($document !== null);
        $grants = new DirectLinkGrants();
        $this->assertFalse($grants->has($document->id));

        $this->handle('GET', '/documents/{slug}', 'open', 'public', '/documents/' . $document->slug);

        $this->assertTrue($grants->has($document->id));
    }

    public function testAnUnknownAddressIsNotFound(): void
    {
        $response = $this->handle('GET', '/documents/{slug}', 'open', 'public', '/documents/inconnu');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheManagementPageShowsTheAddressToShare(): void
    {
        $this->loginAs('admin');

        $body = $this->handle('GET', '/admin/documents', 'index', 'admin', '/admin/documents')->getBody();

        $this->assertStringContainsString('https://unite.example/documents/reglement', $body);
        $this->assertStringContainsString('aria-label="Modifier « Règlement »"', $body);
        $this->assertStringContainsString('Qui voit quoi', $body);
        // The list editor's drag, chevrons and delete are these two
        // scripts; the partial does not load them itself.
        $this->assertStringContainsString('/assets/js/sortable.js', $body);
        $this->assertStringContainsString('/assets/js/list-editor.js', $body);
    }

    public function testTheEditFormWarnsOnlyAboutReplacingTheFile(): void
    {
        $this->loginAs('admin');

        $body = $this->handle('GET', '/admin/documents/{id}/modifier', 'editForm', 'admin', '/admin/documents/' . $this->documentId . '/modifier')->getBody();

        $this->assertStringContainsString('Remplacer le fichier (facultatif)', $body);
        $this->assertMatchesRegularExpression('/<output class="alert alert-warning small d-none d-block" id="document-replace-warning">/', $body);
        $this->assertStringContainsString('Lien direct', $body);
        $this->assertStringContainsString('/assets/js/documents-form.js', $body);
    }

    public function testAnUnknownDocumentCannotBeEdited(): void
    {
        $this->loginAs('admin');

        $response = $this->handle('GET', '/admin/documents/{id}/modifier', 'editForm', 'admin', '/admin/documents/999/modifier');

        $this->assertSame(404, $response->getStatusCode());
    }

    private function loginAs(string $role): void
    {
        if ($role !== 'public') {
            AuthSession::login(1, 'chef@test.be', $role);
        }
    }

    private function resolve(string $path): string
    {
        $document = $this->service->findById($this->documentId);
        \assert($document !== null);

        return str_replace(['{id}', '{slug}'], [(string) $document->id, $document->slug], $path);
    }

    private function handle(string $method, string $route, string $action, string $floor, string $path): Response
    {
        $class = str_starts_with($route, '/admin/') ? DocumentsAdminController::class : DocumentsPublicController::class;
        $router = new Router();
        $router->addRoute($method, $route, $class, $action, $floor);

        $configFile = sys_get_temp_dir() . '/test_documents_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, $this->twig, new AppConfig($configFile));
        $front->registerController($class, $class === DocumentsAdminController::class ? $this->admin : $this->public);

        return $front->handle(new Request($method, $path, [], [], [], []));
    }
}
