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
use Core\Member\MemberService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\SessionRevalidator;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

/**
 * A magic link identifies the address it was sent to, and only that address
 * (SECURITY.md §2, ARCHITECTURE.md §8.27).
 *
 * The regression this pins is a real one. A member's confirmed secondary
 * address (`member_emails`) had no `user_accounts` row of its own, so a
 * magic link requested from one was deliberately stored against — and
 * confirmed as — the account behind that member's PRIMARY address: the mail
 * went to the right mailbox, and the session that came back was somebody
 * else's. Whoever held one confirmed address on one member got that
 * account's identity, its role (a super-admin flag included), its password
 * and passkeys, and every member linked to it.
 *
 * This test walks the whole flow through the real classes — request, mail,
 * click, session, and the scoping every page derives from it — because the
 * bug did not live in any single one of them: `AuthService` resolved the
 * account, `AuthController` established the session from whatever address it
 * was handed, and `RoleResolver`/`MemberService` then scoped correctly to an
 * identity that was already wrong. A test at one layer would have passed
 * while the flow as a whole stayed broken.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SecondaryEmailLoginIdentityTest extends TestCase
{
    private const PRIMARY_EMAIL = 'parent@test.com';
    private const SECONDARY_EMAIL = 'parent+alias@test.com';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private AuthController $controller;
    private AuthService $authService;
    private MemberService $memberService;
    private RoleResolver $roleResolver;
    private UserAccountRepository $userRepo;
    private int $scoutYearId;
    private int $primaryAccountId;
    /** @var int[] the four member_year ids carrying the primary address */
    private array $primaryMemberYearIds = [];
    /** The one member_year the secondary address is attached to. */
    private int $sharedMemberYearId;
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
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $memberEmailRepo = new MemberEmailRepository($this->pdo, $this->encryption);
        $memberYearRepo = new MemberYearRepository($this->pdo);

        // The primary address is a super-admin account linked to four
        // members — exactly the shape that made the old behaviour so sharp:
        // confirming a link sent to one child's secondary address handed
        // back the whole household AND the top role of the site.
        $this->primaryAccountId = $this->userRepo->create(self::PRIMARY_EMAIL, true)->id;
        foreach (['CHILD_A', 'CHILD_B', 'CHILD_C', 'CHILD_D'] as $deskId) {
            $this->primaryMemberYearIds[] = $this->createMember($deskId, self::PRIMARY_EMAIL);
        }
        $this->sharedMemberYearId = $this->primaryMemberYearIds[0];
        $this->addValidSecondaryEmail($this->memberIdOf($this->sharedMemberYearId), self::SECONDARY_EMAIL);

        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $bodyHtml, string $bodyText): void {
                $this->sentMail = ['to' => $to, 'subject' => $subject, 'body' => $bodyText !== '' ? $bodyText : $bodyHtml];
            }
        );

        $twig = $this->buildTwig();

        $this->authService = new AuthService(
            $connection,
            $this->encryption,
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
            'https://example.test',
            'Test Unit'
        );
        $this->roleResolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo, $memberEmailRepo);
        $this->memberService = new MemberService(
            $memberYearRepo,
            $this->encryption,
            $connection,
            null,
            $memberEmailRepo
        );

        $this->controller = new AuthController(
            $twig,
            $this->authService,
            $this->roleResolver,
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
     * The whole round trip, as the reporter walked it: request the link from
     * the secondary address, click it, and end up identified as that address
     * — never as the account behind the member's primary one.
     */
    public function testConfirmingALinkSentToASecondaryAddressLogsInAsThatAddress(): void
    {
        $verifyResponse = $this->requestAndConfirmLink(self::SECONDARY_EMAIL);

        // The mail went to the address that was typed, and the link row
        // carries that same address's blind index — the identity it confers
        // and what MagicLinkRepository::countRecentByEmail() rate-limits on.
        $this->assertSame(self::SECONDARY_EMAIL, $this->sentMail['to'] ?? null);
        $this->assertSame(
            $this->encryption->blindIndex(self::SECONDARY_EMAIL, 'email'),
            $this->pdo->query('SELECT email_blind_index FROM magic_links ORDER BY id DESC LIMIT 1')->fetchColumn()
        );

        $this->assertStringContainsString('Connexion confirmée', $verifyResponse->getBody());

        // The session is the secondary address, on its own account.
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(self::SECONDARY_EMAIL, AuthSession::getEmail());
        $this->assertNotSame($this->primaryAccountId, AuthSession::getUserAccountId());

        $created = $this->userRepo->findByEmail(self::SECONDARY_EMAIL);
        $this->assertNotNull($created);
        $this->assertSame($created->id, AuthSession::getUserAccountId());

        // The primary account's super-admin flag is not inherited: the role
        // is whatever the members THIS address reaches actually carry.
        $this->assertFalse($created->isSuperAdmin);
        $this->assertSame('identified', AuthSession::getRole());
    }

    /**
     * The scope the session grants: the one member the address is attached
     * to, never the three siblings that only the primary address reaches.
     */
    public function testTheSessionSeesOnlyTheMemberThatAddressIsAttachedTo(): void
    {
        $this->requestAndConfirmLink(self::SECONDARY_EMAIL);

        $this->assertSame([$this->sharedMemberYearId], AuthSession::getLinkedMembers());

        $linked = $this->memberService->getLinkedMembers(self::SECONDARY_EMAIL, $this->scoutYearId);
        $this->assertCount(1, $linked);
        $this->assertSame($this->sharedMemberYearId, $linked[0]->memberYearId);

        foreach ($this->primaryMemberYearIds as $memberYearId) {
            $expected = $memberYearId === $this->sharedMemberYearId;
            $this->assertSame(
                $expected,
                $this->memberService->canAccess(self::SECONDARY_EMAIL, $memberYearId, 'identified'),
                "canAccess() for member_year {$memberYearId}"
            );
            $this->assertSame(
                $expected,
                $this->memberService->isLinkedToMember(self::SECONDARY_EMAIL, $this->memberIdOf($memberYearId), $this->scoutYearId),
                "isLinkedToMember() for member_year {$memberYearId}"
            );
        }

        // ...while the primary address itself still reaches all four.
        $this->assertCount(4, $this->memberService->getLinkedMembers(self::PRIMARY_EMAIL, $this->scoutYearId));
    }

    /**
     * The device that ASKED for the link collects the same session by
     * polling — a second, independent place where a link becomes an
     * identity, and one a fix applied only to /auth/verify would miss.
     */
    public function testTheRequestingDeviceCollectsTheSameIdentityByPolling(): void
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink(self::SECONDARY_EMAIL);
        $deviceA = $_SESSION;

        // Device B (the mailbox) confirms the link in its own session —
        // and is NOT identified by doing so: only the window that asked
        // for the link is (Tests\Integration\MagicLinkWindowIdentityTest).
        $_SESSION = [];
        $this->controller->verifyMagicLink($this->verifyRequestFor($magicLinkId), []);
        $this->assertFalse(AuthSession::isAuthenticated());

        // Back on device A, which was never authenticated.
        $_SESSION = $deviceA;
        $this->assertFalse(AuthSession::isAuthenticated());

        $response = $this->controller->pollMagicLink(new Request('GET', '/auth/poll/' . $magicLinkId, [], [], [], []), ['id' => (string) $magicLinkId]);

        $this->assertTrue(json_decode($response->getBody(), true)['confirmed']);
        $this->assertSame(self::SECONDARY_EMAIL, AuthSession::getEmail());
        $this->assertNotSame($this->primaryAccountId, AuthSession::getUserAccountId());
        $this->assertSame([$this->sharedMemberYearId], AuthSession::getLinkedMembers());
    }

    /**
     * Deactivating the address ends its access with no extra machinery: the
     * account row it was given survives (like any account whose membership
     * ended), and the membership check every request re-runs is what closes
     * the door — on a live session, and on any new link.
     */
    public function testDeactivatingTheAddressEndsItsAccess(): void
    {
        $this->requestAndConfirmLink(self::SECONDARY_EMAIL);
        $this->assertTrue(AuthSession::isAuthenticated());

        $this->pdo->exec("UPDATE member_emails SET status = 'inactive'");

        $revalidator = new SessionRevalidator($this->userRepo, $this->roleResolver);
        $this->assertFalse($revalidator->revalidate(fn(): int => $this->scoutYearId));
        $this->assertFalse(AuthSession::isAuthenticated());

        // And a fresh link never establishes a session again.
        $response = $this->requestAndConfirmLink(self::SECONDARY_EMAIL);
        $this->assertStringContainsString('Compte non autorisé', $response->getBody());
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    /**
     * A 'pending' address — added but never confirmed from its own mailbox —
     * is not an identity at all: no mail, and nothing written.
     */
    public function testAnUnconfirmedAddressNeverReceivesALinkNorGetsAnAccount(): void
    {
        $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', 'pending')"
        )->execute([
            $this->memberIdOf($this->sharedMemberYearId),
            $this->encryption->encrypt('stranger@test.com', 'member_emails.email'),
            $this->encryption->blindIndex('stranger@test.com', 'email'),
        ]);

        $this->startTestSession();
        $this->requestLink('stranger@test.com');

        $this->assertNull($this->sentMail);
        $this->assertNull($this->userRepo->findByEmail('stranger@test.com'));
    }

    /**
     * Requesting from a secondary address is rate-limited like any other:
     * the link rows and the counter must agree on which blind index they
     * are about, or the 5/hour ceiling silently stops applying (SECURITY.md
     * §8 — this is how the old resolved-index storage broke it).
     */
    public function testRequestsFromASecondaryAddressAreRateLimited(): void
    {
        $this->startTestSession();

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->authService->requestMagicLink(self::SECONDARY_EMAIL)->success);
        }

        $this->assertFalse($this->authService->requestMagicLink(self::SECONDARY_EMAIL)->success);
    }

    /**
     * Drives the real controller end to end: POST /login/magic-link, read
     * the emailed URL, then GET /auth/verify with what it carried.
     */
    private function requestAndConfirmLink(string $email): \Core\Http\Response
    {
        $this->startTestSession();
        $magicLinkId = $this->requestLink($email);

        return $this->controller->verifyMagicLink($this->verifyRequestFor($magicLinkId), []);
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
     * The link exactly as the mail carried it — the token is read back out
     * of the rendered email, never from the database, so the test exercises
     * the same URL a member would click.
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

    private function addValidSecondaryEmail(int $memberId, string $email): void
    {
        $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', 'valid')"
        )->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
    }

    private function memberIdOf(int $memberYearId): int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);

        return (int) $stmt->fetchColumn();
    }

    private function buildTwig(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );

        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn(): string => 'test-csrf-token'));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn(): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('editable', fn(): string => '', ['is_safe' => ['html']]));
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));
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
