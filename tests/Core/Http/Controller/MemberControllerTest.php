<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\MemberController;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @group database
 */
class MemberControllerTest extends TestCase
{
    private MemberController $controller;
    private \PDO $pdo;
    private int $scoutYearId;
    private string $testEmail = 'test@example.com';

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Create Twig
        $templateDir = dirname(__DIR__, 5) . '/core/View/templates';
        $twig = new Environment(new ArrayLoader(), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', $this->testEmail);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('current_path', '/members/1');
        $twig->addGlobal('config_mode', false);
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
        $twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));
        $twig->addFilter(new \Twig\TwigFilter('display_name', function ($member) {
            if ($member instanceof MemberProfile) {
                return $member->getDisplayName();
            }
            if (is_array($member)) {
                return $member['totem'] ?? $member['first_name'] ?? '?';
            }
            return (string) $member;
        }));

        $memberService = $this->createMock(MemberService::class);
        $this->controller = $this->newController($twig, $memberService);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function newController(Environment $twig, MemberService $memberService): MemberController
    {
        return new MemberController(
            $twig,
            $memberService,
            new MemberYearService(),
            $this->createMock(JournalService::class)
        );
    }

    /**
     * Real MemberProfile fixture — this DTO has no benefit from mocking (plain
     * readonly properties), and computeEffectiveAge() in the controller reads
     * birthDate/scoutYearOffset/scoutYearLabel directly, which a mock leaves
     * uninitialized.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeProfile(array $overrides = []): MemberProfile
    {
        $defaults = [
            'memberYearId' => 1,
            'memberId' => 1,
            'deskId' => 'T001',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'totem' => null,
            'quali' => null,
            'gender' => null,
            'birthDate' => null,
            'phone' => null,
            'mobile' => null,
            'email' => null,
            'patrol' => null,
            'formationLevel' => null,
            'federationMailConsent' => false,
            'unitMailConsent' => false,
            'addresses' => [],
            'functions' => [],
            'scoutYearLabel' => '2025-2026',
        ];

        $args = array_merge($defaults, $overrides);

        return new MemberProfile(...$args);
    }

    public function testShowPageRendersForLinkedMemberSelf(): void
    {
        $profile = $this->makeProfile(['totem' => 'Baloo']);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn($profile);

        $controller = $this->newController($this->createMock(Environment::class), $memberService);

        $request = new Request('GET', '/members/1', [], [], [], []);
        $response = $controller->show($request, ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowPageRendersForChiefViewingAnyMember(): void
    {
        // Set up as chief
        $_SESSION['auth'] = [
            'user_account_id' => 1,
            'email' => 'chief@example.com',
            'role' => 'chief',
            'linked_members' => [],
        ];

        $profile = $this->makeProfile(['totem' => 'Mowgli']);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn($profile);

        $controller = $this->newController($this->createMock(Environment::class), $memberService);

        $request = new Request('GET', '/members/2', [], [], [], []);
        $response = $controller->show($request, ['id' => '2']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowPageReturns403ForIdentifiedUserViewingSomeoneElsesMember(): void
    {
        // Mock member service to deny access
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(false);

        $controller = $this->newController($this->createMock(Environment::class), $memberService);

        $request = new Request('GET', '/members/999', [], [], [], []);
        $response = $controller->show($request, ['id' => '999']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden', $response->getBody());
    }

    public function testShowPageReturns404ForNonExistentMemberYear(): void
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')
            ->willThrowException(new MemberNotFoundException());

        $controller = $this->newController($this->createMock(Environment::class), $memberService);

        $request = new Request('GET', '/members/99999', [], [], [], []);
        $response = $controller->show($request, ['id' => '99999']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Member not found', $response->getBody());
    }

    public function testContactInfoIsVisibleToSelf(): void
    {
        $profile = $this->makeProfile(['totem' => 'Baloo']);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn($profile);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                'members/show.html.twig',
                $this->callback(function (array $context) {
                    return $context['show_contact'] === true;
                })
            )
            ->willReturn('<html></html>');

        $controller = $this->newController($twig, $memberService);

        $request = new Request('GET', '/members/1', [], [], [], []);
        $response = $controller->show($request, ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testContactInfoIsVisibleToChief(): void
    {
        // Set up as chief
        $_SESSION['auth'] = [
            'user_account_id' => 1,
            'email' => 'chief@example.com',
            'role' => 'chief',
            'linked_members' => [],
        ];

        $profile = $this->makeProfile(['totem' => 'Mowgli']);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn($profile);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                'members/show.html.twig',
                $this->callback(function (array $context) {
                    return $context['show_contact'] === true;
                })
            )
            ->willReturn('<html></html>');

        $controller = $this->newController($twig, $memberService);

        $request = new Request('GET', '/members/2', [], [], [], []);
        $response = $controller->show($request, ['id' => '2']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAddressesAreHiddenFromIdentifiedUsersViewingViaAnotherRoute(): void
    {
        // Mock canAccess to return true for the email match but not for chief role
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn($this->makeProfile());

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                'members/show.html.twig',
                $this->callback(function (array $context) {
                    return $context['show_addresses'] === true; // Self access allows addresses
                })
            )
            ->willReturn('<html></html>');

        $controller = $this->newController($twig, $memberService);

        $request = new Request('GET', '/members/1', [], [], [], []);
        $response = $controller->show($request, ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPageHandlesMembersWithMinimalDataGracefully(): void
    {
        $profile = $this->makeProfile(['firstName' => 'John']);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('canAccess')->willReturn(true);
        $memberService->method('getMemberProfile')->willReturn($profile);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturn('<html></html>');

        $controller = $this->newController($twig, $memberService);

        $request = new Request('GET', '/members/1', [], [], [], []);
        $response = $controller->show($request, ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }
}
