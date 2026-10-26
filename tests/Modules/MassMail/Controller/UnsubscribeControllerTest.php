<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Http\Request;
use Core\Member\MemberEmailService;
use Modules\MassMail\Controller\UnsubscribeController;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The one-click unsubscribe flow (module addendum, RFC 8058) — public, no
 * session, token-authenticated. Two properties matter most here: the GET
 * confirmation page must never mutate anything (email clients/antivirus
 * routinely prefetch every link in an email), and the POST action must be
 * idempotent (a mailbox prefetch of the one-click target, a real click,
 * and a resubmit may all reach it for the same message). Since issue #999
 * the token and the `{id}` of the two routes are a MESSAGE's — one address.
 */
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class UnsubscribeControllerTest extends TestCase
{
    private DeliveryRepository&\PHPUnit\Framework\MockObject\MockObject $recipientRepository;
    private MemberEmailService&\PHPUnit\Framework\MockObject\MockObject $memberEmailService;
    private SuppressedAddressRepository&\PHPUnit\Framework\MockObject\MockObject $suppressedAddressRepository;
    private UnsubscribeController $controller;

    protected function setUp(): void
    {
        $this->recipientRepository = $this->createMock(DeliveryRepository::class);
        $this->memberEmailService = $this->createMock(MemberEmailService::class);
        $this->suppressedAddressRepository = $this->createMock(SuppressedAddressRepository::class);
        $this->controller = new UnsubscribeController(
            $this->createStub(Environment::class),
            $this->recipientRepository,
            $this->memberEmailService,
            $this->suppressedAddressRepository
        );
    }

    private function makeRecipient(?int $memberEmailId): Delivery
    {
        return new Delivery(
            id: 10, emailId: 1, audienceRowId: null, emailAddress: 'someone@example.com',
            memberEmailId: $memberEmailId, status: 'sent', errorMessage: null, sentAt: '2026-01-01 00:00:00',
            attempts: 1, createdAt: '2026-01-01 00:00:00'
        );
    }

    // --- show() (GET) — prefetch-safe: never mutates ---

    public function testShowNeverCallsUnsubscribeEvenWithAValidToken(): void
    {
        $this->recipientRepository->expects($this->once())->method('verifyUnsubscribeToken')->with(10, 'goodtoken')->willReturn($this->makeRecipient(5));
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
        $this->recipientRepository->expects($this->once())->method('verifyUnsubscribeToken')->with(10, 'goodtoken')->willReturn($this->makeRecipient(5));
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
        $this->recipientRepository->expects($this->once())->method('verifyUnsubscribeToken')->with(10, 'goodtoken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->once())->method('unsubscribe')->with(5);

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsubscribePostFallsBackToABodyToken(): void
    {
        $this->recipientRepository->expects($this->once())->method('verifyUnsubscribeToken')->with(10, 'formtoken')->willReturn($this->makeRecipient(5));
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

    /**
     * A member's message suppresses its address as well as deactivating
     * the member_emails row: what the person asked to stop is the address.
     */
    public function testUnsubscribePostForAMembersMessageAlsoSuppressesTheAddress(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->willReturn($this->makeRecipient(5));
        $this->memberEmailService->expects($this->once())->method('unsubscribe')->with(5);
        $this->suppressedAddressRepository->expects($this->once())->method('suppress')->with('someone@example.com');

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- external mail-merge recipients (no member at all) ---

    public function testUnsubscribePostForAnExternalRecipientSuppressesTheAddress(): void
    {
        // A mail-merge row addressed by its "Email" column: no member, no
        // member_emails row to flip — the address lands on the module's
        // own suppression list instead.
        $this->recipientRepository->expects($this->once())->method('verifyUnsubscribeToken')->with(10, 'goodtoken')
            ->willReturn($this->makeRecipient(null));
        $this->memberEmailService->expects($this->never())->method('unsubscribe');
        $this->suppressedAddressRepository->expects($this->once())->method('suppress')->with('someone@example.com');

        $response = $this->controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowForAnExternalRecipientNeverSuppresses(): void
    {
        $this->recipientRepository->method('verifyUnsubscribeToken')->willReturn($this->makeRecipient(null));
        $this->suppressedAddressRepository->expects($this->never())->method('suppress');

        $response = $this->controller->show(
            new Request('GET', '/mass-mail/unsubscribe/10', ['token' => 'goodtoken'], [], [], []),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
