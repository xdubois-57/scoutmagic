<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Security\AuthService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @group database
 */
class AuthServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AuthService $authService;
    private UserAccountRepository $userRepo;
    private MailService $mailService;
    private bool $emailSent;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_encrypted BLOB NOT NULL,
                email_blind_index CHAR(64) NOT NULL,
                first_name_encrypted BLOB,
                last_name_encrypted BLOB,
                password_hash VARCHAR(255),
                is_super_admin BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at DATETIME
            )
        ');
        $this->pdo->exec('
            CREATE TABLE magic_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_blind_index CHAR(64) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used BOOLEAN NOT NULL DEFAULT 0,
                confirmed_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        // Secondary-email login resolution (AuthService::resolveAccountForEmail()).
        $this->pdo->exec('
            CREATE TABLE member_emails (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                email_encrypted BLOB NOT NULL,
                email_blind_index CHAR(64) NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT "manual",
                status VARCHAR(20) NOT NULL DEFAULT "pending",
                confirmation_token_hash VARCHAR(255),
                confirmation_expires_at DATETIME,
                last_confirmation_sent_at DATETIME,
                confirmed_at DATETIME,
                deactivated_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE scout_years (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label VARCHAR(9) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE member_years (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                scout_year_id INTEGER NOT NULL,
                email_blind_index CHAR(64)
            )
        ');

        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        // Create a test mail service that doesn't actually send
        $this->emailSent = false;
        $tempDir = sys_get_temp_dir() . '/authservice_test_' . uniqid();
        mkdir($tempDir, 0700, true);
        $dkimManager = new DkimManager($tempDir);

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->method('send')->willReturnCallback(function () {
            $this->emailSent = true;
        });

        $twig = new Environment(new ArrayLoader([
            'email/magic_link.html.twig' => '<a href="{{ magic_link_url }}">Login</a>',
            'email/magic_link.text.twig' => '{{ magic_link_url }}',
        ]));
        $twig->addGlobal('site_name', 'Test Unit');

        // Create a mock connection that returns our PDO
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->authService = new AuthService(
            $connection,
            $this->encryption,
            $this->mailService,
            $twig,
            'https://example.com',
            'Test Unit'
        );
    }

    public function testRequestMagicLinkWithKnownEmail(): void
    {
        $this->userRepo->create('user@test.com');

        $result = $this->authService->requestMagicLink('user@test.com');

        $this->assertTrue($result->success);
        $this->assertNotNull($result->magicLinkId);
        $this->assertNull($result->error);
        $this->assertTrue($this->emailSent);
    }

    public function testRequestMagicLinkWithUnknownEmail(): void
    {
        $result = $this->authService->requestMagicLink('unknown@test.com');

        // Same response (no enumeration)
        $this->assertTrue($result->success);
        $this->assertNotNull($result->magicLinkId);
        $this->assertNull($result->error);
        // But email should NOT be sent
        $this->assertFalse($this->emailSent);
    }

    public function testRequestMagicLinkWithValidSecondaryEmailSendsAndAttachesToThePrimaryAccount(): void
    {
        $this->userRepo->create('primary@test.com');

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");

        $memberId = 42;
        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, email_blind_index) VALUES (?, 1, ?)')
            ->execute([$memberId, $this->encryption->blindIndex('primary@test.com')]);
        $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, "manual", "valid")'
        )->execute([$memberId, $this->encryption->encrypt('secondary@test.com'), $this->encryption->blindIndex('secondary@test.com')]);

        $result = $this->authService->requestMagicLink('secondary@test.com');

        $this->assertTrue($result->success);
        $this->assertTrue($this->emailSent);

        // The stored magic link must resolve to the PRIMARY account's blind
        // index, not the secondary address's — otherwise verifyMagicLink()
        // could never find a matching user_accounts row.
        $stmt = $this->pdo->query('SELECT email_blind_index FROM magic_links ORDER BY id DESC LIMIT 1');
        $this->assertSame($this->encryption->blindIndex('primary@test.com'), $stmt->fetchColumn());
    }

    public function testRequestMagicLinkWithPendingSecondaryEmailDoesNotSend(): void
    {
        $this->userRepo->create('primary2@test.com');

        $memberId = 43;
        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, email_blind_index) VALUES (?, 1, ?)')
            ->execute([$memberId, $this->encryption->blindIndex('primary2@test.com')]);
        $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, "manual", "pending")'
        )->execute([$memberId, $this->encryption->encrypt('unconfirmed@test.com'), $this->encryption->blindIndex('unconfirmed@test.com')]);

        $result = $this->authService->requestMagicLink('unconfirmed@test.com');

        $this->assertTrue($result->success);
        $this->assertFalse($this->emailSent);
    }

    public function testRequestMagicLinkRateLimiting(): void
    {
        $this->userRepo->create('limited@test.com');

        // Make 5 successful requests
        for ($i = 0; $i < 5; $i++) {
            $result = $this->authService->requestMagicLink('limited@test.com');
            $this->assertTrue($result->success);
        }

        // 6th request should be rate-limited
        $result = $this->authService->requestMagicLink('limited@test.com');
        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
    }

    public function testVerifyMagicLinkWithValidToken(): void
    {
        $this->userRepo->create('verify@test.com');

        // Manually create a magic link with a known token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $blindIndex = $this->encryption->blindIndex('verify@test.com');
        $expiresAt = (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, $tokenHash, $expiresAt]);
        $id = (int) $this->pdo->lastInsertId();

        $verified = $this->authService->verifyMagicLink($id, $rawToken);

        $this->assertNotNull($verified);
        $this->assertSame('verify@test.com', $verified->email);
        $this->assertSame(1, $verified->userAccountId);
    }

    public function testVerifyMagicLinkWithWrongToken(): void
    {
        $this->userRepo->create('user@test.com');
        $blindIndex = $this->encryption->blindIndex('user@test.com');

        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, password_hash('correct', PASSWORD_DEFAULT), (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s')]);
        $id = (int) $this->pdo->lastInsertId();

        $verified = $this->authService->verifyMagicLink($id, 'wrong_token');
        $this->assertNull($verified);
    }

    public function testVerifyMagicLinkExpired(): void
    {
        $this->userRepo->create('user@test.com');
        $blindIndex = $this->encryption->blindIndex('user@test.com');
        $rawToken = 'test_token';

        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, password_hash($rawToken, PASSWORD_DEFAULT), '2020-01-01 00:00:00']);
        $id = (int) $this->pdo->lastInsertId();

        $verified = $this->authService->verifyMagicLink($id, $rawToken);
        $this->assertNull($verified);
    }

    public function testVerifyMagicLinkAlreadyUsed(): void
    {
        $this->userRepo->create('user@test.com');
        $blindIndex = $this->encryption->blindIndex('user@test.com');
        $rawToken = 'test_token';

        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, used, confirmed_at) VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$blindIndex, password_hash($rawToken, PASSWORD_DEFAULT), (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s')]);
        $id = (int) $this->pdo->lastInsertId();

        $verified = $this->authService->verifyMagicLink($id, $rawToken);
        $this->assertNull($verified);
    }

    public function testIsMagicLinkConfirmed(): void
    {
        $blindIndex = str_repeat('a', 64);

        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, used, confirmed_at) VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$blindIndex, 'hash', (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s')]);
        $id = (int) $this->pdo->lastInsertId();

        $this->assertTrue($this->authService->isMagicLinkConfirmed($id));
    }

    public function testIsMagicLinkNotConfirmed(): void
    {
        $blindIndex = str_repeat('a', 64);

        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, 'hash', (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s')]);
        $id = (int) $this->pdo->lastInsertId();

        $this->assertFalse($this->authService->isMagicLinkConfirmed($id));
    }

    public function testCleanupExpiredLinks(): void
    {
        // Insert an expired link
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([str_repeat('x', 64), 'hash', '2020-01-01 00:00:00', '2020-01-01 00:00:00']);

        $deleted = $this->authService->cleanupExpiredLinks();
        $this->assertSame(1, $deleted);
    }

    public function testGetUserById(): void
    {
        $account = $this->userRepo->create('getbyid@test.com', true);

        $found = $this->authService->getUserById($account->id);

        $this->assertNotNull($found);
        $this->assertSame('getbyid@test.com', $found->email);
        $this->assertTrue($found->isSuperAdmin);
    }
}
