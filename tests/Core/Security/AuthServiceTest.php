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
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
                sessions_valid_from DATETIME,
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

        // This file builds its own minimal schema rather than using
        // DatabaseTestHelper, so the e-mail register's table has to be
        // declared here too: AuthService renders the magic link through
        // Core\Mail\Template\EmailTemplateRenderer, which asks whether a
        // customisation exists before it renders anything. An empty table
        // is the ordinary answer — the shipped template.
        $this->pdo->exec('
            CREATE TABLE email_template_overrides (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id TEXT NOT NULL UNIQUE,
                subject TEXT NOT NULL,
                body_html TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by INTEGER
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
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
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

    public function testRequestMagicLinkWithValidSecondaryEmailSendsAndStoresTheSubmittedAddress(): void
    {
        $this->userRepo->create('primary@test.com');
        $this->givenValidSecondaryEmail(42, 'primary@test.com', 'secondary@test.com');

        $result = $this->authService->requestMagicLink('secondary@test.com');

        $this->assertTrue($result->success);
        $this->assertTrue($this->emailSent);

        // The link must be stored against the SUBMITTED address, never the
        // member's primary one: that blind index is the identity the link
        // confers, and it is what the rate limit counts.
        $stmt = $this->pdo->query('SELECT email_blind_index FROM magic_links ORDER BY id DESC LIMIT 1');
        $this->assertSame($this->encryption->blindIndex('secondary@test.com', 'email'), $stmt->fetchColumn());

        // Nothing is created before the token is proven.
        $this->assertNull($this->userRepo->findByEmail('secondary@test.com'));
    }

    /**
     * The security regression this whole path exists for: confirming a
     * magic link sent to a member's secondary address used to log the
     * clicker in as the account behind that member's PRIMARY address —
     * i.e. as somebody else, with every member and every credential of
     * that account.
     */
    public function testVerifyMagicLinkForSecondaryEmailLogsInAsThatAddressNotAsThePrimaryAccount(): void
    {
        $primary = $this->userRepo->create('primary@test.com');
        $this->givenValidSecondaryEmail(42, 'primary@test.com', 'secondary@test.com');

        $rawToken = bin2hex(random_bytes(32));
        $id = $this->givenMagicLink('secondary@test.com', $rawToken);

        $verified = $this->authService->verifyMagicLink($id, $rawToken);

        $this->assertNotNull($verified);
        $this->assertSame('secondary@test.com', $verified->email);
        $this->assertNotSame($primary->id, $verified->userAccountId);

        // The address got its own account row, never super-admin.
        $created = $this->userRepo->findByEmail('secondary@test.com');
        $this->assertNotNull($created);
        $this->assertSame($created->id, $verified->userAccountId);
        $this->assertFalse($created->isSuperAdmin);
    }

    public function testVerifyMagicLinkForSecondaryEmailReusesItsAccountOnTheNextLogin(): void
    {
        $this->userRepo->create('primary@test.com');
        $this->givenValidSecondaryEmail(42, 'primary@test.com', 'secondary@test.com');

        $firstToken = bin2hex(random_bytes(32));
        $first = $this->authService->verifyMagicLink($this->givenMagicLink('secondary@test.com', $firstToken), $firstToken);

        $secondToken = bin2hex(random_bytes(32));
        $second = $this->authService->verifyMagicLink($this->givenMagicLink('secondary@test.com', $secondToken), $secondToken);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->userAccountId, $second->userAccountId);

        $count = $this->pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn();
        $this->assertSame(2, (int) $count);
    }

    public function testVerifyMagicLinkForAnAddressThatIsNoLongerValidFails(): void
    {
        $this->userRepo->create('primary@test.com');
        $this->givenValidSecondaryEmail(42, 'primary@test.com', 'secondary@test.com');

        $rawToken = bin2hex(random_bytes(32));
        $id = $this->givenMagicLink('secondary@test.com', $rawToken);

        // The member removes/deactivates the address between the send and
        // the click — the link must not resolve any account at all.
        $this->pdo->exec("UPDATE member_emails SET status = 'inactive'");

        $this->assertNull($this->authService->verifyMagicLink($id, $rawToken));
        $this->assertNull($this->userRepo->findByEmail('secondary@test.com'));
    }

    public function testRequestMagicLinkRateLimitsASecondaryAddressToo(): void
    {
        $this->userRepo->create('primary@test.com');
        $this->givenValidSecondaryEmail(42, 'primary@test.com', 'secondary@test.com');

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->authService->requestMagicLink('secondary@test.com')->success);
        }

        $this->assertFalse($this->authService->requestMagicLink('secondary@test.com')->success);
    }

    private function givenValidSecondaryEmail(int $memberId, string $primaryEmail, string $secondaryEmail): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, email_blind_index) VALUES (?, 1, ?)')
            ->execute([$memberId, $this->encryption->blindIndex($primaryEmail, 'email')]);
        $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, "manual", "valid")'
        )->execute([
            $memberId,
            $this->encryption->encrypt($secondaryEmail, 'member_emails.email'),
            $this->encryption->blindIndex($secondaryEmail, 'email'),
        ]);
    }

    private function givenMagicLink(string $email, string $rawToken): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $this->encryption->blindIndex($email, 'email'),
            password_hash($rawToken, PASSWORD_DEFAULT),
            (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testRequestMagicLinkWithPendingSecondaryEmailDoesNotSend(): void
    {
        $this->userRepo->create('primary2@test.com');

        $memberId = 43;
        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, email_blind_index) VALUES (?, 1, ?)')
            ->execute([$memberId, $this->encryption->blindIndex('primary2@test.com', 'email')]);
        $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, "manual", "pending")'
        )->execute([$memberId, $this->encryption->encrypt('unconfirmed@test.com', 'member_emails.email'), $this->encryption->blindIndex('unconfirmed@test.com', 'email')]);

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
        $blindIndex = $this->encryption->blindIndex('verify@test.com', 'email');
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
        $blindIndex = $this->encryption->blindIndex('user@test.com', 'email');

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
        $blindIndex = $this->encryption->blindIndex('user@test.com', 'email');
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
        $blindIndex = $this->encryption->blindIndex('user@test.com', 'email');
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
