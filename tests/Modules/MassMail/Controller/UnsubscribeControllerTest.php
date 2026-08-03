<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Http\Request;
use Core\Member\MemberEmailService;
use Modules\MassMail\Controller\UnsubscribeController;
use Modules\MassMail\Repository\RecipientRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The one-click unsubscribe flow (module addendum, RFC 8058) — public, no
 * session, token-authenticated. Two properties matter most here: the GET
 * confirmation page must never mutate anything (email clients/antivirus
 * routinely prefetch every link in an email), and the POST action must be
 * idempotent (a mailbox prefetch of the one-click target, a real click,
 * and a resubmit may all reach it for the same recipient).
 */
class UnsubscribeControllerTest extends TestCase
{
    private RecipientRepository&\PHPUnit\Framework\MockObject\MockObject $recipientRepository;
    private MemberEmailService&\PHPUnit\Framework\MockObject\MockObject $memberEmailService;
    private UnsubscribeController $controller;

    protected function setUp(): void
    {
        $this->recipientRepository = $this->createMock(RecipientRepository::class);
        $this->memberEmailService = $this->createMock(MemberEmailService::class);
        $this->controller = new UnsubscribeController(
            $this->createMock(Environment::class),
            $this->recipientRepository,
            $this->memberEmailService
        );
    }

    private function makeRecipient(?int $memberEmailId): \Modules\MassMail\Repository\Recipient
    {
        return new \Modules\MassMail\Repository\Recipient(
            id: 10, emailId: 1, memberId: 1, scoutYearId: 1, emailAddress: 'someone@example.com',
            memberEmailId: $memberEmailId, status: 'sent', errorMessage: null, sentAt: '2026-01-01 00:00:00',
            attempts: 1, createdAt: '2026-01-01 00:00:00'
        );
    }

    // --- show() (GET) — prefetch-safe: never mutates ---

    public function testShowNeverCallsUnsubscribeEvenWithAValidToken(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->with(10, 'goodtoken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->never())->method('unsubscribe');

        $response = $this->controller->show(
            new Request('GET', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowWithAnInvalidTokenNeverCallsUnsubscribeEither(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->willReturn(null);
        $this->memberEmailService->expects($this->never())->method('unsubscribe');

        $response = $this->controller->show(
            new Request('GET', '/mass-mail/unsubscribe/10', ['token' => 'badtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- unsubscribe() (POST) — the only action that mutates, idempotent ---

    public function testUnsubscribePostWithAValidTokenCallsUnsubscribeOnce(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->with(10, 'goodtoken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->once())->method('unsubscribe')->with(5);

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * RFC 8058's List-Unsubscribe-Post target has no CSRF token — the
     * mailbox provider has no session to have gotten one from. This must
     * still work with the token supplied only via the query string (as
     * the List-Unsubscribe header URL carries it), no request body at all.
     */
    public function testUnsubscribePostAcceptsATokenFromTheQueryStringAlone(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->with(10, 'goodtoken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->once())->method('unsubscribe')->with(5);

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsubscribePostFallsBackToABodyToken(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->with(10, 'formtoken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->once())->method('unsubscribe')->with(5);

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', [], ['token' => 'formtoken'], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsubscribePostWithAnInvalidTokenNeverCallsUnsubscribe(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->willReturn(null);
        $this->memberEmailService->expects($this->never())->method('unsubscribe');

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'badtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsubscribePostCallingTwiceCallsTheIdempotentServiceMethodTwice(): void
    {
        // Idempotency itself lives in MemberEmailService::unsubscribe() —
        // see MemberEmailServiceTest::testUnsubscribeIsIdempotent(). This
        // only confirms the controller doesn't add its own state (e.g. a
        // "already handled" guard that would make a genuine resubmit fail).
        $this->recipientRepository->method('verifyUnsubscribeToken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->exactly(2))->method('unsubscribe')->with(5);

        $request = new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []);
        $this->controller->unsubscribe($request, ['id' => '10']);
        $response = $this->controller->unsubscribe($request, ['id' => '10']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsubscribePostWithNoMemberEmailIdNeverCallsUnsubscribe(): void
    {
        // Defensive: a recipient row created for the "no usable address at
        // all" error branch has no member_email_id and never reaches
        // 'pending'/'sent' in practice, but the controller must not crash
        // or act on it if it somehow does.
        $this->recipientRepository->method('verifyUnsubscribeToken')->willReturn($this->makeRecipient(null));
        $this->memberEmailService->expects($this->never())->method('unsubscribe');

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
