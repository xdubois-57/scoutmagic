<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\SectionMembershipRepository;
use Core\Security\Role;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostPermission;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class GroupAccessServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupAccessService $access;
    private GroupService $groupService;
    private GroupRepository $groupRepo;
    private GroupMemberRepository $memberRepo;
    private int $currentYearId;
    private int $pastYearId;
    private int $louveteauxId;
    private int $eclaireursId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $sectionRepo = new GroupSectionRepository($this->pdo);
        $this->memberRepo = new GroupMemberRepository($this->pdo);
        $this->access = new GroupAccessService($this->memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));
        $this->groupService = new GroupService($this->groupRepo, $sectionRepo, $this->memberRepo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $this->louveteauxId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $this->eclaireursId = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
    }

    /**
     * @param int[] $linkedMemberIds
     */
    private function context(array $linkedMemberIds, string $role = 'identified', bool $completeProfile = true, ?int $yearId = null): GroupSessionContext
    {
        return new GroupSessionContext(
            1,
            Role::fromString($role),
            $linkedMemberIds,
            $yearId ?? $this->currentYearId,
            $completeProfile
        );
    }

    private function sectionGroup(): int
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CREATOR');

        // Granted to the account the context() above identifies as: the
        // flag names a login, so a fixture that names none moderates
        // nothing (Service\GroupAccessService::canModerate()).
        return $this->groupService->createSectionGroup(
            'Louveteaux 2025-2026',
            $this->louveteauxId,
            $this->currentYearId,
            $creator,
            1
        );
    }

    // --- canRead -------------------------------------------------------

    public function testDerivedMemberOfALinkedSectionCanRead(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $this->louveteauxId, $this->currentYearId);

        $this->assertTrue($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$memberId])));
    }

    public function testExplicitlyInvitedMemberCanRead(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMember($this->pdo, 'M2');
        $this->groupService->inviteMember($this->groupRepo->findById($groupId), $memberId, 1);

        $this->assertTrue($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$memberId])));
    }

    public function testANonMemberCannotRead(): void
    {
        $groupId = $this->sectionGroup();
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $this->assertFalse($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$outsider])));
    }

    public function testAMemberOfADifferentSectionGroupCannotRead(): void
    {
        $groupId = $this->sectionGroup();
        // Belongs to a section this group is not linked to.
        $other = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M3', $this->eclaireursId, $this->currentYearId);

        $this->assertFalse($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$other])));
    }

    public function testASiteAdminCanReadAnyGroup(): void
    {
        $groupId = $this->sectionGroup();
        $stranger = GroupsTestHelper::createMember($this->pdo, 'ADMINMEMBER');

        $this->assertTrue($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$stranger], 'admin')));
    }

    public function testAChiefWhoIsNotAMemberCannotRead(): void
    {
        // Deliberately no chief bypass: only a site admin is an implicit
        // moderator, a chief is an ordinary member or nothing at all.
        $groupId = $this->sectionGroup();
        $chiefMember = GroupsTestHelper::createMember($this->pdo, 'CHIEFMEMBER');

        $this->assertFalse($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$chiefMember], 'chief')));
    }

    // --- archives ------------------------------------------------------

    public function testAPastYearGroupStaysReadableByAThenMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux 2024-2025', $this->louveteauxId, $this->pastYearId, $creator);
        $thenMember = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'THEN', $this->louveteauxId, $this->pastYearId);

        $this->assertTrue($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$thenMember])));
    }

    public function testAPastYearGroupIsInvisibleToACurrentMemberWhoWasNotThereThatYear(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux 2024-2025', $this->louveteauxId, $this->pastYearId, $creator);
        // Same section, but only from the current year onwards.
        $newcomer = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'NEW', $this->louveteauxId, $this->currentYearId);

        $this->assertFalse($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$newcomer])));
    }

    // --- canModerate ---------------------------------------------------

    public function testTheCreatorIsAModeratorThroughTheLoginTheyCreatedItWith(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4');
        $groupId = $this->groupService->createSectionGroup('G', $this->louveteauxId, $this->currentYearId, $creator, 1);

        $this->assertTrue($this->access->canModerate($this->groupRepo->findById($groupId), $this->context([$creator])));
    }

    /**
     * The rule this whole column exists for: two addresses can reach the
     * same member, and only the one the flag was granted to moderates.
     */
    public function testAnotherLoginOfTheSameMemberDoesNotModerate(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4B');
        $groupId = $this->groupService->createSectionGroup('G', $this->louveteauxId, $this->currentYearId, $creator, 1);

        $otherLogin = new GroupSessionContext(2, Role::fromString('identified'), [$creator], $this->currentYearId, true);

        $this->assertFalse($this->access->canModerate($this->groupRepo->findById($groupId), $otherLogin));
    }

    /**
     * A flag granted before it named a login moderates nothing — the
     * state Service\ModeratorBindingService exists to resolve.
     */
    public function testAFlagThatNamesNoLoginModeratesNothing(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4C');
        $groupId = $this->groupService->createSectionGroup('G', $this->louveteauxId, $this->currentYearId, $creator);

        $this->assertFalse($this->access->canModerate($this->groupRepo->findById($groupId), $this->context([$creator])));
    }

    public function testAnOrdinaryMemberIsNotAModerator(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M4', $this->louveteauxId, $this->currentYearId);

        $this->assertTrue($this->access->canRead($this->groupRepo->findById($groupId), $this->context([$memberId])));
        $this->assertFalse($this->access->canModerate($this->groupRepo->findById($groupId), $this->context([$memberId])));
    }

    public function testASiteAdminIsAnImplicitModerator(): void
    {
        $groupId = $this->sectionGroup();
        $stranger = GroupsTestHelper::createMember($this->pdo, 'ADM2');

        $this->assertTrue($this->access->canModerate($this->groupRepo->findById($groupId), $this->context([$stranger], 'admin')));
    }

    public function testAModeratorRowMayExistPurelyForTheFlagOnASectionGroup(): void
    {
        $groupId = $this->sectionGroup();
        $derived = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M5', $this->louveteauxId, $this->currentYearId);

        $this->groupService->setModerator($this->groupRepo->findById($groupId), $derived, true, 1, 1);

        $this->assertTrue($this->access->canModerate($this->groupRepo->findById($groupId), $this->context([$derived])));
    }

    // --- canPost -------------------------------------------------------

    public function testCanPostForAnOrdinaryMemberOfAnOpenCurrentYearGroup(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M6', $this->louveteauxId, $this->currentYearId);

        $this->assertTrue($this->access->canPost($this->groupRepo->findById($groupId), $this->context([$memberId]))->allowed);
    }

    public function testCanPostRefusesANonMember(): void
    {
        $groupId = $this->sectionGroup();
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT2');

        $permission = $this->access->canPost($this->groupRepo->findById($groupId), $this->context([$outsider]));
        $this->assertFalse($permission->allowed);
        $this->assertSame(PostPermission::REASON_NOT_MEMBER, $permission->reason);
    }

    public function testCanPostRefusesAClosedGroup(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M7', $this->louveteauxId, $this->currentYearId);
        $this->groupRepo->setClosed($groupId, '2026-02-01 10:00:00');

        $permission = $this->access->canPost($this->groupRepo->findById($groupId), $this->context([$memberId]));
        $this->assertFalse($permission->allowed);
        $this->assertSame(PostPermission::REASON_CLOSED, $permission->reason);
        $this->assertStringContainsString('clôturé', $permission->message);
    }

    public function testCanPostRefusesAGroupFromANonEffectiveYear(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C5');
        $groupId = $this->groupService->createSectionGroup('G', $this->louveteauxId, $this->pastYearId, $creator);
        $thenMember = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'THEN2', $this->louveteauxId, $this->pastYearId);

        $permission = $this->access->canPost($this->groupRepo->findById($groupId), $this->context([$thenMember]));
        $this->assertFalse($permission->allowed);
        $this->assertSame(PostPermission::REASON_PAST_YEAR, $permission->reason);
    }

    public function testCanPostRefusesAnIncompleteProfileAndPointsAtMonCompte(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M8', $this->louveteauxId, $this->currentYearId);

        $permission = $this->access->canPost(
            $this->groupRepo->findById($groupId),
            $this->context([$memberId], 'identified', false)
        );
        $this->assertFalse($permission->allowed);
        $this->assertSame(PostPermission::REASON_INCOMPLETE_PROFILE, $permission->reason);
        $this->assertSame('/account', $permission->actionUrl);
        $this->assertSame('Mon compte', $permission->actionLabel);
    }

    public function testAYearLessInvitationGroupIsPostableWhateverTheEffectiveYear(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C6');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);

        $this->assertTrue($this->access->canPost($this->groupRepo->findById($groupId), $this->context([$creator]))->allowed);
    }

    // --- posting policy -------------------------------------------------

    /**
     * The whole point of the policy, and the whole point of it being TWO
     * questions: an ordinary member of a moderators-only group stops
     * publishing and keeps everything else.
     */
    public function testAMemberOfAModeratorsOnlyGroupMayNotPostButMayStillTakePart(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M20', $this->louveteauxId, $this->currentYearId);
        $this->groupRepo->setPostingPolicy($groupId, DiscussionGroup::POSTING_MODERATORS);
        $group = $this->groupRepo->findById($groupId);

        $permission = $this->access->canPost($group, $this->context([$memberId]));
        $this->assertFalse($permission->allowed);
        $this->assertSame(PostPermission::REASON_MODERATORS_ONLY, $permission->reason);
        $this->assertStringContainsString('commenter', $permission->message);

        $this->assertTrue($this->access->canParticipate($group, $this->context([$memberId]))->allowed);
    }

    public function testAModeratorOfTheGroupStillPostsInAModeratorsOnlyGroup(): void
    {
        $groupId = $this->sectionGroup();
        $moderator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M21', $this->louveteauxId, $this->currentYearId);
        // Granted to the account context() identifies as — the flag names
        // a login, never a member.
        $this->memberRepo->add($groupId, $moderator, true, null, 1);
        $this->groupRepo->setPostingPolicy($groupId, DiscussionGroup::POSTING_MODERATORS);

        $this->assertTrue($this->access->canPost($this->groupRepo->findById($groupId), $this->context([$moderator]))->allowed);
    }

    /**
     * Staff d'U needs no grant anywhere: a site admin is an implicit
     * moderator of every group, so the policy never locks the unit's own
     * chiefs out of a group they have to be able to write in.
     */
    public function testStaffDUniteAlwaysPublishesInAModeratorsOnlyGroup(): void
    {
        $groupId = $this->sectionGroup();
        $chief = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M22', $this->louveteauxId, $this->currentYearId);
        $this->groupRepo->setPostingPolicy($groupId, DiscussionGroup::POSTING_MODERATORS);

        $this->assertTrue(
            $this->access->canPost($this->groupRepo->findById($groupId), $this->context([$chief], 'admin'))->allowed
        );
    }

    /**
     * The policy is the LAST thing checked, so a closed group still says
     * "clôturé" rather than blaming a restriction that is not the reason
     * this member cannot write.
     */
    public function testAClosedModeratorsOnlyGroupStillExplainsItselfAsClosed(): void
    {
        $groupId = $this->sectionGroup();
        $memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M23', $this->louveteauxId, $this->currentYearId);
        $this->groupRepo->setPostingPolicy($groupId, DiscussionGroup::POSTING_MODERATORS);
        $this->groupRepo->setClosed($groupId, '2026-02-01 10:00:00');

        $permission = $this->access->canPost($this->groupRepo->findById($groupId), $this->context([$memberId]));

        $this->assertSame(PostPermission::REASON_CLOSED, $permission->reason);
    }

    public function testAGroupPublishesToEveryMemberByDefault(): void
    {
        $groupId = $this->sectionGroup();

        $this->assertSame(DiscussionGroup::POSTING_MEMBERS, $this->groupRepo->findById($groupId)->postingPolicy);
    }

    public function testAnInventedPolicyIsStoredAsTheOpenDefault(): void
    {
        $groupId = $this->sectionGroup();

        $this->groupRepo->setPostingPolicy($groupId, 'personne');

        $this->assertSame(DiscussionGroup::POSTING_MEMBERS, $this->groupRepo->findById($groupId)->postingPolicy);
    }

    // --- posting identity ----------------------------------------------

    public function testMemberIdsAllowedToPostAsExcludesALinkedMemberWhoIsNotInTheGroup(): void
    {
        $groupId = $this->sectionGroup();
        $inGroup = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD1', $this->louveteauxId, $this->currentYearId);
        $otherSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD2', $this->eclaireursId, $this->currentYearId);

        $allowed = $this->access->memberIdsAllowedToPostAs(
            $this->groupRepo->findById($groupId),
            $this->context([$inGroup, $otherSection])
        );

        $this->assertSame([$inGroup], $allowed);
    }

    // --- answering a member-scoped poll ---------------------------------

    /**
     * The picker a "une réponse par membre" poll shows is NOT the
     * composer's: a parent of four whose four children reach this account
     * has four answers to give, whichever sections those children are in.
     * Offering two of them would quietly produce a count that is short.
     */
    public function testMemberIdsAllowedToVoteAsOffersEveryLinkedMemberNotOnlyTheGroupsOwn(): void
    {
        $groupId = $this->sectionGroup();
        $inGroup = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD1', $this->louveteauxId, $this->currentYearId);
        $otherSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD2', $this->eclaireursId, $this->currentYearId);
        $noSectionAtAll = GroupsTestHelper::createMember($this->pdo, 'CHILD3');

        $allowed = $this->access->memberIdsAllowedToVoteAs(
            $this->groupRepo->findById($groupId),
            $this->context([$otherSection, $inGroup, $noSectionAtAll])
        );

        $this->assertSame([$inGroup, $otherSection, $noSectionAtAll], $allowed);
    }

    /**
     * The group's own members come first whatever order the account
     * carries them in: the first option is the picker's default, which is
     * what a no-JavaScript submit sends and what Service\PollService
     * falls back to when the form names nobody.
     */
    public function testMemberIdsAllowedToVoteAsPutsTheGroupsOwnMembersFirst(): void
    {
        $groupId = $this->sectionGroup();
        $otherSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD1', $this->eclaireursId, $this->currentYearId);
        $invited = GroupsTestHelper::createMember($this->pdo, 'CHILD2');
        $this->memberRepo->add($groupId, $invited);

        $allowed = $this->access->memberIdsAllowedToVoteAs(
            $this->groupRepo->findById($groupId),
            $this->context([$otherSection, $invited])
        );

        $this->assertSame([$invited, $otherSection], $allowed);
    }

    /**
     * The shape the defect was reported in, kept as its own case because
     * a rule is easiest to trust against the household that found it: one
     * account reaching four active members — two animés in the group's
     * own section, one in another, and the parent's own Staff d'U
     * membership. The poll used to offer the two, and the family had four
     * answers to give.
     */
    public function testAParentOfFourIsOfferedFourAnswersInASectionGroupPoll(): void
    {
        $staffId = GroupsTestHelper::createSection($this->pdo, 'STAFF', "Staff d'U");
        $groupId = $this->sectionGroup();
        $firstInSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SV025L1-A', $this->louveteauxId, $this->currentYearId);
        $secondInSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SV025L1-B', $this->louveteauxId, $this->currentYearId);
        $otherSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SV025E1', $this->eclaireursId, $this->currentYearId);
        $theParent = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'STAFF-PARENT', $staffId, $this->currentYearId);

        $allowed = $this->access->memberIdsAllowedToVoteAs(
            $this->groupRepo->findById($groupId),
            $this->context([$firstInSection, $otherSection, $secondInSection, $theParent])
        );

        $this->assertSame([$firstInSection, $secondInSection, $otherSection, $theParent], $allowed);
    }

    /**
     * The picker has to be able to say which side each option is on, so
     * the two are resolved together rather than by asking the same
     * membership question twice from the Controller.
     */
    public function testMemberIdsAllowedToVoteAsBySideKeepsTheTwoSidesApart(): void
    {
        $groupId = $this->sectionGroup();
        $inGroup = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD1', $this->louveteauxId, $this->currentYearId);
        $otherSection = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD2', $this->eclaireursId, $this->currentYearId);

        $sides = $this->access->memberIdsAllowedToVoteAsBySide(
            $this->groupRepo->findById($groupId),
            $this->context([$otherSection, $inGroup])
        );

        $this->assertSame(['in_group' => [$inGroup], 'elsewhere' => [$otherSection]], $sides);
    }

    /**
     * An account whose members are all in the group has nothing to
     * distinguish — the picker draws no headings at all in that case
     * (partials/poll.html.twig), which is what the empty side says.
     */
    public function testMemberIdsAllowedToVoteAsBySideLeavesTheOtherSideEmptyWhenEverybodyIsHere(): void
    {
        $groupId = $this->sectionGroup();
        $first = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD1', $this->louveteauxId, $this->currentYearId);
        $second = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD2', $this->louveteauxId, $this->currentYearId);

        $sides = $this->access->memberIdsAllowedToVoteAsBySide(
            $this->groupRepo->findById($groupId),
            $this->context([$first, $second])
        );

        $this->assertSame(['in_group' => [$first, $second], 'elsewhere' => []], $sides);
    }

    /**
     * An account with a single member has nothing to pick between, and no
     * poll offers it a choice — the same list, one entry long.
     */
    public function testMemberIdsAllowedToVoteAsWithOneLinkedMemberIsThatMember(): void
    {
        $groupId = $this->sectionGroup();
        $only = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'CHILD1', $this->louveteauxId, $this->currentYearId);

        $allowed = $this->access->memberIdsAllowedToVoteAs(
            $this->groupRepo->findById($groupId),
            $this->context([$only])
        );

        $this->assertSame([$only], $allowed);
    }
}
