<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\SectionMembershipRepository;
use Core\Security\Role;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
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

        $this->listService = new GroupListService($groupRepo, $sectionRepo, $memberRepo, $membershipRepo);
        $this->groupService = new GroupService($groupRepo, $sectionRepo, $memberRepo);

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
}
