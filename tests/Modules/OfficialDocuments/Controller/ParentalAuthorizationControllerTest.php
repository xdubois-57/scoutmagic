<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Controller;

use Core\Http\Request;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\OfficialDocuments\Controller\ParentalAuthorizationController;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Service\ParentalAuthorizationPdfService;
use Modules\OfficialDocuments\Service\ParentalAuthorizationService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The boundary, exercised against the controller directly rather than
 * through the router — because `role_min: identified` is not the boundary.
 *
 * What decides is whether the account asking is linked to THIS member, and
 * the chantier makes that strict: no chief and no administrator bypass,
 * whatever their role. So the interesting case is not « a public visitor is
 * refused » (the guard answers that before any of this runs) but « another
 * identified member is refused », which is the one a role check cannot see.
 */
final class ParentalAuthorizationControllerTest extends TestCase
{
    private MemberService&\PHPUnit\Framework\MockObject\MockObject $memberService;
    private UserAccountRepository&\PHPUnit\Framework\MockObject\Stub $userAccounts;
    private ParentalAuthorizationService&\PHPUnit\Framework\MockObject\Stub $authorizationService;
    private ParentalAuthorizationController $controller;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        // Stubs rather than mocks where nothing is expected OF them: they
        // stand in for a collaborator, they are not what is under test.
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $this->memberService = $this->createMock(MemberService::class);
        $this->userAccounts = $this->createStub(UserAccountRepository::class);
        $this->authorizationService = $this->createStub(ParentalAuthorizationService::class);

        $this->controller = new ParentalAuthorizationController(
            $twig,
            $this->memberService,
            $this->userAccounts,
            $this->authorizationService,
            new ParentalAuthorizationPdfService(TemplateLibrary::shipped()),
            null
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function signIn(string $email = 'parent@example.be'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, $email, 'identified');

        return $token;
    }

    private static function profile(): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 7,
            memberId: 42,
            deskId: 'D42',
            firstName: 'Loup',
            lastName: 'Dubois',
            totem: 'Akéla',
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [new MemberFunctionInfo('Animé', 'identified', 'Louveteaux', 'Meute', 'MEU', true, null, null)],
            scoutYearLabel: '2026-2027'
        );
    }

    /**
     * @return array<string, string>
     */
    private static function filledForm(string $token): array
    {
        return [
            '_csrf_token' => $token,
            'signatory_name' => 'Xavier Dubois',
            'capacity' => 'father',
            'start_date' => '2026-11-14',
            'end_date' => '2026-11-16',
            'place' => 'Verviers',
        ];
    }

    // --- the boundary ---

    /**
     * The case `role_min` cannot express: an identified account that is
     * simply not this child's. Both actions refuse it.
     */
    public function testAnIdentifiedAccountThatIsNotThisMembersIsRefused(): void
    {
        $token = $this->signIn('quelquun@example.be');
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberService->expects($this->never())->method('getMemberProfile');

        $shown = $this->controller->show(
            new Request('GET', '/members/7/autorisation-parentale', [], [], [], []),
            ['id' => '7']
        );
        $downloaded = $this->controller->download(
            new Request('POST', '/members/7/autorisation-parentale', [], self::filledForm($token), [], []),
            ['id' => '7']
        );

        $this->assertSame(403, $shown->getStatusCode());
        $this->assertSame(403, $downloaded->getStatusCode());
    }

    /**
     * The literal `'identified'` is what makes it strict: passing the
     * caller's own role would let `canAccess()`'s chief/admin branch answer
     * true for somebody else's child. A chief signed in as a chief must be
     * asked the same question as anybody else.
     */
    public function testTheAccessQuestionIsAskedAsIdentifiedWhateverTheCallersRole(): void
    {
        $this->signIn();
        AuthSession::setRole('admin');

        $this->memberService->expects($this->once())
            ->method('canAccess')
            ->with('parent@example.be', 7, 'identified')
            ->willReturn(false);

        $this->controller->show(
            new Request('GET', '/members/7/autorisation-parentale', [], [], [], []),
            ['id' => '7']
        );
    }

    public function testTheMembersOwnHouseholdReachesTheScreen(): void
    {
        $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());
        $this->userAccounts->method('findById')->willReturn(self::account());
        $this->authorizationService->method('defaultPlaceFor')->willReturn('Verviers');

        $response = $this->controller->show(
            new Request('GET', '/members/7/autorisation-parentale', [], [], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- the CSRF wall ---

    public function testAPostWithoutAValidTokenProducesNoDocument(): void
    {
        $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $body = self::filledForm('mauvais-jeton');
        $response = $this->controller->download(
            new Request('POST', '/members/7/autorisation-parentale', [], $body, [], []),
            ['id' => '7']
        );

        $this->assertNotSame('application/pdf', $response->getHeaders()['Content-Type'] ?? null);
    }

    // --- the document ---

    public function testAFilledFormComesBackAsAPdfAttachment(): void
    {
        $token = $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());
        $this->memberService->method('getScoutYearIdForMemberYear')->willReturn(3);
        $this->authorizationService->method('responsableFor')->willReturn(null);
        $this->authorizationService->method('unitLabel')->willReturn('LgVI/25 — 25e SV');

        $response = $this->controller->download(
            new Request('POST', '/members/7/autorisation-parentale', [], self::filledForm($token), [], []),
            ['id' => '7']
        );

        $headers = $response->getHeaders();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $headers['Content-Type']);
        $this->assertStringContainsString('attachment;', $headers['Content-Disposition']);
        $this->assertStringStartsWith('%PDF-', $response->getBody());
        // A document naming a family is not something a shared cache keeps.
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
    }

    /**
     * A refusal re-renders the screen rather than redirecting, so the parent
     * does not retype a form they already filled in — and no document is
     * produced from an incomplete one.
     */
    public function testAnIncompleteFormComesBackAsTheScreenAndNotAsAPdf(): void
    {
        $token = $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $body = self::filledForm($token);
        $body['place'] = '';

        $response = $this->controller->download(
            new Request('POST', '/members/7/autorisation-parentale', [], $body, [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame('application/pdf', $response->getHeaders()['Content-Type'] ?? null);
    }

    /**
     * With the calendar module disabled the picker simply is not there, and
     * the screen works unchanged — the §7.5 contract, exercised rather than
     * asserted in a docblock. The controller in this class is built with a
     * null lookup throughout, so every other test here covers the same
     * thing; this one says so out loud.
     */
    public function testTheScreenWorksWithTheCalendarModuleDisabled(): void
    {
        $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());
        $this->userAccounts->method('findById')->willReturn(self::account());
        $this->authorizationService->method('defaultPlaceFor')->willReturn('');

        $response = $this->controller->show(
            new Request('GET', '/members/7/autorisation-parentale', [], [], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    private static function account(): UserAccount
    {
        return new UserAccount(1, 'parent@example.be', 'Xavier', 'Dubois', null, false, null);
    }
}
