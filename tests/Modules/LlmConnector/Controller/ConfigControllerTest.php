<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Controller;

use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Modules\LlmConnector\Controller\ConfigController;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\OcrModelSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private ProviderRepository $providerRepository;
    private ProviderModelRepository $modelRepository;
    private ConfigController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->providerRepository = new ProviderRepository($this->pdo, $encryption);
        $this->modelRepository = new ProviderModelRepository($this->pdo);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath(dirname(__DIR__, 4) . '/modules/llm_connector/views', 'llm_connector');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        foreach ([
            'site_name' => 'Test', 'is_authenticated' => true, 'current_user_role' => 'superadmin',
            'config_mode' => true, 'cookie_consent_given' => true, 'menus' => null, 'csp_nonce' => 'test-nonce',
        ] as $key => $value) {
            $twig->addGlobal($key, $value);
        }
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn() => ''));

        $this->controller = new ConfigController(
            $twig,
            $this->providerRepository,
            $this->modelRepository,
            new OcrModelSelector(),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function createLlmTables(): void
    {
        $this->pdo->exec('CREATE TABLE llm_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            driver TEXT NOT NULL,
            api_endpoint TEXT NOT NULL,
            api_key BLOB NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->pdo->exec('CREATE TABLE llm_provider_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            model_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            is_tier_cheap INTEGER NOT NULL DEFAULT 0,
            is_tier_capable INTEGER NOT NULL DEFAULT 0,
            is_tier_ocr INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    /**
     * Request::getRawBody() reads php://input directly, so the body has to be
     * supplied by a double. A stub rather than a mock: these tests assert on
     * the controller's response, never on how it consulted the request.
     *
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        return $this->createConfiguredStub(Request::class, [
            'getRawBody' => (string) json_encode($data),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manifestRoutes(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/llm_connector/module.json'),
            true
        );

        return $manifest['routes'];
    }

    // ---------------------------------------------------------------- RBAC

    /**
     * The routes handle API keys, so every one of them must be superadmin.
     * The guard itself is applied by the Router (never by the controller), so
     * the manifest declaration IS the boundary — pin it.
     */
    public function testEveryRouteIsDeclaredSuperadminOnly(): void
    {
        $routes = $this->manifestRoutes();

        $this->assertCount(3, $routes);
        foreach ($routes as $route) {
            $this->assertSame('superadmin', $route['role_min'], "Route {$route['path']} must be superadmin.");
        }
    }

    public function testAccessIsGrantedAtRoleMinAndDeniedOneLevelBelow(): void
    {
        $guard = new RbacGuard();

        AuthSession::login(1, 'super@example.org', Role::SUPERADMIN->value);
        foreach ($this->manifestRoutes() as $route) {
            $this->assertNull(
                $guard->enforce(Role::from($route['role_min'])),
                "Superadmin must reach {$route['path']}."
            );
        }

        // One level below superadmin is admin ("Chef d'Unité").
        AuthSession::login(2, 'admin@example.org', Role::ADMIN->value);
        foreach ($this->manifestRoutes() as $route) {
            $response = $guard->enforce(Role::from($route['role_min']));
            $this->assertNotNull($response, "Admin must be refused {$route['path']}.");
            $this->assertSame(403, $response->getStatusCode());
        }
    }

    public function testAnAnonymousVisitorIsRedirectedToLogin(): void
    {
        $response = (new RbacGuard())->enforce(Role::SUPERADMIN);

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
    }

    // --------------------------------------------------------------- index

    public function testIndexRendersWithNoProviderConfigured(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/llm', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Intelligence artificielle', $response->getBody());
    }

    public function testIndexShowsTheAssignedTierModels(): void
    {
        $providerId = $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepository->upsert($providerId, 'claude-haiku', 'Claude Haiku');
        $this->modelRepository->autoAssignTiers($providerId, ['cheap' => 'claude-haiku', 'capable' => null, 'ocr' => null]);

        $response = $this->controller->index(new Request('GET', '/config/llm', [], [], [], []), []);

        $this->assertStringContainsString('Claude Haiku', $response->getBody());
    }

    /**
     * Nothing enforced one-row-per-driver, and the page keys providers by
     * driver — a second row used to overwrite the first in the view while
     * findFirstActive() (id ASC) still picked the first. The page must show
     * the provider actually in use.
     */
    public function testIndexShowsTheProviderThatFindFirstActiveWouldPick(): void
    {
        $firstId = $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-first', true);
        $this->providerRepository->create('Anthropic bis', 'anthropic', 'https://api.anthropic.com', 'sk-second', false);
        $this->modelRepository->upsert($firstId, 'claude-haiku', 'Modèle du premier');
        $this->modelRepository->autoAssignTiers($firstId, ['cheap' => 'claude-haiku', 'capable' => null, 'ocr' => null]);

        $response = $this->controller->index(new Request('GET', '/config/llm', [], [], [], []), []);

        $this->assertStringContainsString('Modèle du premier', $response->getBody());
    }

    // --------------------------------------------------------- saveProvider

    public function testSaveProviderRejectsAMissingCsrfToken(): void
    {
        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                'name' => 'Anthropic', 'driver' => 'anthropic',
                'api_endpoint' => 'https://api.anthropic.com', 'api_key' => 'sk-test',
            ]),
            []
        );

        $this->assertFalse(json_decode($response->getBody(), true)['success']);
    }

    public function testSaveProviderCreatesAndActivatesANewProvider(): void
    {
        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Anthropic', 'driver' => 'anthropic',
                'api_endpoint' => 'https://api.anthropic.com', 'api_key' => 'sk-test',
            ]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $active = $this->providerRepository->findFirstActive();
        $this->assertNotNull($active);
        $this->assertSame('anthropic', $active['driver']);
        $this->assertSame('sk-test', $active['api_key']);
    }

    public function testSaveProviderRejectsAnUnknownDriver(): void
    {
        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Rogue', 'driver' => 'rogue',
                'api_endpoint' => 'https://example.org', 'api_key' => 'k',
            ]),
            []
        );

        $this->assertFalse(json_decode($response->getBody(), true)['success']);
    }

    /**
     * The endpoint is concatenated into a URL and handed to the HTTP layer,
     * so "not empty" was never enough — the posted value is whatever the
     * client chose to send, not what the dropdown offered.
     *
     */
    #[DataProvider('invalidEndpointProvider')]
    public function testSaveProviderRejectsANonHttpsEndpoint(string $endpoint): void
    {
        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Anthropic', 'driver' => 'anthropic',
                'api_endpoint' => $endpoint, 'api_key' => 'sk-test',
            ]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success'], "Endpoint '{$endpoint}' should be refused.");
        $this->assertNull($this->providerRepository->findFirstActive());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEndpointProvider(): array
    {
        return [
            'plain http' => ['http://api.anthropic.com'],
            'file scheme' => ['file:///etc/passwd'],
            'no scheme' => ['api.anthropic.com'],
            'garbage' => ['not a url at all'],
        ];
    }

    /**
     * The regression this route needed most: deactivateAll() used to run
     * before validation, so a refused save still switched every AI feature
     * on the site off — with an error message that implied nothing had
     * happened.
     */
    public function testARefusedSaveLeavesTheExistingProviderActive(): void
    {
        $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-existing', true);

        // A new provider for a different driver, with no API key: refused.
        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Mistral AI', 'driver' => 'mistral',
                'api_endpoint' => 'https://api.mistral.ai', 'api_key' => '',
            ]),
            []
        );

        $this->assertFalse(json_decode($response->getBody(), true)['success']);

        $active = $this->providerRepository->findFirstActive();
        $this->assertNotNull($active, 'The previously working provider must still be active.');
        $this->assertSame('anthropic', $active['driver']);
    }

    public function testASaveForAnUnknownProviderIdIsRefusedAndChangesNothing(): void
    {
        $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-existing', true);

        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'id' => 4242, 'name' => 'Mistral AI', 'driver' => 'mistral',
                'api_endpoint' => 'https://api.mistral.ai', 'api_key' => 'sk-new',
            ]),
            []
        );

        $this->assertFalse(json_decode($response->getBody(), true)['success']);

        $active = $this->providerRepository->findFirstActive();
        $this->assertNotNull($active);
        $this->assertSame('anthropic', $active['driver']);
    }

    public function testSavingAgainWithoutAnIdReusesTheDriverRowInsteadOfCreatingARival(): void
    {
        $existingId = $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-existing', true);

        $response = $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Anthropic', 'driver' => 'anthropic',
                'api_endpoint' => 'https://api.anthropic.com', 'api_key' => '',
            ]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame($existingId, $decoded['provider_id']);
        $this->assertCount(1, $this->providerRepository->findAll());
        // An empty key means "keep the existing one".
        $this->assertSame('sk-existing', $this->providerRepository->findById($existingId)['api_key']);
    }

    public function testSwitchingDriverLeavesExactlyOneActiveProvider(): void
    {
        $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Anthropic', 'driver' => 'anthropic',
                'api_endpoint' => 'https://api.anthropic.com', 'api_key' => 'sk-a',
            ]),
            []
        );
        $this->controller->saveProvider(
            $this->jsonRequest([
                '_csrf_token' => $this->csrfToken(), 'name' => 'Mistral AI', 'driver' => 'mistral',
                'api_endpoint' => 'https://api.mistral.ai', 'api_key' => 'sk-m',
            ]),
            []
        );

        $this->assertCount(1, $this->providerRepository->findAllActive());
        $this->assertSame('mistral', $this->providerRepository->findFirstActive()['driver']);
    }

    // -------------------------------------------------------- testConnection

    public function testTestConnectionRejectsAMissingCsrfToken(): void
    {
        $providerId = $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);

        $response = $this->controller->testConnection(
            $this->jsonRequest([]),
            ['id' => (string) $providerId]
        );

        $this->assertFalse(json_decode($response->getBody(), true)['success']);
    }

    public function testTestConnectionReportsAnUnknownProvider(): void
    {
        $response = $this->controller->testConnection(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => '999']
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('introuvable', $decoded['error']);
    }

    /**
     * A failed test-connection must come back as a clean JSON failure, never
     * as an uncaught exception — the page reads this with response.json(). An
     * internal endpoint (here loopback) is now refused up front by the SSRF
     * guard (audit M5) rather than being connected to, which is exactly the
     * clean-JSON outcome this test guards.
     */
    public function testTestConnectionReportsAnUnreachableEndpointAsJson(): void
    {
        $providerId = $this->providerRepository->create('Anthropic', 'anthropic', 'http://127.0.0.1:19', 'sk-test', true);

        $response = $this->controller->testConnection(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $providerId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('Point de terminaison invalide', $decoded['error']);
    }

    public function testTestConnectionRefusesAnInternalEndpointBeforeConnecting(): void
    {
        // An https loopback endpoint (SSRF target) must be refused by the guard
        // (audit M5) — the request never leaves the host.
        $providerId = $this->providerRepository->create('Anthropic', 'anthropic', 'https://127.0.0.1/v1', 'sk-test', true);

        $response = $this->controller->testConnection(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $providerId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('Point de terminaison invalide', $decoded['error']);
    }

    // --------------------------------------------------- ProviderRepository

    /**
     * The deactivate-then-activate pair must be atomic: a failure between the
     * two would otherwise leave the site with no active provider at all.
     */
    public function testTransactionalRollsBackEverythingWhenTheWorkThrows(): void
    {
        $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-a', true);

        try {
            $this->providerRepository->transactional(function (): void {
                $this->providerRepository->deactivateAll();
                throw new \RuntimeException('mid-flight failure');
            });
            $this->fail('The exception must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('mid-flight failure', $e->getMessage());
        }

        $active = $this->providerRepository->findFirstActive();
        $this->assertNotNull($active, 'deactivateAll() must have been rolled back.');
        $this->assertSame('anthropic', $active['driver']);
    }

    public function testTransactionalCommitsWhenTheWorkSucceeds(): void
    {
        $this->providerRepository->transactional(function (): void {
            $this->providerRepository->create('Mistral AI', 'mistral', 'https://api.mistral.ai', 'sk-m', true);
        });

        $this->assertCount(1, $this->providerRepository->findAll());
    }

    /**
     * A caller may already own a transaction; the helper must join it rather
     * than fail on a nested begin, leaving the commit to that caller.
     */
    public function testTransactionalJoinsAnAlreadyOpenTransaction(): void
    {
        $this->pdo->beginTransaction();

        $this->providerRepository->transactional(function (): void {
            $this->providerRepository->create('Scaleway', 'scaleway', 'https://api.scaleway.ai', 'sk-s', true);
        });

        $this->assertTrue($this->pdo->inTransaction(), 'The outer transaction must still be open.');
        $this->pdo->commit();

        $this->assertCount(1, $this->providerRepository->findAll());
    }

    public function testFindByDriverReturnsNullWhenNoRowExists(): void
    {
        $this->assertNull($this->providerRepository->findByDriver('anthropic'));
    }

    public function testFindByDriverReturnsTheLowestIdForThatDriver(): void
    {
        $firstId = $this->providerRepository->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-1', false);
        $this->providerRepository->create('Anthropic bis', 'anthropic', 'https://api.anthropic.com', 'sk-2', true);
        $this->providerRepository->create('Mistral AI', 'mistral', 'https://api.mistral.ai', 'sk-m', false);

        $found = $this->providerRepository->findByDriver('anthropic');

        $this->assertNotNull($found);
        $this->assertSame($firstId, $found['id']);
        $this->assertSame('mistral', $this->providerRepository->findByDriver('mistral')['driver']);
    }
}
