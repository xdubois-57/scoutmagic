<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\SectionMembershipRepository;
use Core\Security\Role;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class GroupListServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupListService $listService;
    private GroupReadRepository $readRepo;
    private GroupRepository $groupRepo;
    private GroupService $groupService;
    private int $currentYearId;
    private int $pastYearId;
    private int $sectionId;
    private int $otherSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $groupRepo = new GroupRepository($this->pdo);
        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $membershipRepo = new SectionMembershipRepository($this->pdo);

        $this->readRepo = new GroupReadRepository($this->pdo);
        $this->listService = new GroupListService(
            $groupRepo,
            $sectionRepo,
            $memberRepo,
            $membershipRepo,
            new PostRepository($this->pdo),
            new ReplyRepository($this->pdo),
            $this->readRepo
        );
        $this->groupService = new GroupService($groupRepo, $sectionRepo, $memberRepo);
        $this->groupRepo = $groupRepo;

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $this->otherSectionId = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
    }

    /**
     * @param int[] $memberIds
     */
    private function context(array $memberIds, string $role = 'identified'): GroupSessionContext
    {
        return new GroupSessionContext(1, Role::fromString($role), $memberIds, $this->currentYearId, true);
    }

    /**
     * @param GroupListItem[] $items
     * @return string[]
     */
    private function names(array $items): array
    {
        return array_map(fn(GroupListItem $i) => $i->group->name, $items);
    }

    public function testCurrentListsOnlyGroupsTheCallerBelongsTo(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C1');
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $this->groupService->createSectionGroup('Éclaireurs', $this->otherSectionId, $this->currentYearId, $creator);

        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $this->sectionId, $this->currentYearId);

        $this->assertSame(['Louveteaux'], $this->names($this->listService->findCurrent($this->context([$member]))));
    }

    public function testAPastYearGroupLandsInArchivesNotInTheCurrentList(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C2');
        $this->groupService->createSectionGroup('Louveteaux 24-25', $this->sectionId, $this->pastYearId, $creator);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M2', $this->sectionId, $this->pastYearId);

        $context = $this->context([$member]);

        $this->assertSame([], $this->names($this->listService->findCurrent($context)));
        $this->assertSame(['Louveteaux 24-25'], $this->names($this->listService->findArchived($context)));
    }

    public function testAYearLessInvitationGroupIsNeverArchived(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3');
        $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);

        $context = $this->context([$creator]);

        $this->assertSame(['Groupe de travail'], $this->names($this->listService->findCurrent($context)));
        $this->assertSame([], $this->listService->findArchived($context));
    }

    // ── The headcount on the card ───────────────────────────────────────

    /** @param GroupListItem[] $items */
    private function headcountOf(array $items, string $name): int
    {
        foreach ($items as $item) {
            if ($item->group->name === $name) {
                return $item->memberCount;
            }
        }
        self::fail("No group named {$name} in the list.");
    }

    public function testASectionGroupCountsTheWholeSectionNotItsInvitedRows(): void
    {
        // The failure this exists to prevent: discussion_group_members on
        // a section group holds its moderator and almost nobody else, so
        // the row count would print « 1 membre » next to a group of four.
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'C10', $this->sectionId, $this->currentYearId);
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M10', $this->sectionId, $this->currentYearId);
        GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M11', $this->sectionId, $this->currentYearId);
        GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M12', $this->sectionId, $this->currentYearId);

        $items = $this->listService->findCurrent($this->context([$creator]));

        $this->assertSame(4, $this->headcountOf($items, 'Louveteaux'));
    }

    public function testSomebodyBothInvitedAndInTheSectionIsCountedOnce(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'C11', $this->sectionId, $this->currentYearId);
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $both = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M13', $this->sectionId, $this->currentYearId);
        (new GroupMemberRepository($this->pdo))->add($groupId, $both);

        $this->assertSame(2, $this->headcountOf($this->listService->findCurrent($this->context([$creator])), 'Louveteaux'));
    }

    public function testAnInvitationGroupCountsThePeopleInvitedToIt(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C12');
        $groupId = $this->groupService->createInvitationGroup('Coordination du camp', null, $creator);
        $repo = new GroupMemberRepository($this->pdo);
        $repo->add($groupId, GroupsTestHelper::createMember($this->pdo, 'M14'));
        $repo->add($groupId, GroupsTestHelper::createMember($this->pdo, 'M15'));

        $this->assertSame(
            3,
            $this->headcountOf($this->listService->findCurrent($this->context([$creator])), 'Coordination du camp')
        );
    }

    public function testAPastYearGroupKeepsTheHeadcountItHadThatYear(): void
    {
        // Not this year's roster: an archived group is a record of who was
        // there, and resolving its sections against the current year would
        // quietly rewrite that.
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'C13', $this->sectionId, $this->pastYearId);
        $this->groupService->createSectionGroup('Louveteaux 24-25', $this->sectionId, $this->pastYearId, $creator);
        GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M16', $this->sectionId, $this->pastYearId);
        // Three newcomers, this year only. They were never in that group.
        foreach (['M17', 'M18', 'M19'] as $deskId) {
            GroupsTestHelper::createMemberWithPeriod($this->pdo, $deskId, $this->sectionId, $this->currentYearId);
        }

        $items = $this->listService->findArchived($this->context([$creator]));

        $this->assertSame(2, $this->headcountOf($items, 'Louveteaux 24-25'));
    }

    public function testAGroupNobodyIsInCountsZeroRatherThanFailing(): void
    {
        // A site admin sees every group, including one whose section has
        // nobody in it yet — a section group created the day the section
        // was, before the first Desk import.
        $admin = GroupsTestHelper::createMember($this->pdo, 'A1');
        // Straight through the repositories: createSectionGroup() would
        // add its creator as the first moderator, which is exactly the
        // one member this test needs there not to be.
        $groupId = $this->groupRepo->create('Éclaireurs', $this->currentYearId, $this->otherSectionId, null);
        (new GroupSectionRepository($this->pdo))->add($groupId, $this->otherSectionId);

        $items = $this->listService->findCurrent($this->context([$admin], 'superadmin'));

        $this->assertSame(0, $this->headcountOf($items, 'Éclaireurs'));
    }

    public function testTheModeratorFlagIsResolvedForTheList(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4');
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $plain = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M3', $this->sectionId, $this->currentYearId);

        $this->assertTrue($this->listService->findCurrent($this->context([$creator]))[0]->isModerator);
        $this->assertFalse($this->listService->findCurrent($this->context([$plain]))[0]->isModerator);
    }

    public function testASiteAdminSeesEveryGroup(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C5');
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'ADM');

        $this->assertCount(2, $this->listService->findCurrent($this->context([$stranger], 'admin')));
    }

    public function testTheListMatchesGroupAccessServiceForEachGroup(): void
    {
        // The list decides membership in PHP from batched data instead of
        // asking the access service per group — the two must never drift.
        $creator = GroupsTestHelper::createMember($this->pdo, 'C6');
        $groupRepo = new GroupRepository($this->pdo);
        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new \Modules\Groups\Service\GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));

        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $this->groupService->createSectionGroup('Éclaireurs', $this->otherSectionId, $this->currentYearId, $creator);
        $invitationId = $this->groupService->createInvitationGroup('Invitation', null, $creator);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M4', $this->sectionId, $this->currentYearId);
        $this->groupService->inviteMember($groupRepo->findById($invitationId), $member, $creator);

        $context = $this->context([$member]);
        $fromList = $this->names(array_merge($this->listService->findCurrent($context), $this->listService->findArchived($context)));

        $fromAccess = [];
        foreach ($groupRepo->findAll() as $group) {
            if ($access->canRead($group, $context)) {
                $fromAccess[] = $group->name;
            }
        }

        sort($fromList);
        sort($fromAccess);
        $this->assertSame($fromAccess, $fromList);
    }

    // --- unread ----------------------------------------------------------
    //
    // « Nouveau » means a message or a reply somebody else wrote since the
    // reader's last visit (issue #1349) — never the activity clock, which
    // a reaction or a reopening also moves.

    /** A post by somebody other than the reader, at $at. */
    private function postBy(int $groupId, int $authorMemberId, string $at = '2030-01-01 12:00:00'): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Bonjour', $at, 99, $authorMemberId);
    }

    /** Moves only the activity clock — what a reaction or a reopening does. */
    private function withActivity(int $groupId, string $at = '2030-01-01 12:00:00'): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$at, $groupId]);
    }

    private function unread(int $memberId, string $role = 'identified'): bool
    {
        return $this->listService->findCurrent($this->context([$memberId], $role))[0]->hasUnread;
    }

    /** An invitation group of $creator's, with $other invited. */
    private function groupOfTwo(int $creator, int $other): int
    {
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->groupService->inviteMember($this->groupRepo->findById($groupId), $other, $creator);

        return $groupId;
    }

    public function testAPostBySomebodyElseAndNoReadMarkCountsAsUnread(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U1');
        $other = GroupsTestHelper::createMember($this->pdo, 'U1B');
        $groupId = $this->groupOfTwo($creator, $other);
        $this->postBy($groupId, $other);

        $this->assertTrue($this->unread($creator));
    }

    /**
     * Otherwise a brand-new empty group would announce itself as "new"
     * forever, and the badge would mean "exists" rather than "has
     * something you have not seen".
     */
    public function testAGroupWithNoContentIsNotUnread(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U2');
        $this->groupService->createInvitationGroup('Tout neuf', null, $creator);

        $this->assertFalse($this->unread($creator));
    }

    public function testOpeningTheGroupClearsTheUnreadFlag(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U3');
        $other = GroupsTestHelper::createMember($this->pdo, 'U3B');
        $groupId = $this->groupOfTwo($creator, $other);
        $this->postBy($groupId, $other, '2030-01-01 12:00:00');

        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:00:01');

        $this->assertFalse($this->unread($creator));
    }

    public function testANewPostAfterTheLastVisitMakesItUnreadAgain(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U4');
        $other = GroupsTestHelper::createMember($this->pdo, 'U4B');
        $groupId = $this->groupOfTwo($creator, $other);
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:00:00');
        $this->postBy($groupId, $other, '2030-01-02 09:00:00');

        $this->assertTrue($this->unread($creator));
    }

    /** A reply is content as much as a post is — the thread badge already counts them. */
    public function testANewReplyAfterTheLastVisitMakesItUnreadAgain(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U11');
        $other = GroupsTestHelper::createMember($this->pdo, 'U11B');
        $groupId = $this->groupOfTwo($creator, $other);
        $postId = $this->postBy($groupId, $creator, '2030-01-01 10:00:00');
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Oui', '2030-01-02 09:00:00', 99, $other);

        $this->assertTrue($this->unread($creator));
    }

    /**
     * Issue #1349, the defect itself. A reaction — added, changed or taken
     * back — bumps the group's activity (ReactionService::toggle()), and
     * that used to be what the badge read: it came back with nothing new
     * to read. A reopening resets the same clock (GroupMembershipService::
     * reopen()). Neither is content, so neither brings the badge back.
     */
    public function testActivityWithoutNewContentDoesNotBringTheBadgeBack(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U12');
        $other = GroupsTestHelper::createMember($this->pdo, 'U12B');
        $groupId = $this->groupOfTwo($creator, $other);
        $postId = $this->postBy($groupId, $other, '2030-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Oui', '2030-01-01 10:05:00', 99, $other);
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:00:00');

        $this->pdo->prepare(
            'INSERT INTO discussion_group_post_reactions (post_id, member_id, reaction_key, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$postId, $other, 'thumbs_up', '2030-01-02 09:00:00']);
        $this->withActivity($groupId, '2030-01-02 09:00:00');

        $this->assertFalse($this->unread($creator), 'a reaction is not something new to read');

        $this->withActivity($groupId, '2030-01-03 09:00:00');

        $this->assertFalse($this->unread($creator), 'a reopening is not something new to read either');
    }

    /** The same for a group never opened: activity alone, and no content, is no badge. */
    public function testAGroupWithActivityButNoContentIsNotUnread(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U13');
        $groupId = $this->groupService->createInvitationGroup('Rouvert', null, $creator);
        $this->withActivity($groupId);

        $this->assertFalse($this->unread($creator));
    }

    /** Your own words are never news to you — the thread badges already say so. */
    public function testTheReadersOwnContentIsNeverNew(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U14');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $postId = $this->postBy($groupId, $creator);
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Moi aussi', '2030-01-01 12:05:00', 99, $creator);

        $this->assertFalse($this->unread($creator));
    }

    /**
     * A hidden post or reply is not something the reader would find on
     * opening the group, so it cannot be what the badge announces — the
     * same rule as the thread badges and the home band's counters.
     */
    public function testHiddenContentProducesNoBadge(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U15');
        $other = GroupsTestHelper::createMember($this->pdo, 'U15B');
        $groupId = $this->groupOfTwo($creator, $other);
        $ownPost = $this->postBy($groupId, $creator, '2030-01-01 10:00:00');
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 11:00:00');

        $hiddenPost = $this->postBy($groupId, $other, '2030-01-02 09:00:00');
        (new PostRepository($this->pdo))->setHiddenAt($hiddenPost, '2030-01-02 10:00:00');
        $hiddenReply = GroupsTestHelper::createReplyAt($this->pdo, $ownPost, 'Masqué', '2030-01-02 09:30:00', 99, $other);
        (new ReplyRepository($this->pdo))->setHiddenAt($hiddenReply, '2030-01-02 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $hiddenPost, 'Sous un message masqué', '2030-01-02 09:45:00', 99, $other);

        $this->assertFalse($this->unread($creator));
    }

    /**
     * One query for the whole list, so one group's content must never
     * light up another's badge.
     */
    public function testEachGroupIsJudgedOnItsOwnContent(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U16');
        $other = GroupsTestHelper::createMember($this->pdo, 'U16B');
        $this->groupService->createInvitationGroup('Calme', null, $creator);
        $busy = $this->groupOfTwo($creator, $other);
        $this->postBy($busy, $other);

        $byName = [];
        foreach ($this->listService->findCurrent($this->context([$creator])) as $item) {
            $byName[$item->group->name] = $item->hasUnread;
        }
        ksort($byName);

        $this->assertSame(['Calme' => false, 'Groupe de travail' => true], $byName);
    }

    /**
     * A past-year group is a read-only archive: « Nouveau » there is not a
     * call to action. Decided by the service, so the list and the home
     * band cannot disagree (it used to be the controller's alone).
     */
    public function testAnArchivedGroupNeverCarriesTheBadge(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'U17', $this->sectionId, $this->pastYearId);
        $other = GroupsTestHelper::createMember($this->pdo, 'U17B');
        $groupId = $this->groupService->createSectionGroup('Louveteaux 24-25', $this->sectionId, $this->pastYearId, $creator);
        $this->postBy($groupId, $other);

        $archived = $this->listService->findArchived($this->context([$creator]));

        $this->assertCount(1, $archived);
        $this->assertFalse($archived[0]->hasUnread);
    }

    /**
     * An account linked to two members of the same group has ONE reading
     * position — opening it as either one clears the badge.
     */
    public function testAReadMarkFromAnyLinkedMemberClearsTheBadge(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U5');
        $second = GroupsTestHelper::createMember($this->pdo, 'U6');
        $other = GroupsTestHelper::createMember($this->pdo, 'U5B');
        $groupId = $this->groupOfTwo($creator, $second);
        $this->groupService->inviteMember($this->groupRepo->findById($groupId), $other, $creator);
        $this->postBy($groupId, $other, '2030-01-01 12:00:00');

        $this->readRepo->markRead($groupId, $second, '2030-01-01 12:00:01');

        $this->assertFalse($this->listService->findCurrent($this->context([$creator, $second]))[0]->hasUnread);
    }

    /**
     * Issue #712. A site admin SEES every group and is a moderator of each,
     * but opening one a member of theirs does not belong to records no
     * reading position (Service\GroupReadStateService — an admin looking
     * in must not mark a group read for somebody else). A « Nouveau » badge
     * on such a group could therefore never be cleared: it was permanent,
     * page refresh included.
     */
    public function testASiteAdminOutsideAGroupHasNoBadgeToClear(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U8');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->postBy($groupId, $creator);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'ADM2');

        $items = $this->listService->findCurrent($this->context([$stranger], 'admin'));

        $this->assertCount(1, $items, 'the admin still sees the group');
        $this->assertTrue($items[0]->isModerator);
        $this->assertFalse($items[0]->hasUnread);
    }

    /** The same admin, in a group of their own, is a reader like any other. */
    public function testASiteAdminInsideAGroupStillGetsItsBadge(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U9');
        $admin = GroupsTestHelper::createMember($this->pdo, 'ADM3');
        $groupId = $this->groupOfTwo($creator, $admin);
        $this->postBy($groupId, $creator, '2030-01-01 12:00:00');

        $this->assertTrue($this->unread($admin, 'admin'));

        $this->readRepo->markRead($groupId, $admin, '2030-01-01 12:00:01');

        $this->assertFalse($this->unread($admin, 'admin'));
    }

    /** Belonging through a section counts as belonging, for the badge as for reading. */
    public function testASiteAdminInASectionGroupThroughTheirSectionStillGetsItsBadge(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U10');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $this->postBy($groupId, $creator);
        $admin = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'ADM4', $this->sectionId, $this->currentYearId);

        $this->assertTrue($this->unread($admin, 'admin'));
    }

    /**
     * The read repository is an optional dependency (the service predates
     * it) — without one the list still renders, it just cannot know what
     * was already seen, so a group with content stays flagged. That is the
     * right way round: over-reporting "there is something new" is a
     * harmless nudge, silently under-reporting it is a missed message.
     */
    public function testWithoutAReadRepositoryAGroupWithContentStaysFlaggedRatherThanFailing(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'U7');
        $other = GroupsTestHelper::createMember($this->pdo, 'U7B');
        $groupId = $this->groupOfTwo($creator, $other);
        $this->postBy($groupId, $other);

        $service = new GroupListService(
            new GroupRepository($this->pdo),
            new GroupSectionRepository($this->pdo),
            new GroupMemberRepository($this->pdo),
            new SectionMembershipRepository($this->pdo),
            new PostRepository($this->pdo),
            new ReplyRepository($this->pdo)
        );

        $this->assertTrue($service->findCurrent($this->context([$creator]))[0]->hasUnread);
    }
}
