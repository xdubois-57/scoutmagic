<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * Logging out forgets the device's push subscription (issue #1123, audit
 * SEC-028): public/assets/js/push-logout.js posts the endpoint in
 * `push_endpoint`, and every row for it goes, whichever account holds it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class AuthControllerLogoutPushTest extends TestCase
{
    private const ENDPOINT = 'https://push.example/shared-device';

    private \PDO $pdo;
    private PushSubscriptionRepository $subscriptions;
    private AuthController $controller;
    private int $firstAccount;
    private int $secondAccount;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->subscriptions = new PushSubscriptionRepository($this->pdo, $encryption);

        $notifications = new NotificationService(
            new NotificationRepository($this->pdo, $encryption),
            $this->subscriptions,
            new NotificationPreferenceRepository($this->pdo),
            null,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption)
        );

        $twig = TestTwig::create();
        $this->controller = new AuthController(
            $twig,
            $this->createMock(AuthService::class),
            \Tests\SessionGenerationFixture::forPdo()
        );
        $this->controller->setNotificationService($notifications);

        $this->firstAccount = $this->createAccount('enc-1', 'idx-1');
        $this->secondAccount = $this->createAccount('enc-2', 'idx-2');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        AuthSession::login($this->secondAccount, 'second@test.be', 'identified');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function createAccount(string $email, string $index): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$email, $index]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, string> $extra
     */
    private function logout(array $extra = []): int
    {
        $body = ['_csrf_token' => CsrfGuard::generateToken()] + $extra;

        return $this->controller->logout(new Request('POST', '/logout', [], $body, [], []), [])->getStatusCode();
    }

    public function testLogoutWithAPostedEndpointForgetsItForEveryAccount(): void
    {
        // Written straight through the repository: two accounts holding
        // the same endpoint is the state this issue found in production
        // data, which subscribe() itself no longer produces.
        $this->subscriptions->create($this->firstAccount, self::ENDPOINT, 'auth-1', 'p256dh-1');
        $this->subscriptions->create($this->secondAccount, self::ENDPOINT, 'auth-2', 'p256dh-2');
        $this->subscriptions->create($this->secondAccount, 'https://push.example/other-device', 'auth-3', 'p256dh-3');

        $status = $this->logout(['push_endpoint' => self::ENDPOINT]);

        $this->assertSame(302, $status);
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertSame([], $this->subscriptions->findByUserAccountId($this->firstAccount));
        $remaining = $this->subscriptions->findByUserAccountId($this->secondAccount);
        $this->assertCount(1, $remaining, 'Only the device logging out is forgotten, never the account\'s others.');
        $this->assertSame('https://push.example/other-device', $remaining[0]->endpoint);
    }

    public function testLogoutWithoutAnEndpointStillLogsOutAndForgetsNothing(): void
    {
        $this->subscriptions->create($this->secondAccount, self::ENDPOINT, 'auth', 'p256dh');

        $status = $this->logout();

        $this->assertSame(302, $status);
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertCount(1, $this->subscriptions->findByUserAccountId($this->secondAccount));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEndpoints(): iterable
    {
        yield 'plain http' => ['http://push.example/shared-device'];
        yield 'no scheme' => ['push.example/shared-device'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'no host' => ['https:///shared-device'];
        yield 'whitespace' => ["https://push.example/shared device"];
        yield 'control character' => ["https://push.example/shared\n-device"];
        yield 'far too long' => ['https://push.example/' . str_repeat('a', 5000)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEndpoints')]
    public function testAMalformedEndpointIsIgnoredAndTheLogoutStillHappens(string $endpoint): void
    {
        $this->subscriptions->create($this->secondAccount, self::ENDPOINT, 'auth', 'p256dh');

        $status = $this->logout(['push_endpoint' => $endpoint]);

        $this->assertSame(302, $status);
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertCount(1, $this->subscriptions->findByUserAccountId($this->secondAccount));
    }

    public function testANonStringEndpointIsIgnored(): void
    {
        $this->subscriptions->create($this->secondAccount, self::ENDPOINT, 'auth', 'p256dh');
        $body = ['_csrf_token' => CsrfGuard::generateToken(), 'push_endpoint' => [self::ENDPOINT]];

        $response = $this->controller->logout(new Request('POST', '/logout', [], $body, [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->subscriptions->findByUserAccountId($this->secondAccount));
    }

    public function testAFailingDatabaseNeverStopsTheLogout(): void
    {
        $this->pdo->exec('DROP TABLE push_subscriptions');

        $status = $this->logout(['push_endpoint' => self::ENDPOINT]);

        $this->assertSame(302, $status);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testWithoutACsrfTokenNothingIsForgotten(): void
    {
        $this->subscriptions->create($this->secondAccount, self::ENDPOINT, 'auth', 'p256dh');
        $body = ['_csrf_token' => 'forged', 'push_endpoint' => self::ENDPOINT];

        $this->controller->logout(new Request('POST', '/logout', [], $body, [], []), []);

        $this->assertCount(1, $this->subscriptions->findByUserAccountId($this->secondAccount));
    }
}
