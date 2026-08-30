<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\SuperAdminAccountsController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\Security\SuperAdminService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Configuration > Comptes superadmin.
 *
 * The three refusals are the point of this file, and they are asserted
 * against the controller — not against the JavaScript, which only greys
 * a button out and is not where a refusal can live.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SuperAdminAccountsControllerTest extends TestCase
{
    private \PDO $pdo;
    private UserAccountRepository $userRepo;
    private SuperAdminAccountsController $controller;
    private string $csrfToken;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $encryption);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $this->controller = new SuperAdminAccountsController(
            $twig,
            $this->userRepo,
            new SuperAdminService($this->userRepo, new JournalService(new JournalRepository($this->pdo)))
        );

        $this->csrfToken = CsrfGuard::generateToken();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ---------------------------------------------------------------
    // RBAC boundary
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function superAdminRoutes(): array
    {
        return [
            'the page itself' => ['GET', '/config/superadmins', 'index'],
            'granting the right' => ['POST', '/config/superadmins/add', 'add'],
            'withdrawing the right' => ['POST', '/config/superadmins/revoke', 'revoke'],
        ];
    }

    /**
     * Read out of public/index.php rather than copied here: the route
     * table lives in a procedural bootstrap no unit test loads, so a
     * source-level read is the only way to assert the value the
     * application actually registers (same technique as
     * Tests\Core\Http\Controller\EditableContentRbacTest).
     */
    private static function registeredRoleMin(string $method, string $path, string $action): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]' . $method . '[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,'
                . '\s*SuperAdminAccountsController::class\s*,\s*[\'"]' . preg_quote($action, '/') . '[\'"]\s*,'
                . '\s*[\'"]([a-z_]+)[\'"]/',
            $contents,
            $m
        );
        self::assertSame(1, $matched, "No addRoute registration found for {$method} {$path}");

        return $m[1];
    }

    #[DataProvider('superAdminRoutes')]
    public function testEveryRouteIsRegisteredAtSuperadmin(string $method, string $path, string $action): void
    {
        $this->assertSame('superadmin', self::registeredRoleMin($method, $path, $action));
    }

    #[DataProvider('superAdminRoutes')]
    public function testASuperAdminReachesIt(string $method, string $path, string $action): void
    {
        AuthSession::login(1, 'admin@test.be', 'superadmin');

        $this->assertNull(
            (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path, $action)))
        );
    }

    /**
     * One level below the floor: a chef d'unité (admin) manages the unit,
     * not the accounts that administer the site.
     */
    #[DataProvider('superAdminRoutes')]
    public function testAnAdminIsRefused(string $method, string $path, string $action): void
    {
        AuthSession::login(2, 'chef-unite@test.be', 'admin');

        $response = (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path, $action)));

        $this->assertNotNull($response, "A chef d'unité must not reach {$method} {$path}.");
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Adding
    // ---------------------------------------------------------------

    public function testAddingAnUnknownAddressCreatesTheAccount(): void
    {
        $this->loginAsExistingSuperAdmin();

        $this->controller->add($this->postRequest(['email' => 'nouveau@example.com']), []);

        $created = $this->userRepo->findByEmail('nouveau@example.com');
        $this->assertNotNull($created);
        $this->assertTrue($created->isSuperAdmin);
        $this->assertTrue($created->isActive);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    public function testAddingAnExistingAccountSetsTheFlagWithoutDuplicating(): void
    {
        $this->loginAsExistingSuperAdmin();
        $chief = $this->userRepo->create('chef-unite@example.com', false);

        $this->controller->add($this->postRequest(['email' => 'chef-unite@example.com']), []);

        $this->assertCount(2, $this->userRepo->findAllIds(), 'no second row for the same address');
        $this->assertTrue($this->userRepo->findById($chief->id)?->isSuperAdmin);
    }

    public function testAddingAnAddressThatIsAlreadySuperAdminChangesNothing(): void
    {
        $this->loginAsExistingSuperAdmin();
        $other = $this->userRepo->create('deja@example.com', true);

        $this->controller->add($this->postRequest(['email' => 'DEJA@example.com']), []);

        $this->assertCount(2, $this->userRepo->findAllIds());
        $this->assertTrue($this->userRepo->findById($other->id)?->isSuperAdmin);
        $this->assertSame('warning', FlashMessage::get()['type'] ?? null);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $this->loginAsExistingSuperAdmin();

        $this->controller->add($this->postRequest(['email' => 'pas-une-adresse']), []);

        $this->assertCount(1, $this->userRepo->findAllIds());
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    // ---------------------------------------------------------------
    // The three refusals — server-side
    // ---------------------------------------------------------------

    public function testTheLastSuperAdminCannotBeRevoked(): void
    {
        // The only super admin, and someone else is doing the revoking,
        // so it is the "last one" rule that has to catch this and not the
        // "not yourself" one.
        $alone = $this->userRepo->create('seul@example.com', true);
        AuthSession::login(999, 'quelquun@example.com', 'superadmin');

        $this->controller->revoke($this->postRequest(['user_account_id' => (string) $alone->id]), []);

        $this->assertTrue($this->userRepo->findById($alone->id)?->isSuperAdmin, 'still a super admin');
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('dernier', (string) ($flash['message'] ?? ''));
        $this->assertRefusalJournaled('last_super_admin');
    }

    /**
     * Counted on the accounts that can actually log in: a deactivated
     * super admin is refused by every login path, so leaving only one
     * behind would lock the unit out of its own configuration.
     */
    public function testADeactivatedSuperAdminDoesNotCountAsTheOneThatRemains(): void
    {
        $active = $this->userRepo->create('actif@example.com', true);
        $dormant = $this->userRepo->create('dormant@example.com', true);
        $this->userRepo->deactivate($dormant->id);
        AuthSession::login(999, 'quelquun@example.com', 'superadmin');

        $this->controller->revoke($this->postRequest(['user_account_id' => (string) $active->id]), []);

        $this->assertTrue($this->userRepo->findById($active->id)?->isSuperAdmin);
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    /**
     * Compared on the session's account id, never on the address: an
     * address can be re-typed or re-cased, and the identity of the person
     * clicking is the session's.
     */
    public function testYouCannotRevokeYourself(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $this->userRepo->create('autre@example.com', true);
        AuthSession::login($me->id, $me->email, 'superadmin');

        $this->controller->revoke($this->postRequest(['user_account_id' => (string) $me->id]), []);

        $this->assertTrue($this->userRepo->findById($me->id)?->isSuperAdmin, 'still a super admin');
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('propre', (string) ($flash['message'] ?? ''));
        $this->assertRefusalJournaled('self');
    }

    public function testRevokingSomebodyElseWorksAndKeepsTheRow(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $other = $this->userRepo->create('autre@example.com', true);
        AuthSession::login($me->id, $me->email, 'superadmin');

        $this->controller->revoke($this->postRequest(['user_account_id' => (string) $other->id]), []);

        $reloaded = $this->userRepo->findById($other->id);
        $this->assertNotNull($reloaded, 'the row is never deleted');
        $this->assertFalse($reloaded->isSuperAdmin);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    // ---------------------------------------------------------------
    // CSRF
    // ---------------------------------------------------------------

    public function testARevokeWithoutACsrfTokenChangesNothing(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $other = $this->userRepo->create('autre@example.com', true);
        AuthSession::login($me->id, $me->email, 'superadmin');

        $this->controller->revoke(
            new Request('POST', '/config/superadmins/revoke', [], ['user_account_id' => (string) $other->id], [], []),
            []
        );

        $this->assertTrue($this->userRepo->findById($other->id)?->isSuperAdmin);
    }

    // ---------------------------------------------------------------

    private function loginAsExistingSuperAdmin(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        AuthSession::login($me->id, $me->email, 'superadmin');
    }

    /**
     * @param array<string, string> $body
     */
    private function postRequest(array $body): Request
    {
        return new Request(
            'POST',
            '/config/superadmins',
            [],
            $body + ['_csrf_token' => $this->csrfToken],
            [],
            []
        );
    }

    private function assertRefusalJournaled(string $reason): void
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'super_admin_revoke_refused' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

        $this->assertIsArray($row, 'a refused attempt is journaled');
        $this->assertSame('security', $row['level']);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame($reason, $context['reason'] ?? null);
        $this->assertStringNotContainsString('@', (string) $row['context'], 'never the address');
    }
}
