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

    // --- confirm() — public, unauthenticated, must fail gracefully ---

    public function testConfirmRendersConfirmedTrueOnSuccess(): void
    {
        $this->memberEmailService->method('confirmEmail')->with(7, 'goodtoken')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('members/email_confirmed.html.twig', ['confirmed' => true])
            ->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirm(
            new Request('GET', '/members/emails/confirm/7', ['token' => 'goodtoken'], [], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConfirmRendersConfirmedFalseOnFailureWithoutThrowing(): void
    {
        $this->memberEmailService->method('confirmEmail')->willReturn(false);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->with('members/email_confirmed.html.twig', ['confirmed' => false])->willReturn('<html></html>');
        $controller = new MemberEmailAddressController($twig, $this->memberEmailService, $this->memberService);

        $response = $controller->confirm(
            new Request('GET', '/members/emails/confirm/999', ['token' => 'wrong'], [], [], []),
            ['id' => '999']
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
