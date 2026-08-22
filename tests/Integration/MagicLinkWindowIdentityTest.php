<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Mail\MailService;
use Core\Member\MemberEmailRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Confirming a magic link is not logging in.
 *
 * A magic link travels by email, and an email is opened wherever the mail
 * happens to be read: a shared family tablet, a work laptop, a webmail on
 * somebody else's machine, a corporate link scanner that follows every
 * URL before the human ever sees it. GET /auth/verify used to create a
 * session right there, so each of those ended up holding a live session
 * for the address — silently, and for as long as the session lasted —
 * while the person who actually asked to log in got their own session
 * too.
 *
 * The session now belongs to the WINDOW THAT ASKED for the link, and to
 * nothing else: /auth/verify confirms the address and says so, and
 * GET /auth/poll/{id} — which only the requesting session may call
 * (Core\Security\PendingMagicLink) — is what establishes the identity.
 * The one exception is the same session doing both, which is the
 * ordinary "ask on my phone, open the mail app on my phone" case and
 * grants nothing that session was not already about to collect.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MagicLinkWindowIdentityTest extends TestCase
{
    private const EMAIL = 'parent@test.com';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private AuthController $controller;
    private int $scoutYearId;
    /** @var array{to: string, subject: string, body: string}|null */
    private ?array $sentMail = null;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $connection = Connection::withPdo($this->pdo);
        $userRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $memberEmailRepo = new MemberEmailRepository($this->pdo, $this->encryption);
        $memberYearRepo = new MemberYearRepository($this->pdo);

        $userRepo->create(self::EMAIL, false);
        $this->createMember('CHILD_A', self::EMAIL);

        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $bodyHtml, string $bodyText): void {
                $this->sentMail = ['to' => $to, 'subject' => $subject, 'body' => $bodyText !== '' ? $bodyText : $bodyHtml];
            }
        );

        $twig = $this->buildTwig();

        $this->controller = new AuthController(
            $twig,
            new AuthService($connection, $this->encryption, $mailService, $twig, 'https://example.test', 'Test Unit'),
            new RoleResolver($memberYearRepo, $this->encryption, $this->pdo, $memberEmailRepo),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                $memberYearRepo
            )
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->sentMail = null;
    }

    /**
     * The reported case, and the reason this file exists: the mailbox is
     * open somewhere else, the link is clicked there, and THAT browser
     * must stay exactly as anonymous as it was.
     */
    public function testTheBrowserThatOpensTheLinkIsNotIdentified(): void
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink(self::EMAIL);

        // Another browser entirely: no session state of its own.
        $_SESSION = [];

        $response = $this->controller->verifyMagicLink($this->verifyRequestFor($magicLinkId), []);

        $this->assertStringContainsString('Connexion confirmée', $response->getBody());
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertNull(AuthSession::getEmail());
        // …and it says where the session actually is, rather than
        // offering a "continuer" that would land on the login page.
        $this->assertStringContainsString('Retournez à la fenêtre', $response->getBody());
    }

    /**
     * The other half of the same rule: the window that asked IS
     * identified, by polling — the link is confirmed and usable, it just
     * confers its identity in exactly one place.
     */
    public function testTheWindowThatAskedForTheLinkIsTheOneIdentified(): void
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink(self::EMAIL);
        $requestingSession = $_SESSION;

        // Confirmed elsewhere…
        $_SESSION = [];
        $this->controller->verifyMagicLink($this->verifyRequestFor($magicLinkId), []);
        $this->assertFalse(AuthSession::isAuthenticated());

        // …collected here.
        $_SESSION = $requestingSession;
        $this->assertFalse(AuthSession::isAuthenticated());

        $response = $this->controller->pollMagicLink(
            new Request('GET', '/auth/poll/' . $magicLinkId, [], [], [], []),
            ['id' => (string) $magicLinkId]
        );

        $this->assertTrue(json_decode($response->getBody(), true)['confirmed']);
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(self::EMAIL, AuthSession::getEmail());
    }

    /**
     * Opening the link in the very session that asked for it — request on
     * a phone, open the mail app on the same phone — still signs that
     * session in. It grants nothing new: that session's own poll was
     * about to do exactly this, and it has now additionally proven it
     * holds the emailed token.
     */
    public function testTheSessionThatAskedForTheLinkIsSignedInWhenItOpensItItself(): void
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink(self::EMAIL);

        $response = $this->controller->verifyMagicLink($this->verifyRequestFor($magicLinkId), []);

        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(self::EMAIL, AuthSession::getEmail());
        $this->assertStringContainsString('continuer sur ce navigateur', $response->getBody());
    }

    /**
     * A second browser cannot collect what the first one asked for: the
     * poll endpoint answers "not confirmed yet" to anyone but the
     * requesting session, whatever the id (Core\Security\PendingMagicLink).
     * Without that, "the browser opening the link is not identified"
     * would be trivially worked around by polling for the id.
     */
    public function testAnotherBrowserCannotCollectTheSessionByPolling(): void
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink(self::EMAIL);

        $_SESSION = [];
        $this->controller->verifyMagicLink($this->verifyRequestFor($magicLinkId), []);

        $response = $this->controller->pollMagicLink(
            new Request('GET', '/auth/poll/' . $magicLinkId, [], [], [], []),
            ['id' => (string) $magicLinkId]
        );

        $this->assertFalse(json_decode($response->getBody(), true)['confirmed']);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    /**
     * The link stays single-use: confirming it in the mailbox's browser
     * spends it there, so replaying the same URL identifies nobody, in
     * any session — including the one that asked.
     */
    public function testAConfirmedLinkCannotBeReplayed(): void
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink(self::EMAIL);
        $request = $this->verifyRequestFor($magicLinkId);
        $requestingSession = $_SESSION;

        $_SESSION = [];
        $this->controller->verifyMagicLink($request, []);

        $_SESSION = $requestingSession;
        $response = $this->controller->verifyMagicLink($request, []);

        $this->assertStringContainsString('Lien invalide', $response->getBody());
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    private function requestLink(string $email): int
    {
        $this->sentMail = null;
        $csrfToken = CsrfGuard::generateToken();

        $response = $this->controller->requestMagicLink(
            new Request('POST', '/login/magic-link', [], [
                'email' => $email,
                '_csrf_token' => $csrfToken,
                'rgpd_consent' => '1',
            ], [], []),
            []
        );

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success'], 'magic link request refused: ' . ($body['error'] ?? ''));

        return (int) $body['poll_id'];
    }

    /**
     * The URL exactly as the mail carried it — token read back out of the
     * rendered email, never from the database.
     */
    private function verifyRequestFor(int $magicLinkId): Request
    {
        $this->assertNotNull($this->sentMail, 'no magic link email was sent');
        preg_match('/token=([a-f0-9]+)&(?:amp;)?id=(\d+)/', (string) $this->sentMail['body'], $matches);
        $this->assertNotEmpty($matches, 'the email carried no usable magic link URL');
        $this->assertSame($magicLinkId, (int) $matches[2]);

        return new Request('GET', '/auth/verify', ['token' => $matches[1], 'id' => $matches[2]], [], [], []);
    }

    private function createMember(string $deskId, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($deskId, 'member_years.first_name'),
            $this->encryption->encrypt('Test', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function buildTwig(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );

        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn(): string => 'test-csrf-token'));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn(): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('editable', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn(): string => ''));

        return $twig;
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }
}
