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
use Modules\OfficialDocuments\Controller\HealthSheetController;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Security\OwnMemberOnly;
use Modules\OfficialDocuments\Service\HealthSheetService;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The boundary the router cannot see, on the three routes carrying a
 * child's health data.
 *
 * `role_min: identified` only says somebody is signed in;
 * `OfficialDocumentsRbacTest` proves that floor. What is left — and what
 * this file is for — is that an identified account which is NOT this
 * member's is refused, on every one of the three, including the one that
 * destroys data.
 */
final class HealthSheetControllerTest extends TestCase
{
    private MemberService&\PHPUnit\Framework\MockObject\MockObject $memberService;
    private HealthSheetRepository&\PHPUnit\Framework\MockObject\MockObject $repository;
    private HealthSheetController $controller;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $this->memberService = $this->createMock(MemberService::class);
        $this->repository = $this->createMock(HealthSheetRepository::class);

        $this->controller = new HealthSheetController(
            $twig,
            new OwnMemberOnly($this->memberService),
            new HealthSheetService($this->repository)
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
            totem: null,
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

    // --- the boundary ---

    /**
     * The case `role_min` cannot express, on all three routes: an
     * identified account that is simply not this child's. Nothing is shown,
     * nothing is saved, and — the one that would be worst — nothing is
     * deleted.
     */
    public function testAnIdentifiedAccountThatIsNotThisMembersIsRefusedEverywhere(): void
    {
        $token = $this->signIn('quelquun@example.be');
        $this->memberService->method('canAccess')->willReturn(false);
        $this->memberService->expects($this->never())->method('getMemberProfile');
        $this->repository->expects($this->never())->method('save');
        $this->repository->expects($this->never())->method('delete');

        $shown = $this->controller->show(
            new Request('GET', '/members/7/fiche-sante', [], [], [], []),
            ['id' => '7']
        );
        $saved = $this->controller->save(
            new Request('POST', '/members/7/fiche-sante', [], ['_csrf_token' => $token], [], []),
            ['id' => '7']
        );
        $cleared = $this->controller->clear(
            new Request('POST', '/members/7/fiche-sante/effacer', [], ['_csrf_token' => $token], [], []),
            ['id' => '7']
        );

        $this->assertSame(403, $shown->getStatusCode());
        $this->assertSame(403, $saved->getStatusCode());
        $this->assertSame(403, $cleared->getStatusCode());
    }

    /**
     * The literal `'identified'` is what makes it strict: passing the
     * caller's own role would let `canAccess()`'s chief/admin branch answer
     * true for somebody else's child. A chief signed in as a chief must be
     * asked the same question as anybody else — on the health sheet more
     * than anywhere.
     */
    public function testTheAccessQuestionIsAskedAsIdentifiedWhateverTheCallersRole(): void
    {
        $this->signIn();
        AuthSession::setRole('admin');

        $this->memberService->expects($this->once())
            ->method('canAccess')
            ->with('parent@example.be', 7, 'identified')
            ->willReturn(false);

        $this->controller->show(new Request('GET', '/members/7/fiche-sante', [], [], [], []), ['id' => '7']);
    }

    public function testTheMembersOwnHouseholdReachesTheScreen(): void
    {
        $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $response = $this->controller->show(
            new Request('GET', '/members/7/fiche-sante', [], [], [], []),
            ['id' => '7']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- the CSRF wall, on both writing routes ---

    public function testAPostWithoutAValidTokenSavesNothing(): void
    {
        $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());
        $this->repository->expects($this->never())->method('save');

        $this->controller->save(
            new Request('POST', '/members/7/fiche-sante', [], ['_csrf_token' => 'mauvais'], [], []),
            ['id' => '7']
        );
    }

    public function testAPostWithoutAValidTokenDeletesNothing(): void
    {
        $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());
        $this->repository->expects($this->never())->method('delete');

        $this->controller->clear(
            new Request('POST', '/members/7/fiche-sante/effacer', [], ['_csrf_token' => 'mauvais'], [], []),
            ['id' => '7']
        );
    }

    // --- saving ---

    /**
     * The sheet is stored against the member's PERSISTENT id, not the
     * member-year the URL carries. Getting this backwards would file a
     * child's health data under a number that changes every September —
     * and would look perfectly fine for one season.
     */
    public function testTheSheetIsStoredAgainstThePersistentMemberId(): void
    {
        $token = $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $this->repository->expects($this->once())
            ->method('save')
            ->with(
                42, // memberId, not memberYearId (7)
                $this->callback(static fn(HealthSheet $s): bool => $s->allergies === 'Arachides'),
                $this->anything()
            );

        $this->controller->save(
            new Request('POST', '/members/7/fiche-sante', [], [
                '_csrf_token' => $token,
                'allergies' => 'Arachides',
            ], [], []),
            ['id' => '7']
        );
    }

    /**
     * An empty form saves an empty sheet rather than being refused: every
     * field is optional, and a family clearing one field at a time must be
     * able to end up with nothing.
     */
    public function testAnEmptyFormIsSavedRatherThanRefused(): void
    {
        $token = $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $this->repository->expects($this->once())
            ->method('save')
            ->with(42, $this->callback(static fn(HealthSheet $s): bool => $s->isEmpty()), $this->anything());

        $response = $this->controller->save(
            new Request('POST', '/members/7/fiche-sante', [], ['_csrf_token' => $token], [], []),
            ['id' => '7']
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    // --- clearing ---

    public function testClearingRemovesTheSheetAndComesBackToTheScreen(): void
    {
        $token = $this->signIn();
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $this->repository->expects($this->once())->method('delete')->with(42)->willReturn(true);

        $response = $this->controller->clear(
            new Request('POST', '/members/7/fiche-sante/effacer', [], ['_csrf_token' => $token], [], []),
            ['id' => '7']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/members/7/fiche-sante', $response->getHeaders()['Location'] ?? null);
    }

    /**
     * Clearing a sheet that was not there answers exactly as clearing one
     * that was. « Il n'y avait rien à effacer » would be the site telling
     * whoever is at the keyboard something about this child's record.
     */
    public function testClearingSaysTheSameThingWhetherThereWasASheetOrNot(): void
    {
        $this->memberService->method('canAccess')->willReturn(true);
        $this->memberService->method('getMemberProfile')->willReturn(self::profile());

        $token = $this->signIn();
        $this->repository->method('delete')->willReturn(false);
        $onNothing = $this->controller->clear(
            new Request('POST', '/members/7/fiche-sante/effacer', [], ['_csrf_token' => $token], [], []),
            ['id' => '7']
        );

        $this->assertSame(302, $onNothing->getStatusCode());
        $this->assertSame('/members/7/fiche-sante', $onNothing->getHeaders()['Location'] ?? null);
    }
}
