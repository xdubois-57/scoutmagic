<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\MemberEmailAddressController;
use Core\Http\Request;
use Core\Member\MemberEmailException;
use Core\Member\MemberEmailService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Member page "Adresses email" management — self-only, no chief/admin
 * bypass (module addendum). Every action re-verifies the requesting
 * account is linked to the member's current year regardless of the
 * route's role_min, so these tests exercise that boundary directly
 * against the controller rather than through the router.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberEmailAddressControllerTest extends TestCase
{
    private MemberService&\PHPUnit\Framework\MockObject\MockObject $memberService;
    private MemberEmailService&\PHPUnit\Framework\MockObject\MockObject $memberEmailService;
    private MemberEmailAddressController $controller;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->memberService = $this->createMock(MemberService::class);
        $this->memberEmailService = $this->createMock(MemberEmailService::class);
        $this->controller = new MemberEmailAddressController(
            $this->createMock(Environment::class),
            $this->memberEmailService,
            $this->memberService
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function startSessionWithCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'self@test.example', 'identified');

        return $token;
    }

    private function makeProfile(int $memberId): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 1, memberId: $memberId, deskId: 'D1', firstName: 'Jean', lastName: 'Dupont',
            totem: null, quali: null, gender: null, birthDate: null, phone: null, mobile: null, email: null,
            patrol: null, formationLevel: null, federationMailConsent: false, unitMailConsent: false,
            addresses: [], functions: [], scoutYearLabel: '2025-2026'
        );
    }

    // --- self-only RBAC boundary (canAccess(..., 'identified')) ---

    public function testAddIsForbiddenWhenNotLinkedToThisMemberYear(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberEmailService->expects($this->never())->method('addEmail');

        $response = $this->controller->add(
            new Request('POST', '/members/1/emails', [], ['email' => 'new@example.com', '_csrf_token' => $token], [], []),
            ['id' => '1']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAddSucceedsForTheMemberThemselves(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->once())
            ->method('addEmail')
            ->with(42, 'new@example.com', 1);

        $response = $this->controller->add(
            new Request('POST', '/members/1/emails', [], ['email' => 'new@example.com', '_csrf_token' => $token], [], []),
            ['id' => '1']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/members/1', $response->getHeaders()['Location']);
    }

    public function testAddRejectsAnInvalidCsrfTokenWithoutCallingTheService(): void
    {
        $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->never())->method('addEmail');

        $response = $this->controller->add(
            new Request('POST', '/members/1/emails', [], ['email' => 'new@example.com', '_csrf_token' => 'wrong'], [], []),
            ['id' => '1']
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testAddSurfacesAMemberEmailExceptionAsAFlashMessageNotAnError(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->method('addEmail')->willThrowException(new MemberEmailException('Adresse email invalide.'));

        $response = $this->controller->add(
            new Request('POST', '/members/1/emails', [], ['email' => 'bad', '_csrf_token' => $token], [], []),
            ['id' => '1']
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeleteIsForbiddenWhenNotLinkedToThisMemberYear(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberEmailService->expects($this->never())->method('deleteEmail');

        $response = $this->controller->delete(
            new Request('POST', '/members/1/emails/5/delete', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteSucceedsForTheMemberThemselves(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->once())->method('deleteEmail')->with(42, 5, 1);

        $response = $this->controller->delete(
            new Request('POST', '/members/1/emails/5/delete', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testResendIsForbiddenWhenNotLinkedToThisMemberYear(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberEmailService->expects($this->never())->method('resendConfirmation');

        $response = $this->controller->resend(
            new Request('POST', '/members/1/emails/5/resend', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReactivateIsForbiddenWhenNotLinkedToThisMemberYear(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberEmailService->expects($this->never())->method('reactivateEmail');

        $response = $this->controller->reactivate(
            new Request('POST', '/members/1/emails/5/reactivate', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReactivateSucceedsForTheMemberThemselves(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->once())->method('reactivateEmail')->with(42, 5, 1);

        $response = $this->controller->reactivate(
            new Request('POST', '/members/1/emails/5/reactivate', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    // ── lifting a block the site placed (roadmap IT-05, D19) ─────────

    /**
     * The route half of AGENTS.md's conjunctive rule: an action nobody
     * can reach is an action that does not exist, and the route table
     * lives in a procedural bootstrap no unit test loads — so it is read
     * at source, the same technique as
     * `Tests\Core\Mail\OutboundMailWiringTest`.
     *
     * `identified` and not `admin`: the whole point is that the person
     * inconvenienced can act, and the controller re-checks self-access on
     * every call regardless of what the route says.
     */
    public function testTheUnblockRouteIsRegisteredForTheMemberThemselves(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($source);

        $position = strpos($source, "'/members/{id}/emails/{email_id}/bounce-unblock'");
        $this->assertNotFalse($position, 'The unblock action must be reachable.');

        $declaration = substr($source, $position, 260);
        $this->assertStringContainsString("'unblockBounce'", $declaration);
        $this->assertStringContainsString("'identified'", $declaration);
    }

    public function testUnblockBounceIsForbiddenWhenNotLinkedToThisMemberYear(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberEmailService->expects($this->never())->method('unblockBounce');

        $response = $this->controller->unblockBounce(
            new Request('POST', '/members/1/emails/5/bounce-unblock', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnblockBounceSucceedsForTheMemberThemselves(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->once())->method('unblockBounce')
            ->with(42, 5)->willReturn(true);

        $response = $this->controller->unblockBounce(
            new Request('POST', '/members/1/emails/5/bounce-unblock', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/members/1', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    /**
     * **A no-op is said out loud**, as the admin path already said it.
     *
     * `unblockBounce()` returned `void` and the controller flashed
     * « Adresse réactivée » whatever happened — so a double-submit, a
     * back-button resubmit, a second guardian's stale tab, or a
     * super-admin who lifted the block first all reported a success over
     * nothing. None of those needs any bad intent to reach: the CSRF token
     * is not consumed on use.
     */
    public function testUnblockBounceSaysSoWhenThereWasNothingToLift(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->once())->method('unblockBounce')
            ->with(42, 5)->willReturn(false);

        $response = $this->controller->unblockBounce(
            new Request('POST', '/members/1/emails/5/bounce-unblock', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(302, $response->getStatusCode());

        $flash = \Core\Http\FlashMessage::get();
        $this->assertNotNull($flash);
        $this->assertSame('error', $flash['type'], 'nothing was lifted, so this is not a success.');
        $this->assertStringNotContainsString('réactivée', $flash['message']);
    }

    public function testUnblockBounceRejectsAStaleCsrfTokenWithoutCallingTheService(): void
    {
        $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->never())->method('unblockBounce');

        $response = $this->controller->unblockBounce(
            new Request('POST', '/members/1/emails/5/bounce-unblock', [], ['_csrf_token' => 'périmé'], [], []),
            ['id' => '1', 'email_id' => '5']
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * **Two buttons, two decisions.** `reactivate` undoes the member's own
     * « je ne veux plus rien recevoir ici »; this undoes a block the site
     * placed after bounces. One action doing both would let a parent
     * silently undo their unsubscribe while meaning to empty their
     * mailbox (D19).
     */
    public function testLiftingABlockNeverReactivatesAnAddressTheMemberSwitchedOff(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn($this->makeProfile(42));
        $this->memberEmailService->expects($this->never())->method('reactivateEmail');
        $this->memberEmailService->expects($this->once())->method('unblockBounce');

        $this->controller->unblockBounce(
            new Request('POST', '/members/1/emails/5/bounce-unblock', [], ['_csrf_token' => $token], [], []),
            ['id' => '1', 'email_id' => '5']
        );
    }

    public function testAddReturns403WhenTheMemberYearDoesNotExist(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willThrowException(new MemberNotFoundException('not found'));
        $this->memberEmailService->expects($this->never())->method('addEmail');

        $response = $this->controller->add(
            new Request('POST', '/members/1/emails', [], ['email' => 'new@example.com', '_csrf_token' => $token], [], []),
            ['id' => '1']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- confirm() (GET) — public, prefetch-safe: never mutates ---

    public function testConfirmGetNeverConfirmsEvenWithAValidToken(): void
    {
        $this->memberEmailService->method('canConfirmEmail')->with(7, 'goodtoken')->willReturn(true);
        $this->memberEmailService->expects($this->never())->method('confirmEmail');
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('members/email_confirmed.html.twig', ['state' => 'confirm', 'email_id' => 7, 'token' => 'goodtoken'])
            ->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirm(
            new Request('GET', '/members/emails/confirm/7', ['token' => 'goodtoken'], [], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConfirmGetRendersInvalidOnFailureWithoutThrowing(): void
    {
        $this->memberEmailService->method('canConfirmEmail')->willReturn(false);
        $this->memberEmailService->expects($this->never())->method('confirmEmail');
        $twig = $this->createMock(Environment::class);
        $twig->method('render')
            ->with('members/email_confirmed.html.twig', ['state' => 'invalid', 'email_id' => 999, 'token' => 'wrong'])
            ->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirm(
            new Request('GET', '/members/emails/confirm/999', ['token' => 'wrong'], [], [], []),
            ['id' => '999']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- confirmPost() (POST) — the only action that confirms ---

    public function testConfirmPostConfirmsWithABodyToken(): void
    {
        $this->memberEmailService->expects($this->once())
            ->method('confirmEmail')->with(7, 'goodtoken')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('members/email_confirmed.html.twig', ['state' => 'done'])
            ->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirmPost(
            new Request('POST', '/members/emails/confirm/7', [], ['token' => 'goodtoken'], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConfirmPostRendersInvalidOnFailureWithoutThrowing(): void
    {
        $this->memberEmailService->method('confirmEmail')->willReturn(false);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')
            ->with('members/email_confirmed.html.twig', ['state' => 'invalid'])
            ->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirmPost(
            new Request('POST', '/members/emails/confirm/999', [], ['token' => 'wrong'], [], []),
            ['id' => '999']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConfirmPostIgnoresAQueryStringToken(): void
    {
        // The token must ride the form body — a bare URL with ?token= that a
        // scanner "posts" without a body must not confirm on this shape.
        $this->memberEmailService->expects($this->never())->method('confirmEmail');
        $twig = $this->createMock(Environment::class);
        $twig->method('render')
            ->with('members/email_confirmed.html.twig', ['state' => 'invalid'])
            ->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirmPost(
            new Request('POST', '/members/emails/confirm/7', ['token' => 'goodtoken'], [], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
