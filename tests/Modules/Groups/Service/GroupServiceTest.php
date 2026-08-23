<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The last-moderator protection on the two MODERATOR-driven paths —
 * "Retirer la modération" and "Retirer ce membre" — which used to bypass
 * the guard the voluntary departure has always had
 * (GroupMemberRepository::removeUnlessLastModerator()): a group must keep
 * a moderator of its own (ARCHITECTURE.md §8.40), whoever is doing the
 * removing.
 *
 * @group database
 */
#[Group('database')]
class GroupServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private GroupMemberRepository $memberRepo;
    private GroupService $service;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->memberRepo = new GroupMemberRepository($this->pdo);
        $this->service = new GroupService(
            $this->groupRepo,
            new GroupSectionRepository($this->pdo),
            $this->memberRepo
        );

        $yearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->groupId = $this->groupRepo->create('Projet camp', $yearId, null, 3);
        // Member 3 is the group's one bound moderator (account 300).
        $this->memberRepo->add($this->groupId, 3, true, null, 300);
    }

    private function group(): DiscussionGroup
    {
        $group = $this->groupRepo->findById($this->groupId);
        \assert($group !== null);

        return $group;
    }

    // ---- "Retirer la modération" ----

    public function testRevokingTheLastModeratorIsRefusedAndChangesNothing(): void
    {
        $this->assertFalse($this->service->setModerator($this->group(), 3, false, 1));

        $row = $this->memberRepo->find($this->groupId, 3);
        $this->assertNotNull($row);
        $this->assertTrue($row->isModerator);
        $this->assertSame(300, $row->moderatorUserAccountId);
    }

    public function testASecondModeratorMakesRevokingSucceedAndClearsBothColumns(): void
    {
        $this->memberRepo->add($this->groupId, 4, true, null, 400);

        $this->assertTrue($this->service->setModerator($this->group(), 3, false, 1));

        $row = $this->memberRepo->find($this->groupId, 3);
        $this->assertNotNull($row);
        $this->assertFalse($row->isModerator);
        $this->assertNull($row->moderatorUserAccountId);
        $this->assertSame(1, $this->memberRepo->countModerators($this->groupId));
    }

    /**
     * A flag granted before it named a login moderates nothing
     * (Service\ModeratorBindingService), so clearing it takes nothing
     * away — even when it is the only is_moderator row the group has.
     */
    public function testClearingAnUnboundModeratorFlagIsAlwaysAllowed(): void
    {
        $unboundOnlyGroup = $this->groupRepo->create('Ancien groupe', null, null, 5);
        $this->memberRepo->add($unboundOnlyGroup, 5, true, null, null);
        $group = $this->groupRepo->findById($unboundOnlyGroup);
        \assert($group !== null);

        $this->assertTrue($this->service->setModerator($group, 5, false, 1));

        $row = $this->memberRepo->find($unboundOnlyGroup, 5);
        $this->assertNotNull($row);
        $this->assertFalse($row->isModerator);
    }

    public function testGrantingStillNamesTheLogin(): void
    {
        $this->memberRepo->add($this->groupId, 4, false);

        $this->assertTrue($this->service->setModerator($this->group(), 4, true, 3, 400));

        $row = $this->memberRepo->find($this->groupId, 4);
        $this->assertNotNull($row);
        $this->assertTrue($row->isModerator);
        $this->assertSame(400, $row->moderatorUserAccountId);
    }

    // ---- "Retirer ce membre" ----

    public function testRemovingTheLastModeratorIsRefusedAndKeepsTheirRow(): void
    {
        $this->assertFalse($this->service->removeMember($this->group(), 3));

        $this->assertNotNull($this->memberRepo->find($this->groupId, 3));
        $this->assertSame(1, $this->memberRepo->countModerators($this->groupId));
    }

    public function testRemovingAnOrdinaryMemberStillWorks(): void
    {
        $this->memberRepo->add($this->groupId, 4, false);

        $this->assertTrue($this->service->removeMember($this->group(), 4));

        $this->assertNull($this->memberRepo->find($this->groupId, 4));
    }

    public function testASecondModeratorMakesRemovingAModeratorSucceed(): void
    {
        $this->memberRepo->add($this->groupId, 4, true, null, 400);

        $this->assertTrue($this->service->removeMember($this->group(), 3));

        $this->assertNull($this->memberRepo->find($this->groupId, 3));
        $this->assertSame(1, $this->memberRepo->countModerators($this->groupId));
    }
}
