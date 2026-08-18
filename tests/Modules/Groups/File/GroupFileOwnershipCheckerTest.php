<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\File;

use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class GroupFileOwnershipCheckerTest extends TestCase
{
    private \PDO $pdo;
    private GroupFileOwnershipChecker $checker;
    private GroupService $groupService;
    private GroupRepository $groupRepo;
    private int $currentYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupRepo = new GroupRepository($this->pdo);
        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));
        $this->groupService = new GroupService($this->groupRepo, $sectionRepo, $memberRepo);

        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));

        $this->checker = new GroupFileOwnershipChecker($this->groupRepo, $access, $resolver);
    }

    public function testSupportsOnlyItsOwnOwnerType(): void
    {
        $this->assertTrue($this->checker->supports('discussion_group'));
        $this->assertFalse($this->checker->supports('section_document'));
        $this->assertFalse($this->checker->supports('group'));
    }

    public function testAMemberOfTheGroupIsAllowed(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $this->sectionId, $this->currentYearId);

        $this->assertTrue($this->checker->isAllowed($groupId, Role::IDENTIFIED, [$member]));
    }

    public function testANonMemberIsDenied(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $this->assertFalse($this->checker->isAllowed($groupId, Role::IDENTIFIED, [$outsider]));
    }

    public function testAChiefWhoIsNotAMemberIsDenied(): void
    {
        // No chief bypass on group files, exactly as on the group itself.
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $chief = GroupsTestHelper::createMember($this->pdo, 'CHIEF');

        $this->assertFalse($this->checker->isAllowed($groupId, Role::CHIEF, [$chief]));
    }

    public function testASiteAdminIsAllowedAsAnImplicitModerator(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);

        $this->assertTrue($this->checker->isAllowed($groupId, Role::ADMIN, []));
    }

    public function testAnUnknownGroupIsDenied(): void
    {
        $this->assertFalse($this->checker->isAllowed(4242, Role::ADMIN, []));
    }
}
