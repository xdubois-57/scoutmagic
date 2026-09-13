<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\OutboundMailController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Configuration > Courrier sortant — the page that decides where the
 * site's own mail leaves from (ARCHITECTURE.md §8.106).
 *
 * The RBAC floor is the first thing pinned, and it is read out of
 * `public/index.php` and fed to the real `RbacGuard` rather than
 * asserted against a string this test also wrote. « Chef d'unité » is a
 * role that reaches a great deal of this site; which relay carries the
 * sign-in links is not part of it — a chief who reordered the
 * authentication chain by accident could lock the whole unit out.
 *
 * @group database
 */
class OutboundMailControllerTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private OutboundMailController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);

        $twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates');
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/courrier-sortant');

        $settings = new SettingService(new SettingRepository($this->pdo));
        $connections = new ProviderConnections([]);
        $counters = new SendCounterRepository($this->pdo);
        $directory = new MailProviderDirectory($this->providers, $connections, $settings);

        $this->controller = new OutboundMailController(
            $twig,
            $directory,
            $this->chains,
            $counters,
            new TransportService(
                $this->providers,
                $this->chains,
                $counters,
                $connections,
                $directory,
                new JournalService(new JournalRepository($this->pdo))
            ),
            $settings
        );

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    // ── the RBAC floor ────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function outboundRoutes(): array
    {
        return [
            'the providers' => ['GET', '/config/courrier-sortant'],
            'the chains' => ['GET', '/config/courrier-sortant/acheminement'],
            'reordering a chain' => ['POST', '/config/courrier-sortant/acheminement/{lane}/ordre'],
            'enabling an entry' => ['POST', '/config/courrier-sortant/acheminement/{lane}/activation'],
            'the new-provider form' => ['GET', '/config/courrier-sortant/fournisseurs/nouveau'],
            'adding a provider' => ['POST', '/config/courrier-sortant/fournisseurs'],
            'one provider' => ['GET', '/config/courrier-sortant/fournisseurs/{id}'],
            'saving a provider' => ['POST', '/config/courrier-sortant/fournisseurs/{id}'],
            'deleting a provider' => ['POST', '/config/courrier-sortant/fournisseurs/{id}/suppression'],
        ];
    }

    #[DataProvider('outboundRoutes')]
    public function testEveryRouteIsRegisteredWithRoleMinSuperadmin(string $method, string $path): void
    {
        $this->assertSame('superadmin', self::registeredRoleMin($method, $path));
    }

    #[DataProvider('outboundRoutes')]
    public function testSuperadminIsAllowedThrough(string $method, string $path): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $this->assertNull((new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path))));
    }

    #[DataProvider('outboundRoutes')]
    public function testAdminIsRefused(string $method, string $path): void
    {
        AuthSession::login(2, 'chef-unite@test.be', 'admin');

        $response = (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path)));

        $this->assertNotNull($response, "A chief d'unité must not reach {$method} {$path}.");
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The route table lives in a procedural bootstrap no unit test loads,
     * so it is read at source — the same technique as
     * Tests\Core\Http\Controller\EmailTemplateControllerTest.
     */
    private static function registeredRoleMin(string $method, string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]' . $method . '[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,'
                . '[^;]*?OutboundMailController::class\s*,\s*[\'"][a-zA-Z]+[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/',
            $contents,
            $m
        );

        self::assertSame(1, $matched, "No addRoute registration found for {$method} {$path}");

        return $m[1];
    }

    // ── the pages render ──────────────────────────────────────────────

    public function testTheProvidersPageAlwaysShowsTheLocalSend(): void
    {
        $response = $this->controller->providers($this->getRequest(), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(MailProvider::LOCAL_NAME, (string) $response->getBody());
    }

    public function testTheChainsPageRendersTheThreeLanes(): void
    {
        $this->chains->append(MailLane::Authentication, MailProvider::LOCAL_ID, true);

        $body = (string) $this->controller->routing($this->getRequest(), [])->getBody();

        foreach (MailLane::ordered() as $lane) {
            $this->assertStringContainsString($lane->label(), $body);
        }
    }

    // ── the refusals reach the caller ─────────────────────────────────

    /**
     * The one refusal this page exists to make: emptying the
     * authentication lane would lock everybody out of the site, the
     * person doing it included. It comes back as a 409 carrying the
     * sentence, not as a silent no-op.
     */
    public function testEmptyingTheAuthenticationLaneIsRefusedWithAnExplanation(): void
    {
        $this->chains->append(MailLane::Authentication, MailProvider::LOCAL_ID, true);

        $response = $this->controller->toggle(
            $this->jsonRequest(['id' => MailProvider::LOCAL_ID, 'active' => false]),
            ['lane' => MailLane::Authentication->value]
        );

        $this->assertSame(409, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Authentification', $payload['error']);
        $this->assertSame(1, $this->chains->countEnabled(MailLane::Authentication), 'Nothing was changed.');
    }

    public function testAnUnknownLaneIsA404RatherThanASilentSuccess(): void
    {
        $response = $this->controller->toggle(
            $this->jsonRequest(['id' => 0, 'active' => false]),
            ['lane' => 'inventee']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAStaleCsrfTokenRefusesTheReordering(): void
    {
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'périmé';

        $request = new Request(
            'POST',
            '/config/courrier-sortant/acheminement/bulk/ordre',
            [],
            ['ids' => [0]],
            [],
            []
        );
        $response = $this->controller->reorder($request, ['lane' => MailLane::Bulk->value]);

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testOneProviderFormIs404ForAnIdThatDoesNotExist(): void
    {
        $this->assertSame(404, $this->controller->editForm($this->getRequest(), ['id' => '4242'])->getStatusCode());
    }

    /**
     * The local send has a form of its own — cadence only — because it
     * has no host, no credentials and, deliberately, no quota (D6).
     */
    public function testTheLocalSendHasACadenceFormAndNoQuotaField(): void
    {
        $body = (string) $this->controller->editForm($this->getRequest(), ['id' => '0'])->getBody();

        $this->assertStringContainsString('batch_interval_minutes', $body);
        $this->assertStringNotContainsString('name="daily_quota"', $body);
    }

    private function getRequest(): Request
    {
        return new Request('GET', '/config/courrier-sortant', [], [], [], []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request(
            'POST',
            '/config/courrier-sortant',
            [],
            $body + ['_csrf_token' => $token],
            [],
            []
        );
    }
}
