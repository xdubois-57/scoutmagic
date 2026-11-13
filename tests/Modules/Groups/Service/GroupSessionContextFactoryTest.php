<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\UserAccountRepository;
use Modules\Groups\Service\GroupSessionContextFactory;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1124: the context every groups decision is taken on — the linked
 * members a group's membership is checked against, the year a past group is
 * read-only relative to — is built in the authorization year, never in a
 * chief's year preview, which is chosen by the very person it would
 * authorise and accepts any year that ever existed.
 */
class GroupSessionContextFactoryTest extends TestCase
{
    private const CURRENT_YEAR = 7;
    private const PAST_YEAR = 3;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAPreviewedYearGivesNeitherItsLinkedMembersNorItsYear(): void
    {
        ScoutYearSession::setPreview(self::PAST_YEAR);

        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear(self::PAST_YEAR, '2019-2020', null));
        $resolver->method('getAuthorizationYear')->willReturn(
            new EffectiveScoutYear(self::CURRENT_YEAR, '2025-2026', null)
        );

        // The address was a child's parent in 2019-2020 (member 42), and is
        // linked to member 5 now.
        $members = $this->createStub(MemberService::class);
        $members->method('getLinkedMembers')->willReturnCallback(
            fn(string $email, int $yearId): array => [self::profile($yearId === self::PAST_YEAR ? 42 : 5)]
        );

        $context = (new GroupSessionContextFactory(
            $members,
            $this->createStub(UserAccountRepository::class),
            $resolver
        ))->build('chef@test.be', 'chief', null);

        $this->assertSame(self::CURRENT_YEAR, $context->effectiveScoutYearId);
        $this->assertSame([5], $context->linkedMemberIds);
    }

    private static function profile(int $memberId): MemberProfile
    {
        return new MemberProfile(
            $memberId * 100, $memberId, 'DESK' . $memberId, 'Marie', 'Dupont', null,
            null, null, null, null, null, null, null, null, false, false, [], [], '2025-2026'
        );
    }
}
