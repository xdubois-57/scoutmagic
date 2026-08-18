<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\SectionMembershipRepository;
use Core\Security\Role;
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

        return $this->groupService->createSectionGroup('Louveteaux 2025-2026', $this->louveteauxId, $this->currentYearId, $creator);
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

    public function testTheCreatorIsAModerator(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4');
        $groupId = $this->groupService->createSectionGroup('G', $this->louveteauxId, $this->currentYearId, $creator);

        $this->assertTrue($this->access->canModerate($this->groupRepo->findById($groupId), $this->context([$creator])));
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

        $this->groupService->setModerator($this->groupRepo->findById($groupId), $derived, true, 1);

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
}
