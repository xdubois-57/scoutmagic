<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Http\Controller\PasswordResetController;
use Core\Http\Request;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\PasswordResetService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PasswordResetControllerTest extends TestCase
{
    private PasswordResetController $controller;
    private PasswordResetService $service;
    private UserAccountRepository $userRepo;
    private \PDO $pdo;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        $mailService = $this->createMock(\Core\Mail\MailService::class);
        $twig = new Environment(new ArrayLoader([
            'email/password_reset.html.twig' => '{{ reset_url }}',
            'email/password_reset.text.twig' => '{{ reset_url }}',
            'auth/password_reset.html.twig' => 'RESET_PAGE id={{ reset_id }} csrf={{ csrf_token }}',
        ]));
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('csp_nonce', 'x');

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->service = new PasswordResetService($connection, $this->encryption, $mailService, EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig), 'https://example.com', 'Test Unit');
        $this->controller = new PasswordResetController($twig, $this->service);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRequestRejectsInvalidCsrf(): void
    {
        $request = new Request('POST', '/password-reset/request', [], ['email' => 'a@test.com', '_csrf_token' => 'bad'], [], []);
        $response = $this->controller->request($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function withHumanCheck(int $minDelaySeconds = 0): \Core\Security\HumanCheck\HumanCheckService
    {
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $settings->register('human_check_min_delay_seconds', (string) $minDelaySeconds, 'number', 'D', 'D');
        $settings->register('human_check_form_validity_seconds', '3600', 'number', 'D', 'D');
        $humanCheck = new \Core\Security\HumanCheck\HumanCheckService(
            $this->encryption,
            new \Core\Security\HumanCheck\HumanCheckRateLimitRepository($this->pdo),
            $settings,
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        );
        $this->controller->setHumanCheck($humanCheck);

        return $humanCheck;
    }

    private function resetTokenCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
    }

    public function testRequestWithFilledHoneypotDoesNoWorkButStillReturnsGenericSuccess(): void
    {
        $humanCheck = $this->withHumanCheck();
        $challenge = $humanCheck->generateChallenge(PasswordResetController::HUMAN_CHECK_FORM_KEY);
        $token = CsrfGuard::generateToken();

        // A bot fills the honeypot trap field. The response must still be the
        // indistinguishable "success", but no bcrypt hash / token row is paid
        // (audit M3).
        $request = new Request('POST', '/password-reset/request', [], [
            'email' => 'someone@test.com',
            '_csrf_token' => $token,
            'human_check_token' => $challenge->tokenValue,
            $challenge->trapField => 'i-am-a-bot',
        ], [], ['REMOTE_ADDR' => '203.0.113.7']);

        $response = $this->controller->request($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame(0, $this->resetTokenCount(), 'a honeypot-tripped request must never create a reset token');
    }

    public function testRequestThatPassesTheHumanCheckProceeds(): void
    {
        $humanCheck = $this->withHumanCheck();
        $challenge = $humanCheck->generateChallenge(PasswordResetController::HUMAN_CHECK_FORM_KEY);
        $token = CsrfGuard::generateToken();

        // Empty honeypot, valid token — the request is accepted and (per the
        // anti-enumeration contract) a token row is created even for an
        // unknown address.
        $request = new Request('POST', '/password-reset/request', [], [
            'email' => 'unknown@test.com',
            '_csrf_token' => $token,
            'human_check_token' => $challenge->tokenValue,
            $challenge->trapField => '',
        ], [], ['REMOTE_ADDR' => '203.0.113.8']);

        $response = $this->controller->request($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame(1, $this->resetTokenCount());
    }

    public function testRequestAlwaysReturnsSuccessRegardlessOfEmailExistence(): void
    {
        $token = CsrfGuard::generateToken();
        $request = new Request('POST', '/password-reset/request', [], ['email' => 'nobody@test.com', '_csrf_token' => $token], [], []);
        $response = $this->controller->request($request, []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
    }

    /**
     * The token now travels in the URL fragment, which browsers never send
     * — so this request genuinely cannot see it, and the page renders the
     * same neutral shell whatever the token turns out to be. That is the
     * property worth pinning: the GET must not become a place where a
     * token in the query string would still be honoured.
     */
    public function testShowRendersTheSameShellRegardlessOfAnyTokenInTheQueryString(): void
    {
        $this->userRepo->create('valid-token@test.com');
        [$id, $rawToken] = $this->createRawToken('valid-token@test.com');

        $withRealToken = $this->controller->show(
            new Request('GET', '/password-reset/' . $id, ['token' => $rawToken], [], [], []),
            ['id' => (string) $id]
        );
        $withBogusToken = $this->controller->show(
            new Request('GET', '/password-reset/' . $id, ['token' => 'bogus'], [], [], []),
            ['id' => (string) $id]
        );
        $withNoToken = $this->controller->show(
            new Request('GET', '/password-reset/' . $id, [], [], [], []),
            ['id' => (string) $id]
        );

        $this->assertStringContainsString('RESET_PAGE id=' . $id, $withNoToken->getBody());
        $this->assertSame($withNoToken->getBody(), $withRealToken->getBody());
        $this->assertSame($withNoToken->getBody(), $withBogusToken->getBody());
    }

    public function testCheckReportsAGenuineTokenValidWithoutConsumingIt(): void
    {
        $this->userRepo->create('checkable@test.com');
        [$id, $rawToken] = $this->createRawToken('checkable@test.com');
        $csrf = CsrfGuard::generateToken();

        $request = new Request('POST', '/password-reset/' . $id . '/check', [], [
            'token' => $rawToken, '_csrf_token' => $csrf,
        ], [], []);
        $response = $this->controller->check($request, ['id' => (string) $id]);

        $this->assertTrue(json_decode($response->getBody(), true)['valid']);
        // Checking must never burn the token — the member still has to use it.
        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testCheckReportsAnUnknownOrExpiredTokenInvalid(): void
    {
        $csrf = CsrfGuard::generateToken();

        $request = new Request('POST', '/password-reset/999/check', [], [
            'token' => 'bogus', '_csrf_token' => $csrf,
        ], [], []);
        $response = $this->controller->check($request, ['id' => '999']);

        $this->assertFalse(json_decode($response->getBody(), true)['valid']);
    }

    /**
     * Without CSRF the endpoint would be a cross-origin oracle for whether
     * a guessed token is live.
     */
    public function testCheckRejectsInvalidCsrf(): void
    {
        $request = new Request('POST', '/password-reset/1/check', [], [
            'token' => 'whatever', '_csrf_token' => 'wrong',
        ], [], []);
        $response = $this->controller->check($request, ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['valid']);
    }

    public function testSubmitSetsNewPasswordAndRedirectsToLogin(): void
    {
        $this->userRepo->create('submit@test.com');
        [$id, $rawToken] = $this->createRawToken('submit@test.com');
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/password-reset/' . $id, [], [
            '_csrf_token' => $csrfToken,
            'token' => $rawToken,
            'new_password' => 'MyNewSecureP@ss1',
            'confirm_password' => 'MyNewSecureP@ss1',
        ], [], []);

        $response = $this->controller->submit($request, ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
        $this->assertFalse($this->service->checkToken($id, $rawToken));
    }

    public function testSubmitRejectsMismatchedConfirmation(): void
    {
        $this->userRepo->create('mismatch@test.com');
        [$id, $rawToken] = $this->createRawToken('mismatch@test.com');
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/password-reset/' . $id, [], [
            '_csrf_token' => $csrfToken,
            'token' => $rawToken,
            'new_password' => 'MyNewSecureP@ss1',
            'confirm_password' => 'Different1@Pass',
        ], [], []);

        $this->controller->submit($request, ['id' => (string) $id]);

        // Token still valid — the mismatch was caught before consuming it.
        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function createRawToken(string $email): array
    {
        $blindIndex = $this->encryption->blindIndex(strtolower($email), 'email');
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = (new \DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, $tokenHash, $expiresAt]);

        return [(int) $this->pdo->lastInsertId(), $rawToken];
    }
}
