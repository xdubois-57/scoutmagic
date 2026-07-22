<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Security\PasswordPolicy;
use Core\Security\PasswordResetService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Mirrors Tests\Core\Security\AuthServiceTest's structure/conventions for
 * the "mot de passe oublié" flow. Unlike MagicLinkRepository,
 * Core\Security\PasswordResetRepository never uses MySQL-only NOW()/
 * DATE_SUB(), so this suite (uniquely, compared to AuthServiceTest) runs
 * cleanly against the SQLite test database with no portability failures.
 *
 * @group database
 */
class PasswordResetServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PasswordResetService $service;
    private UserAccountRepository $userRepo;
    private bool $emailSent;
    private string $capturedResetUrl = '';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        $this->emailSent = false;
        $mailService = $this->createMock(\Core\Mail\MailService::class);
        $mailService->method('send')->willReturnCallback(function () {
            $this->emailSent = true;
        });

        $twig = new Environment(new ArrayLoader([
            'email/password_reset.html.twig' => '<a href="{{ reset_url }}">Reset</a>',
            'email/password_reset.text.twig' => '{{ reset_url }}',
        ]));
        $twig->addGlobal('site_name', 'Test Unit');

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->service = new PasswordResetService(
            $connection, $this->encryption, $mailService, $twig, 'https://example.com', 'Test Unit'
        );
    }

    public function testRequestResetWithKnownEmailSendsEmail(): void
    {
        $this->userRepo->create('user@test.com');

        $this->service->requestReset('user@test.com');

        $this->assertTrue($this->emailSent);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testRequestResetWithUnknownEmailDoesNotSendEmailButStillCreatesToken(): void
    {
        // No enumeration: a token row is still created (so rate limiting is
        // consistent either way) but no email goes out.
        $this->service->requestReset('unknown@test.com');

        $this->assertFalse($this->emailSent);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testRequestResetRateLimiting(): void
    {
        $this->userRepo->create('limited@test.com');

        for ($i = 0; $i < 5; $i++) {
            $this->service->requestReset('limited@test.com');
        }
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());

        // 6th request is silently dropped (no new row, no email).
        $this->emailSent = false;
        $this->service->requestReset('limited@test.com');
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        $this->assertFalse($this->emailSent);
    }

    public function testCheckTokenValidForFreshToken(): void
    {
        $this->userRepo->create('checker@test.com');
        [$id, $rawToken] = $this->createRawToken('checker@test.com');

        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testCheckTokenInvalidForWrongToken(): void
    {
        $this->userRepo->create('checker2@test.com');
        [$id] = $this->createRawToken('checker2@test.com');

        $this->assertFalse($this->service->checkToken($id, 'wrong-token'));
    }

    public function testCheckTokenInvalidForExpiredToken(): void
    {
        $this->userRepo->create('expired@test.com');
        [$id, $rawToken] = $this->createRawToken('expired@test.com', expired: true);

        $this->assertFalse($this->service->checkToken($id, $rawToken));
    }

    public function testCheckTokenDoesNotConsumeIt(): void
    {
        $this->userRepo->create('viewer@test.com');
        [$id, $rawToken] = $this->createRawToken('viewer@test.com');

        $this->service->checkToken($id, $rawToken);
        // Still valid on a second look — viewing the page must never burn the token.
        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testResetPasswordSucceedsAndUpdatesHashAndTimestamp(): void
    {
        $account = $this->userRepo->create('reset@test.com');
        [$id, $rawToken] = $this->createRawToken('reset@test.com');

        $result = $this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1');

        $this->assertTrue($result);
        $updated = $this->userRepo->findById($account->id);
        $this->assertTrue(password_verify('MyNewSecureP@ss1', $updated->passwordHash));
        $this->assertNotNull($updated->passwordChangedAt);
    }

    public function testResetPasswordConsumesTokenSoItCannotBeReused(): void
    {
        $this->userRepo->create('onceonly@test.com');
        [$id, $rawToken] = $this->createRawToken('onceonly@test.com');

        $this->assertTrue($this->service->resetPassword($id, $rawToken, 'FirstNewP@ss1'));
        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'SecondNewP@ss1'));
    }

    public function testResetPasswordRejectsPasswordFailingComplexityPolicy(): void
    {
        $this->userRepo->create('weak@test.com');
        [$id, $rawToken] = $this->createRawToken('weak@test.com');

        $this->assertFalse(PasswordPolicy::isValid('short'));
        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'short'));

        // Token remains usable since the reset never actually happened.
        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testResetPasswordFailsForExpiredToken(): void
    {
        $this->userRepo->create('expired2@test.com');
        [$id, $rawToken] = $this->createRawToken('expired2@test.com', expired: true);

        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1'));
    }

    /**
     * @return array{0: int, 1: string} [token id, raw token]
     */
    private function createRawToken(string $email, bool $expired = false): array
    {
        $blindIndex = $this->encryption->blindIndex(strtolower($email));
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = $expired
            ? (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')
            : (new \DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, $tokenHash, $expiresAt]);

        return [(int) $this->pdo->lastInsertId(), $rawToken];
    }
}
