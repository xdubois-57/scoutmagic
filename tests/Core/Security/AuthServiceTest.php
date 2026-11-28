<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Http\AfterResponse;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Security\AuthService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\TestTwig;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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
    private ?MailPurpose $sentPurpose = null;
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
                match_code_hash VARCHAR(255),
                match_attempts INTEGER NOT NULL DEFAULT 0,
                collected_at DATETIME,
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

        $this->mailService = $this->createStub(MailService::class);
        $this->sentPurpose = null;
        $this->mailService->method('send')->willReturnCallback(
            /**
             * @param array<int, array{path: string, name: string}> $attachments
             * @param array<string, string> $extraHeaders
             */
            function (
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText,
                ?string $replyTo = null,
                array $attachments = [],
                ?string $fromAddressOverride = null,
                ?string $fromNameOverride = null,
                array $extraHeaders = [],
                MailPurpose $purpose = MailPurpose::Ordinary
            ): void {
                $this->emailSent = true;
                $this->sentPurpose = $purpose;
            }
        );

        $twig = new Environment(new ArrayLoader([
            'email/magic_link.html.twig' => '<a href="{{ magic_link_url }}">Login</a>',
            'email/magic_link.text.twig' => '{{ magic_link_url }}',
        ]));
        $twig->addGlobal('site_name', 'Test Unit');

        // Create a mock connection that returns our PDO
        $connection = $this->createStub(Connection::class);
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

    /**
     * The one send() call in the codebase that states a purpose. It is
     * what lets the mail sandbox let sign-in links through while capturing
     * everything else (ARCHITECTURE.md §8.63); if this stops being stated,
     * the exemption silently stops applying and nothing else notices.
     */
    public function testTheMagicLinkEmailStatesItIsASignInLink(): void
    {
        $this->userRepo->create('user@test.com');

        $this->authService->requestMagicLink('user@test.com');

        $this->assertSame(MailPurpose::MagicLink, $this->sentPurpose);
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

    /**
     * A failed send answers exactly like a successful one and like an
     * unknown address: anything else tells whoever typed the address that
     * it has an account, whenever mail is failing. The failure goes to the
     * journal instead (AuthControllerMagicLinkEnumerationTest).
     */
    public function testRequestMagicLinkWhoseSendFailsStillAnswersSuccess(): void
    {
        $this->userRepo->create('refused@test.com');

        $refusing = $this->createStub(MailService::class);
        $refusing->method('send')->willThrowException(
            new \Core\Mail\MailException('Destinataire refusé : refused@test.com')
        );
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $twig = new Environment(new ArrayLoader([
            'email/magic_link.html.twig' => '<a href="{{ magic_link_url }}">Login</a>',
            'email/magic_link.text.twig' => '{{ magic_link_url }}',
        ]));
        $authService = new AuthService(
            $connection,
            $this->encryption,
            $refusing,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
            'https://example.com',
            'Test Unit'
        );

        $result = $authService->requestMagicLink('refused@test.com');

        $this->assertTrue($result->success);
        $this->assertNotNull($result->magicLinkId);
        $this->assertNull($result->error);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $result->matchCode);
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

    /**
     * A magic link that cannot be sent is journaled, and the reason the
     * transport gave is written with the address taken out of it: the
     * journal is no place for the address somebody typed.
     */
    public function testAFailedSendIsJournaledWithTheAddressTakenOutOfTheReason(): void
    {
        $account = $this->userRepo->create('user@test.com');
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())
            ->method('send')
            ->with('user@test.com')
            ->willThrowException(new MailException('Destinataire refusé : user@test.com'));
        // Since #1250 a failed send no longer ends the request: the visitor
        // gets the same answer as for any address. Since #1267 the request
        // is journaled before the answer and the send, with its outcome,
        // after it.
        $logged = [];
        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->exactly(2))
            ->method('log')
            ->willReturnCallback(function (...$args) use (&$logged): void {
                $logged[] = $args;
            });
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $service = new AuthService(
            $connection,
            $this->encryption,
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, TestTwig::create()),
            'https://example.com',
            'Test Unit'
        );
        $service->setJournalService($journal);

        $service->requestMagicLink('user@test.com');

        $this->assertSame([
            ['core', 'magic_link_requested', 'info', 'Demande de lien magique', [], null],
            [
                'core',
                'magic_link_send_failed',
                'info',
                "Échec de l'envoi de l'email de lien magique",
                ['error' => 'Destinataire refusé : [adresse]', 'via_secondary_email' => false],
                $account->id,
            ],
        ], $logged, 'the request, then the failure with the address taken out — and no « sent » entry');
    }

    /**
     * Two confirmations of a secondary address racing: the other request
     * created the address's account between this one's lookup and its
     * insert, which the unique blind index refuses. This one logs in as
     * the account the other created, and no second one is made.
     */
    public function testASecondaryAddressWhoseAccountWasCreatedConcurrentlyLogsIntoThatAccount(): void
    {
        $this->userRepo->create('primary@test.com');
        $this->givenValidSecondaryEmail(42, 'primary@test.com', 'secondary@test.com');
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->givenMagicLink('secondary@test.com', $rawToken);
        // The concurrent request's row lands first, then the unique index
        // refuses this one. RAISE(FAIL) keeps what the statement already
        // did, so the competing row survives the refusal.
        $this->pdo->exec(
            "CREATE TRIGGER concurrent_account BEFORE INSERT ON user_accounts
             WHEN NOT EXISTS (SELECT 1 FROM user_accounts WHERE email_blind_index = NEW.email_blind_index)
             BEGIN
                 INSERT INTO user_accounts (email_encrypted, email_blind_index)
                 VALUES (NEW.email_encrypted, NEW.email_blind_index);
                 SELECT RAISE(FAIL, 'UNIQUE constraint failed: user_accounts.email_blind_index');
             END"
        );

        $verified = $this->authService->verifyMagicLink($id, $rawToken);

        $concurrent = $this->userRepo->findByEmail('secondary@test.com');
        $this->assertNotNull($concurrent);
        $this->assertNotNull($verified);
        $this->assertSame($concurrent->id, $verified->userAccountId);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn());
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

    /**
     * The window is fifteen minutes, and that figure is a promise.
     *
     * `specifications.md` §2 states it — « Token: single-use, 15-minute
     * expiry » — and the RGPD page says it twice, as a retention claim:
     * « Expirés et supprimés automatiquement après 15 minutes »
     * (`core/View/rgpd_default.html`). Nothing held it: widening
     * `TOKEN_EXPIRY_MINUTES` to a full day left the whole suite green,
     * 19 878 tests, because every test that mentions the window writes
     * its own `expires_at` instead of reading the one the service wrote.
     *
     * The tolerance is seconds, deliberately. A minute of slack would
     * accept fourteen or sixteen, and the point is the number itself.
     */
    public function testAMagicLinkIsValidForFifteenMinutesAndNoLonger(): void
    {
        $this->userRepo->create('user@test.com');

        $before = new \DateTimeImmutable();
        $result = $this->authService->requestMagicLink('user@test.com');
        $after = new \DateTimeImmutable();

        $this->assertTrue($result->success);
        $stmt = $this->pdo->prepare('SELECT expires_at FROM magic_links WHERE id = ?');
        $stmt->execute([$result->magicLinkId]);
        $expiresAt = new \DateTimeImmutable((string) $stmt->fetchColumn());

        $this->assertGreaterThanOrEqual(
            $before->modify('+15 minutes')->getTimestamp(),
            $expiresAt->getTimestamp(),
            'The link expires sooner than the fifteen minutes the specification and the RGPD page promise.'
        );
        $this->assertLessThanOrEqual(
            $after->modify('+15 minutes')->getTimestamp(),
            $expiresAt->getTimestamp(),
            'The link outlives the fifteen minutes the specification and the RGPD page promise.'
        );
    }

    /**
     * And the window is read at the second, not at the day.
     *
     * `testVerifyMagicLinkExpired()` below uses 2020, which proves that a
     * link expired six years ago is refused — true of an implementation
     * that compared dates, or years, or nothing much. These two are the
     * borders § of the chantier asks for: the second before, the second
     * after.
     */
    public function testTheExpiryIsEnforcedAtItsBorderRatherThanApproximately(): void
    {
        $this->userRepo->create('user@test.com');

        $justExpired = $this->givenMagicLinkExpiringAt('-1 second', 'token_a');
        $this->assertNull(
            $this->authService->verifyMagicLink($justExpired, 'token_a'),
            'A link one second past its expiry was accepted.'
        );

        $aboutToExpire = $this->givenMagicLinkExpiringAt('+30 seconds', 'token_b');
        $this->assertNotNull(
            $this->authService->verifyMagicLink($aboutToExpire, 'token_b'),
            'A link thirty seconds short of its expiry was refused.'
        );
    }

    private function givenMagicLinkExpiringAt(string $modifier, string $rawToken): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $this->encryption->blindIndex('user@test.com', 'email'),
            password_hash($rawToken, PASSWORD_DEFAULT),
            (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
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

    /**
     * Issue #1099: opening the link (checkMagicLink(), the GET) spends
     * nothing — the same link can still be confirmed afterwards.
     */
    public function testCheckingALinkDoesNotConsumeIt(): void
    {
        $this->userRepo->create('check@test.com');
        $id = $this->givenMagicLink('check@test.com', 'tok');

        $this->assertNotNull($this->authService->checkMagicLink($id, 'tok'));
        $this->assertNotNull($this->authService->checkMagicLink($id, 'tok'));
        $this->assertNull($this->authService->checkMagicLink($id, 'wrong'));
        $this->assertFalse($this->authService->isMagicLinkConfirmed($id));
        $this->assertNotNull($this->authService->verifyMagicLink($id, 'tok'));
    }

    /**
     * Issue #1099: the code generated for the requesting window is what a
     * confirmation from another browser must carry. A wrong one confirms
     * nothing; the last allowed one spends the link.
     */
    public function testAMatchCodeIsRequiredWhenGivenAndWrongOnesSpendTheLink(): void
    {
        $this->userRepo->create('code@test.com');
        $result = $this->authService->requestMagicLink('code@test.com');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $result->matchCode);
        $id = (int) $result->magicLinkId;
        $this->pdo->prepare('UPDATE magic_links SET token_hash = ? WHERE id = ?')
            ->execute([password_hash('tok', PASSWORD_DEFAULT), $id]);
        $wrong = $result->matchCode === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= AuthService::MAX_MATCH_ATTEMPTS; $i++) {
            try {
                $this->authService->verifyMagicLink($id, 'tok', $wrong);
                $this->fail('A wrong code must not confirm the link.');
            } catch (\Core\Security\MagicLinkCodeMismatch $e) {
                $this->assertSame($i === AuthService::MAX_MATCH_ATTEMPTS, $e->linkSpent);
            }
        }

        $this->assertFalse($this->authService->isMagicLinkConfirmed($id));
        $this->assertNull($this->authService->verifyMagicLink($id, 'tok', (string) $result->matchCode));
    }

    /**
     * Review of #1099: parallel guesses. Requests already in flight have
     * taken every attempt, but none has spent the link yet (`used` still
     * FALSE, which is all the token check looks at). A further guess —
     * even the right one — is not compared at all: the cap is the
     * counter, not the `used` flag written after the fact.
     */
    public function testNoCodeIsComparedOnceTheAttemptsAreTakenEvenBeforeTheLinkIsSpent(): void
    {
        $this->userRepo->create('race@test.com');
        $result = $this->authService->requestMagicLink('race@test.com');
        $id = (int) $result->magicLinkId;
        $this->pdo->prepare('UPDATE magic_links SET token_hash = ?, match_attempts = ? WHERE id = ?')
            ->execute([password_hash('tok', PASSWORD_DEFAULT), AuthService::MAX_MATCH_ATTEMPTS, $id]);

        try {
            $this->authService->verifyMagicLink($id, 'tok', (string) $result->matchCode);
            $this->fail('No attempt is left: the code must not be compared.');
        } catch (\Core\Security\MagicLinkCodeMismatch $e) {
            $this->assertTrue($e->linkSpent);
        }
        $this->assertFalse($this->authService->isMagicLinkConfirmed($id));
    }

    public function testTheRightCodeConfirmsWhateverItsSpacing(): void
    {
        $this->userRepo->create('spaced@test.com');
        $result = $this->authService->requestMagicLink('spaced@test.com');
        $id = (int) $result->magicLinkId;
        $this->pdo->prepare('UPDATE magic_links SET token_hash = ? WHERE id = ?')
            ->execute([password_hash('tok', PASSWORD_DEFAULT), $id]);
        $code = (string) $result->matchCode;

        $verified = $this->authService->verifyMagicLink($id, 'tok', substr($code, 0, 3) . ' ' . substr($code, 3));

        $this->assertNotNull($verified);
        $this->assertTrue($this->authService->isMagicLinkConfirmed($id));
    }

    /**
     * Issue #1099: expired is expired, confirmed or not — the polling
     * session collects a link within its fifteen minutes, never after.
     */
    public function testAConfirmedButExpiredLinkIsNeitherConfirmedNorCollectable(): void
    {
        $user = $this->userRepo->create('late@test.com');
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, used, confirmed_at)
             VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            $this->encryption->blindIndex('late@test.com', 'email'),
            'hash',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->assertFalse($this->authService->isMagicLinkConfirmed($id));
        $this->assertNull($this->authService->getUserForConfirmedLink($id));
        $this->assertGreaterThan(0, $user->id);
    }

    /**
     * Issue #1530: a link confirmed for the window that asked to collect
     * (from another browser; the code check is the controller's and
     * AuthService's other tests') is collected once; one confirmed in that
     * window is collected by its own confirmation, and never by a poll.
     */
    public function testAConfirmedLinkIsCollectedOnceAndALinkConfirmedHereNeverByAPoll(): void
    {
        $this->userRepo->create('collect@test.com');

        $elsewhere = (int) $this->authService->requestMagicLink('collect@test.com')->magicLinkId;
        $this->pdo->prepare('UPDATE magic_links SET token_hash = ? WHERE id = ?')
            ->execute([password_hash('tok', PASSWORD_DEFAULT), $elsewhere]);
        $this->assertNotNull($this->authService->verifyMagicLink($elsewhere, 'tok', null));
        $this->assertSame('collect@test.com', $this->authService->collectConfirmedLink($elsewhere)?->email);
        $this->assertNull($this->authService->collectConfirmedLink($elsewhere));

        $here = (int) $this->authService->requestMagicLink('collect@test.com')->magicLinkId;
        $this->pdo->prepare('UPDATE magic_links SET token_hash = ? WHERE id = ?')
            ->execute([password_hash('tok', PASSWORD_DEFAULT), $here]);
        $this->assertNotNull($this->authService->verifyMagicLink($here, 'tok', null, true));
        $this->assertTrue($this->authService->isMagicLinkConfirmed($here));
        $this->assertNull($this->authService->collectConfirmedLink($here));
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

    // ── The e-mail leaves after the answer (issue #1267) ───────────────

    /**
     * A service whose mail counter and journal are the test's own, wired to
     * an AfterResponse that can finish the answer first.
     *
     * @param list<array<int, mixed>> $logged
     * @param list<string> $sentTo
     */
    private function serviceAfterTheAnswer(AfterResponse $after, array &$logged, array &$sentTo, bool $failing = false): AuthService
    {
        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(
            function (string $to) use (&$sentTo, $failing): void {
                if ($failing) {
                    throw new MailException('Destinataire refusé : ' . $to);
                }
                $sentTo[] = $to;
            }
        );
        $journal = $this->createStub(JournalService::class);
        $journal->method('log')->willReturnCallback(function (...$args) use (&$logged): void {
            $logged[] = $args;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $service = new AuthService(
            $connection,
            $this->encryption,
            $mail,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, TestTwig::create()),
            'https://example.com',
            'Test Unit'
        );
        $service->setJournalService($journal);
        $service->setAfterResponse($after);

        return $service;
    }

    private function finishingFirst(): AfterResponse
    {
        return new AfterResponse(static function (): void {
        }, static function (int $microseconds): void {
        }, static fn(int $min, int $max): int => $min);
    }

    public function testTheEmailIsNotSentBeforeTheAnswerHasBeenGiven(): void
    {
        $this->userRepo->create('user@test.com');
        $after = $this->finishingFirst();
        $logged = [];
        $sentTo = [];
        $service = $this->serviceAfterTheAnswer($after, $logged, $sentTo);

        $result = $service->requestMagicLink('user@test.com');

        $this->assertTrue($result->success);
        $this->assertSame([], $sentTo, 'no SMTP connection while the visitor is still waiting');

        $after->flush();

        $this->assertSame(['user@test.com'], $sentTo);
    }

    /** What the visitor waits for is the same thing for an address with an account and one without. */
    public function testBeforeTheAnswerAnAddressWithAndWithoutAnAccountDoTheSameThing(): void
    {
        $this->userRepo->create('known@test.com');
        $after = $this->finishingFirst();
        $logged = [];
        $sentTo = [];
        $service = $this->serviceAfterTheAnswer($after, $logged, $sentTo);

        $known = $service->requestMagicLink('known@test.com');
        $loggedForKnown = $logged;
        $logged = [];
        $unknown = $service->requestMagicLink('nobody@test.com');
        $loggedForUnknown = $logged;

        $this->assertSame([['core', 'magic_link_requested', 'info', 'Demande de lien magique', [], null]], $loggedForKnown);
        $this->assertSame($loggedForKnown, $loggedForUnknown, 'the same journal lines before the answer');
        $this->assertSame($known->success, $unknown->success);
        $this->assertSame($known->error, $unknown->error);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $known->matchCode);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $unknown->matchCode);
        $this->assertSame([], $sentTo);
    }

    public function testWhatBecameOfEachAddressIsJournaledAfterTheAnswer(): void
    {
        $account = $this->userRepo->create('known@test.com');
        $after = $this->finishingFirst();
        $logged = [];
        $sentTo = [];
        $service = $this->serviceAfterTheAnswer($after, $logged, $sentTo);

        $service->requestMagicLink('known@test.com');
        $service->requestMagicLink('nobody@test.com');
        $after->flush();

        $events = array_map(static fn(array $line): string => $line[1], $logged);
        $this->assertSame(
            ['magic_link_requested', 'magic_link_requested', 'magic_link_email_sent', 'magic_link_no_account_found'],
            $events
        );
        $this->assertSame($account->id, $logged[2][5]);
        $this->assertSame(['known@test.com'], $sentTo);
    }

    public function testAFailedSendAfterTheAnswerIsJournaledAndNeverAnswered(): void
    {
        $account = $this->userRepo->create('refused@test.com');
        $after = $this->finishingFirst();
        $logged = [];
        $sentTo = [];
        $service = $this->serviceAfterTheAnswer($after, $logged, $sentTo, failing: true);

        $result = $service->requestMagicLink('refused@test.com');
        $after->flush();

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $failure = array_values(array_filter($logged, static fn(array $line): bool => $line[1] === AuthService::EVENT_SEND_FAILED));
        $this->assertCount(1, $failure);
        $this->assertSame('Destinataire refusé : [adresse]', $failure[0][4]['error']);
        $this->assertSame($account->id, $failure[0][5]);
    }

    /** Where the answer cannot be finished first, every address waits for the same random time. */
    public function testWithoutAnEarlyFinishTheEmailIsSentInsideTheRequestAndEveryAddressWaits(): void
    {
        $this->userRepo->create('known@test.com');
        $sleeps = [];
        $after = new AfterResponse(
            null,
            static function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
            static fn(int $min, int $max): int => 1000,
            false,
            null,
            static fn(): float => 0.0
        );
        $logged = [];
        $sentTo = [];
        $service = $this->serviceAfterTheAnswer($after, $logged, $sentTo);

        $service->requestMagicLink('known@test.com');
        $this->assertSame(['known@test.com'], $sentTo);
        $service->requestMagicLink('nobody@test.com');

        $this->assertSame(['known@test.com'], $sentTo);
        $this->assertSame([1000000, 1000000], $sleeps, 'one wait for each request, with or without an account');
    }

    public function testAServiceWithNoAfterResponseSendsAtOnce(): void
    {
        $this->userRepo->create('user@test.com');

        $this->authService->requestMagicLink('user@test.com');

        $this->assertTrue($this->emailSent);
    }
}
