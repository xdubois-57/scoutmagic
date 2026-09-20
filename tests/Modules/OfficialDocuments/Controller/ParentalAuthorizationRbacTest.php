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
use Modules\OfficialDocuments\Controller\ParentalAuthorizationController;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Service\ParentalAuthorizationPdfService;
use Modules\OfficialDocuments\Service\ParentalAuthorizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The route floor, through the REAL Router and RBAC guard, against the
 * `role_min` each route declares in `module.json`.
 *
 * `ParentalAuthorizationControllerTest` is the other half and the more
 * interesting one — it proves the self-only rule the router cannot see. But
 * the two together are what AGENTS.md § Tests asks for on a new route
 * (« allowed at `role_min`, denied one level below »), and neither implies
 * the other: a controller that refuses everybody but a route left `public`
 * would pass that suite while offering an anonymous visitor a page it has
 * no business seeing.
 *
 * The roles come out of the manifest rather than being typed here, so a
 * route whose `role_min` is loosened one day fails this test instead of
 * quietly widening.
 */
final class ParentalAuthorizationRbacTest extends TestCase
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
     * @return array<string, array{string, string, string}>
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
            ];
        }

        return $routes;
    }

    /**
     * The floor itself: `identified`, and nothing looser. A parent has to be
     * signed in before the question « is this your child? » is even asked.
     */
    #[DataProvider('routeProvider')]
    public function testEveryRouteDeclaresTheIdentifiedFloor(string $method, string $path, string $roleMin): void
    {
        $this->assertSame('identified', $roleMin, $method . ' ' . $path);
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
    public function testAPublicVisitorIsStoppedByTheGuard(string $method, string $path, string $roleMin): void
    {
        AuthSession::logout();

        $response = $this->frontController($path, $method, $roleMin)
            ->handle(new Request($method, '/members/7/autorisation-parentale', [], [], [], []));

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
        string $roleMin
    ): void {
        if ($method === 'POST') {
            // A POST without a CSRF token is refused by the controller for a
            // different reason entirely, which would say nothing about the
            // guard. The GET case carries this one.
            $this->assertSame('identified', $roleMin);

            return;
        }

        AuthSession::login(1, 'parent@example.be', 'identified');

        $response = $this->frontController($path, $method, $roleMin)
            ->handle(new Request($method, '/members/7/autorisation-parentale', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode(), $method . ' ' . $path);
    }

    private function frontController(string $path, string $method, string $roleMin): FrontController
    {
        $router = new Router();
        $router->addRoute(
            $method,
            $path,
            ParentalAuthorizationController::class,
            $method === 'GET' ? 'show' : 'download',
            $roleMin
        );

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
