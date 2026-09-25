<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\OfficialDocuments\Controller\HealthSheetController;
use Modules\OfficialDocuments\Controller\ParentalAuthorizationController;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Security\OwnMemberOnly;
use Modules\OfficialDocuments\Service\HealthSheetPdfService;
use Modules\OfficialDocuments\Service\HealthSheetService;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Service\ParentalAuthorizationPdfService;
use Modules\OfficialDocuments\Service\ParentalAuthorizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The route floor of EVERY route this module declares, through the REAL
 * Router and RBAC guard, against the `role_min` in `module.json`.
 *
 * The per-controller suites are the other half and the more interesting
 * one — they prove the self-only rule the router cannot see. But the two
 * together are what AGENTS.md § Tests asks for on a new route (« allowed at
 * `role_min`, denied one level below »), and neither implies the other: a
 * controller that refuses everybody but a route left `public` would pass
 * those suites while offering an anonymous visitor a page it has no
 * business seeing.
 *
 * **The routes come out of the manifest, not from a list here**, which is
 * what makes this file cover a route somebody adds next year without
 * anybody remembering to add it. It earned that the day the health sheet's
 * three routes landed: the provider picked them up on its own and the suite
 * went red until they were dispatched too.
 */
final class OfficialDocumentsRbacTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Every route this module declares, read from its manifest.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routeProvider(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/official_documents/module.json'),
            true
        );
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['routes']);

        $routes = [];
        foreach ($manifest['routes'] as $route) {
            $routes[$route['method'] . ' ' . $route['path']] = [
                (string) $route['method'],
                (string) $route['path'],
                (string) $route['role_min'],
                (string) $route['controller'],
                (string) $route['action'],
            ];
        }

        return $routes;
    }

    /**
     * The floor itself: `identified`, and nothing looser. A parent has to be
     * signed in before the question « is this your child? » is even asked.
     */
    #[DataProvider('routeProvider')]
    public function testEveryRouteDeclaresTheIdentifiedFloor(
        string $method,
        string $path,
        string $roleMin,
        string $controller,
        string $action
    ): void {
        $this->assertSame('identified', $roleMin, $method . ' ' . $path);
        // The manifest must also name something this module actually
        // ships: a typo there is a 500 on a real request, and the router
        // below would happily register it.
        $this->assertTrue(method_exists($controller, $action), $controller . '::' . $action);
    }

    /**
     * Denied one level below: a visitor who is not signed in never reaches
     * the controller at all.
     *
     * The exact answer is asserted rather than « not 200 », which would pass
     * for any reason at all — a routing typo included, and a typo is how a
     * route comes to be untested while looking tested.
     */
    #[DataProvider('routeProvider')]
    public function testAPublicVisitorIsStoppedByTheGuard(
        string $method,
        string $path,
        string $roleMin,
        string $controller,
        string $action
    ): void {
        AuthSession::logout();

        $response = $this->frontController($path, $method, $roleMin, $controller, $action)
            ->handle(new Request($method, self::urlFor($path), [], [], [], []));

        $this->assertSame(302, $response->getStatusCode(), $method . ' ' . $path);
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? null, $method . ' ' . $path);
        $this->assertStringNotContainsString('%PDF-', $response->getBody());
    }

    /**
     * Allowed at `role_min`: an identified account linked to this member
     * goes through the guard and reaches the screen. The link itself is
     * `ParentalAuthorizationControllerTest`'s business; what this says is
     * that the router does not stand in the way of somebody entitled to it.
     */
    #[DataProvider('routeProvider')]
    public function testAnIdentifiedMemberIsLetThroughToTheController(
        string $method,
        string $path,
        string $roleMin,
        string $controller,
        string $action
    ): void {
        AuthSession::login(1, 'parent@example.be', 'identified');

        // A POST is dispatched WITH a valid CSRF token rather than skipped.
        // This branch used to assert `role_min` and return, on the grounds
        // that a token-less POST is refused for a reason that says nothing
        // about the guard — true, and it left every writing route, the
        // document download included, with no proof that a legitimate
        // request reaches its controller at all. A routing typo or a CSRF
        // wiring mistake would have passed.
        $body = [];
        if ($method === 'POST') {
            $body['_csrf_token'] = self::csrfToken();
        }

        $response = $this->frontController($path, $method, $roleMin, $controller, $action)
            ->handle(new Request($method, self::urlFor($path), [], $body, [], []));

        // What this test is about: the guard did not stand in the way. The
        // exact answer belongs to each route — a save redirects, a document
        // comes back as bytes — and the per-controller suites assert those.
        $this->assertNotSame(403, $response->getStatusCode(), $method . ' ' . $path);
        $this->assertNotSame(
            '/login',
            $response->getHeaders()['Location'] ?? null,
            $method . ' ' . $path . ' — sent back to the login page although the account is signed in.'
        );

        if ($method === 'GET') {
            $this->assertSame(200, $response->getStatusCode(), $method . ' ' . $path);
        }

        // And the health sheet really hands back a document, through the
        // router rather than through a direct call to the controller —
        // which is the whole reason this branch stopped skipping POSTs.
        //
        // Only this one: the parental authorization needs dates the parent
        // types, so an empty body legitimately re-renders its form instead.
        // The health sheet has no required input at all — every field is
        // optional — so an empty request is a complete request, and a
        // document is the only honest answer to it.
        if ($method === 'POST' && str_ends_with($path, '/fiche-sante/pdf')) {
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertStringStartsWith('%PDF-', $response->getBody(), $path);
            $this->assertSame('application/pdf', $response->getHeaders()['Content-Type'] ?? null, $path);
        }
    }

    /**
     * A CSRF token this session will accept, so a POST route can be
     * dispatched for real.
     */
    private static function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    /**
     * A concrete URL for a declared route pattern — the only member id in
     * this file, so a route added later needs nothing here.
     */
    private static function urlFor(string $path): string
    {
        return str_replace('{id}', '7', $path);
    }

    private function frontController(
        string $path,
        string $method,
        string $roleMin,
        string $controller,
        string $action
    ): FrontController {
        $router = new Router();
        $router->addRoute($method, $path, $controller, $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_official_documents_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $memberService = $this->createStub(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn(self::profile());

        $accounts = $this->createStub(UserAccountRepository::class);
        $accounts->method('findById')->willReturn(new UserAccount(1, 'parent@example.be', 'Xavier', 'Dubois', null, false, null));

        $authorization = $this->createStub(ParentalAuthorizationService::class);
        $authorization->method('defaultPlaceFor')->willReturn('Verviers');

        $frontController = new FrontController($router, $twig, new AppConfig($configFile));

        // Both controllers are registered whichever route is under test:
        // the router only ever dispatches to the one the manifest named,
        // and this way a third controller added later needs one line.
        $frontController->registerController(
            ParentalAuthorizationController::class,
            new ParentalAuthorizationController(
                $twig,
                $memberService,
                $accounts,
                $authorization,
                new ParentalAuthorizationPdfService(TemplateLibrary::shipped()),
                null
            )
        );
        $frontController->registerController(
            HealthSheetController::class,
            new HealthSheetController(
                $twig,
                new OwnMemberOnly($memberService),
                new HealthSheetService($this->createStub(HealthSheetRepository::class)),
                new HealthSheetPdfService(TemplateLibrary::shipped())
            )
        );

        return $frontController;
    }

    private static function profile(): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 7,
            memberId: 42,
            deskId: 'D42',
            firstName: 'Loup',
            lastName: 'Dubois',
            totem: null,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [new MemberFunctionInfo('Animé', 'identified', 'Louveteaux', 'Meute', 'MEU', true, null, null)],
            scoutYearLabel: '2026-2027'
        );
    }
}
