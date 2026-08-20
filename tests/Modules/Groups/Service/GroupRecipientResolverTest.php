<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\ScoutYearService;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionMembershipRepository;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupRecipientResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Who a group notification actually reaches.
 *
 * Everything here is about the same three properties: the audience is
 * membership rather than a role, it is read at dispatch time rather than
 * at post time, and one account gets one notification however many of its
 * members are in the group.
 *
 * @group database
 */
#[Group('database')]
class GroupRecipientResolverTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private GroupRepository $groupRepo;
    private GroupMemberRepository $groupMemberRepo;
    private int $currentYearId;
    private int $sectionId;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->groupMemberRepo = new GroupMemberRepository($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupId = $this->groupRepo->create('Louveteaux', $this->currentYearId, $this->sectionId, 1);
        (new GroupSectionRepository($this->pdo))->add($this->groupId, $this->sectionId);
    }

    public function testADerivedSectionMemberIsARecipient(): void
    {
        $memberId = $this->memberInSection('DERIVED', 'derived@test.be');

        $this->assertSame(
            [$this->accountIdFor('derived@test.be')],
            $this->accountIds($this->resolver()->forGroup($this->group(), $this->currentYearId))
        );
    }

    public function testAnExplicitlyInvitedMemberIsARecipientWithNoSectionPeriodAtAll(): void
    {
        $memberId = $this->memberWithAccount('INVITED', 'invited@test.be');
        $this->groupMemberRepo->add($this->groupId, $memberId);

        $this->assertSame(
            [$this->accountIdFor('invited@test.be')],
            $this->accountIds($this->resolver()->forGroup($this->group(), $this->currentYearId))
        );
    }

    /**
     * The pitfall this whole design exists for: recipients are resolved
     * now, not captured when the post was written. A member whose period
     * was for another year is simply not in this year's audience.
     */
    public function testAMemberWhoseSectionPeriodIsForAnotherYearIsNotARecipient(): void
    {
        $otherYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $memberId = $this->memberWithAccount('LEFT', 'left@test.be');
        $this->addPeriod($memberId, $this->sectionId, $otherYearId);

        $this->assertSame([], $this->resolver()->forGroup($this->group(), $this->currentYearId));
    }

    public function testAMemberOfADifferentSectionIsNotARecipient(): void
    {
        $otherSectionId = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
        $memberId = $this->memberWithAccount('ELSEWHERE', 'elsewhere@test.be');
        $this->addPeriod($memberId, $otherSectionId, $this->currentYearId);

        $this->assertSame([], $this->resolver()->forGroup($this->group(), $this->currentYearId));
    }

    /**
     * A parent with two children in the same section is the ordinary
     * case, not the edge case — and must be told once, not twice.
     */
    public function testAnAccountLinkedToTwoMembersOfTheGroupIsListedExactlyOnce(): void
    {
        $this->memberInSection('CHILD1', 'parent@test.be');
        $this->memberInSection('CHILD2', 'parent@test.be');

        $recipients = $this->resolver()->forGroup($this->group(), $this->currentYearId);

        $this->assertCount(1, $recipients);
        $this->assertSame($this->accountIdFor('parent@test.be'), $recipients[0]['userAccountId']);
    }

    /**
     * A member in both an explicit row AND the linked section is one
     * member, so still one recipient.
     */
    public function testAMemberWhoIsBothInvitedAndDerivedIsListedOnce(): void
    {
        $memberId = $this->memberInSection('BOTH', 'both@test.be');
        $this->groupMemberRepo->add($this->groupId, $memberId);

        $this->assertCount(1, $this->resolver()->forGroup($this->group(), $this->currentYearId));
    }

    /**
     * A young member with no login of their own is not an error — they
     * simply have nobody to notify.
     */
    public function testAMemberWithNoUserAccountIsSilentlySkipped(): void
    {
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'NOACCOUNT', $this->sectionId, $this->currentYearId);
        $this->addMemberYear($memberId, 'noaccount@test.be');

        $this->assertSame([], $this->resolver()->forGroup($this->group(), $this->currentYearId));
    }

    /**
     * A member whose account is under a confirmed SECONDARY address, not
     * their Desk one — the case the module spec calls out explicitly.
     */
    public function testAValidSecondaryEmailResolvesTheAccount(): void
    {
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SECOND', $this->sectionId, $this->currentYearId);
        $this->addMemberYear($memberId, 'desk@test.be');
        $this->addSecondaryEmail($memberId, 'perso@test.be', 'valid');
        $accountId = $this->createAccount('perso@test.be');

        $this->assertSame(
            [$accountId],
            $this->accountIds($this->resolver()->forGroup($this->group(), $this->currentYearId))
        );
    }

    /**
     * A 'pending' or 'inactive' secondary address never resolves a login
     * (Core\Security\RoleResolver) and must not resolve a notification
     * either — otherwise an unconfirmed address would start receiving a
     * private group's traffic.
     */
    public function testAnUnconfirmedSecondaryEmailDoesNotResolveTheAccount(): void
    {
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'PENDING', $this->sectionId, $this->currentYearId);
        $this->addMemberYear($memberId, 'desk-only@test.be');
        $this->addSecondaryEmail($memberId, 'unconfirmed@test.be', 'pending');
        $this->createAccount('unconfirmed@test.be');

        $this->assertSame([], $this->resolver()->forGroup($this->group(), $this->currentYearId));
    }

    public function testExcludingDropsTheNamedAccount(): void
    {
        $this->memberInSection('A', 'a@test.be');
        $this->memberInSection('B', 'b@test.be');
        $actor = $this->accountIdFor('a@test.be');

        $recipients = $this->resolver()->forGroupExcluding($this->group(), $this->currentYearId, [$actor]);

        $this->assertSame([$this->accountIdFor('b@test.be')], $this->accountIds($recipients));
    }

    public function testIsCurrentMemberFollowsTheSameRuleAsForGroup(): void
    {
        $inside = $this->memberInSection('INSIDE', 'inside@test.be');
        $outside = $this->memberWithAccount('OUTSIDE', 'outside@test.be');

        $resolver = $this->resolver();
        $this->assertTrue($resolver->isCurrentMember($this->group(), $inside, $this->currentYearId));
        $this->assertFalse($resolver->isCurrentMember($this->group(), $outside, $this->currentYearId));
    }

    // ---- moderators ----

    public function testModeratorsForReachesOnlyTheExplicitModerators(): void
    {
        $this->memberInSection('PLAIN', 'plain@test.be');
        $moderator = $this->memberInSection('MOD', 'mod@test.be');
        $this->groupMemberRepo->add($this->groupId, $moderator, true);

        $this->assertSame(
            [$this->accountIdFor('mod@test.be')],
            $this->accountIds($this->resolver()->moderatorsFor($this->group()))
        );
    }

    public function testAnInvitedNonModeratorMemberIsNotAModeratorRecipient(): void
    {
        $member = $this->memberWithAccount('INVITED', 'invited@test.be');
        $this->groupMemberRepo->add($this->groupId, $member, false);

        $this->assertSame([], $this->resolver()->moderatorsFor($this->group()));
    }

    /**
     * A site admin moderates every group without holding a row in any of
     * them — the module's one deliberate bypass (GroupSessionContext::
     * isSiteAdmin()), and "who is told about a report" must match "who may
     * act on one" exactly.
     */
    public function testASiteAdminIsAModeratorRecipientWithoutAnyGroupRow(): void
    {
        $moderator = $this->memberInSection('MOD', 'mod@test.be');
        $this->groupMemberRepo->add($this->groupId, $moderator, true);
        $adminAccountId = $this->createAccount('admin@test.be');

        $roleResolver = $this->createStub(RoleResolver::class);
        $roleResolver->method('resolve')->willReturnCallback(
            static fn(string $email): string => $email === 'admin@test.be' ? 'admin' : 'identified'
        );

        $recipients = $this->accountIds($this->resolver($roleResolver)->moderatorsFor($this->group()));

        sort($recipients);
        $expected = [$this->accountIdFor('mod@test.be'), $adminAccountId];
        sort($expected);
        $this->assertSame($expected, $recipients);
    }

    public function testAnAdminWhoIsAlsoAnExplicitModeratorIsListedOnce(): void
    {
        $moderator = $this->memberInSection('MOD', 'mod@test.be');
        $this->groupMemberRepo->add($this->groupId, $moderator, true);

        $roleResolver = $this->createStub(RoleResolver::class);
        $roleResolver->method('resolve')->willReturn('admin');

        $this->assertCount(1, $this->resolver($roleResolver)->moderatorsFor($this->group()));
    }

    /**
     * Built without a RoleResolver — a narrow unit test's escape hatch,
     * never the composition root — the resolver under-notifies rather
     * than guessing: the explicit moderators still hear about it.
     */
    public function testWithoutARoleResolverOnlyExplicitModeratorsAreResolved(): void
    {
        $moderator = $this->memberInSection('MOD', 'mod@test.be');
        $this->groupMemberRepo->add($this->groupId, $moderator, true);
        $this->createAccount('admin@test.be');

        $this->assertSame(
            [$this->accountIdFor('mod@test.be')],
            $this->accountIds($this->resolver()->moderatorsFor($this->group()))
        );
    }

    // ---- fixtures ----

    private function resolver(?RoleResolver $roleResolver = null): GroupRecipientResolver
    {
        $scoutYearService = null;
        if ($roleResolver !== null) {
            $scoutYearService = new ScoutYearService($this->pdo);
        }

        return new GroupRecipientResolver(
            $this->groupMemberRepo,
            new GroupSectionRepository($this->pdo),
            new SectionMembershipRepository($this->pdo),
            new MemberYearRepository($this->pdo),
            new MemberEmailRepository($this->pdo, $this->encryption),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->encryption,
            $roleResolver,
            $scoutYearService
        );
    }

    private function group(): DiscussionGroup
    {
        $group = $this->groupRepo->findById($this->groupId);
        \assert($group !== null);

        return $group;
    }

    /**
     * @param array<int, array{userAccountId: int, memberId: ?int}> $recipients
     * @return int[]
     */
    private function accountIds(array $recipients): array
    {
        return array_map(static fn(array $r): int => $r['userAccountId'], $recipients);
    }

    /** A member with a period in the group's section, plus an account. */
    private function memberInSection(string $deskId, string $email): int
    {
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, $deskId, $this->sectionId, $this->currentYearId);
        $this->addMemberYear($memberId, $email);
        $this->createAccount($email);

        return $memberId;
    }

    /** A member with an account but no section period anywhere. */
    private function memberWithAccount(string $deskId, string $email): int
    {
        $memberId = GroupsTestHelper::createMember($this->pdo, $deskId);
        $this->addMemberYear($memberId, $email);
        $this->createAccount($email);

        return $memberId;
    }

    private function addMemberYear(int $memberId, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->blindIndex($email, 'email'),
            '2026-01-01 00:00:00',
        ]);
    }

    private function addSecondaryEmail(int $memberId, string $email, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->encryption->blindIndex($email, 'email'),
            'manual',
            $status,
            '2026-01-01 00:00:00',
        ]);
    }

    private function createAccount(string $email): int
    {
        $existing = $this->accountIdFor($email);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $this->encryption->encrypt($email, 'user_accounts.email'),
            $this->encryption->blindIndex($email, 'email'),
            '2026-01-01 00:00:00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function accountIdFor(string $email): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$this->encryption->blindIndex($email, 'email')]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function addPeriod(int $memberId, int $sectionId, int $scoutYearId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$memberId, $sectionId, $scoutYearId, '2025-09-01', '2026-01-01 00:00:00']);
    }
}
