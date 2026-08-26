<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\MemberDiscussionGroupService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Which groups a member belongs to, as the admin member page shows it.
 *
 * The case that matters most is the one about what must NOT be there:
 * this hook answers "who is reachable where", never "what did they
 * write".
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberDiscussionGroupServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groups;
    private GroupMemberRepository $members;
    private MemberDiscussionGroupService $service;
    private int $memberId;
    private int $otherMemberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groups = new GroupRepository($this->pdo);
        $this->members = new GroupMemberRepository($this->pdo);
        $this->service = new MemberDiscussionGroupService($this->members, $this->groups);

        $this->scoutYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->memberId = GroupsTestHelper::createMember($this->pdo, 'D1');
        $this->otherMemberId = GroupsTestHelper::createMember($this->pdo, 'D2');
    }

    private function group(string $name): int
    {
        return $this->groups->create($name, $this->scoutYearId, null, null);
    }

    public function testAMemberSGroupsComeBackWithTheirNameAndAPath(): void
    {
        $groupId = $this->group('Staff d\'unité');
        $this->members->add($groupId, $this->memberId, false, null);

        $groups = $this->service->getDiscussionGroups($this->memberId);

        $this->assertCount(1, $groups);
        $this->assertSame('Staff d\'unité', $groups[0]->name);
        $this->assertSame('/groups/' . $groupId, $groups[0]->path);
        $this->assertFalse($groups[0]->isModerator);
        $this->assertFalse($groups[0]->isClosed);
    }

    public function testRunningAGroupIsDistinguishedFromMerelyBeingInIt(): void
    {
        $groupId = $this->group('Intendance');
        $this->members->add($groupId, $this->memberId, true, null);

        $groups = $this->service->getDiscussionGroups($this->memberId);

        $this->assertTrue($groups[0]->isModerator);
    }

    /**
     * A closed group is still part of the journey. Saying so is what
     * stops it reading as somewhere the person is reachable today.
     */
    public function testAClosedGroupIsListedAndSaysSo(): void
    {
        $groupId = $this->group('Camp 2024');
        $this->members->add($groupId, $this->memberId, false, null);
        $this->groups->setClosed($groupId, '2024-09-01 10:00:00');

        $groups = $this->service->getDiscussionGroups($this->memberId);

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->isClosed);
    }

    public function testOpenGroupsComeBeforeClosedOnes(): void
    {
        $closed = $this->group('Camp 2024');
        $open = $this->group('Staff d\'unité');
        $this->members->add($closed, $this->memberId, false, null);
        $this->members->add($open, $this->memberId, false, null);
        $this->groups->setClosed($closed, '2024-09-01 10:00:00');

        $groups = $this->service->getDiscussionGroups($this->memberId);

        $this->assertSame(['Staff d\'unité', 'Camp 2024'], array_map(fn($g) => $g->name, $groups));
    }

    public function testOneMembersGroupsNeverLeakIntoAnothers(): void
    {
        $groupId = $this->group('Staff d\'unité');
        $this->members->add($groupId, $this->memberId, false, null);

        $this->assertCount(1, $this->service->getDiscussionGroups($this->memberId));
        $this->assertSame([], $this->service->getDiscussionGroups($this->otherMemberId));
    }

    public function testBelongingToNoGroupIsAnEmptyListRatherThanAnError(): void
    {
        $this->assertSame([], $this->service->getDiscussionGroups($this->memberId));
    }

    /**
     * The whole boundary of this hook: a chef d'unité needs to know which
     * groups reach this person, and nothing about what people write to
     * each other. Nothing in the DTO can carry a message.
     */
    public function testNothingAboutWhatIsWrittenInAGroupTravelsWithTheMembership(): void
    {
        $groupId = $this->group('Staff d\'unité');
        $this->members->add($groupId, $this->memberId, false, null);

        $properties = array_keys(get_object_vars($this->service->getDiscussionGroups($this->memberId)[0]));
        sort($properties);

        $this->assertSame(['isClosed', 'isModerator', 'name', 'path'], $properties);
    }
}
