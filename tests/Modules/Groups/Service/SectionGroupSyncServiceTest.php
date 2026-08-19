<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\SectionGroupSyncService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * One group per visible, active section per scout year — created
 * automatically, and idempotently, so the daily task and the module's own
 * page load can both call it without coordinating.
 *
 * @group database
 */
#[Group('database')]
class SectionGroupSyncServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private GroupSectionRepository $sectionRepo;
    private int $currentYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->sectionRepo = new GroupSectionRepository($this->pdo);
        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
    }

    public function testItCreatesOneGroupPerVisibleActiveSection(): void
    {
        $lou = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $ecl = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');

        $created = $this->sync()->sync($this->currentYearId);

        $this->assertSame(2, $created);
        $this->assertNotNull($this->groupRepo->findSectionGroup($lou, $this->currentYearId));
        $this->assertNotNull($this->groupRepo->findSectionGroup($ecl, $this->currentYearId));
    }

    public function testTheGroupTakesTheSectionsName(): void
    {
        $lou = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->sync()->sync($this->currentYearId);

        $this->assertSame('Louveteaux', $this->groupRepo->findSectionGroup($lou, $this->currentYearId)->name);
    }

    /**
     * The property everything else rests on: this runs nightly AND on
     * every group-list page load, so a second run must be a no-op rather
     * than a second group.
     */
    public function testRunningItAgainCreatesNothing(): void
    {
        GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $sync = $this->sync();

        $this->assertSame(1, $sync->sync($this->currentYearId));
        $this->assertSame(0, $sync->sync($this->currentYearId));
        $this->assertSame(0, $sync->sync($this->currentYearId));

        $this->assertSame(1, $this->countGroups());
    }

    /**
     * A run that died partway (say, after creating the first of three
     * sections' groups) must be safe to re-run: the next pass finds what
     * exists and finishes the rest.
     */
    public function testARunAfterAPartialFailureFinishesTheJobWithoutDuplicating(): void
    {
        $lou = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $ecl = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
        $pio = GroupsTestHelper::createSection($this->pdo, 'PIO', 'Pionniers');

        // Simulates a crash after the first section: its group exists,
        // the other two do not.
        $this->groupRepo->create('Louveteaux', $this->currentYearId, $lou, null);

        $created = $this->sync()->sync($this->currentYearId);

        $this->assertSame(2, $created);
        $this->assertSame(3, $this->countGroups());
        foreach ([$lou, $ecl, $pio] as $sectionId) {
            $this->assertNotNull($this->groupRepo->findSectionGroup($sectionId, $this->currentYearId));
        }
    }

    public function testItSkipsAHiddenSection(): void
    {
        $visible = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $hidden = GroupsTestHelper::createSection($this->pdo, 'HID', 'Cachée');
        $this->pdo->prepare('UPDATE sections SET is_visible = 0 WHERE id = ?')->execute([$hidden]);

        $this->assertSame(1, $this->sync()->sync($this->currentYearId));
        $this->assertNotNull($this->groupRepo->findSectionGroup($visible, $this->currentYearId));
        $this->assertNull($this->groupRepo->findSectionGroup($hidden, $this->currentYearId));
    }

    public function testItSkipsAnInactiveSection(): void
    {
        $active = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $inactive = GroupsTestHelper::createSection($this->pdo, 'OLD', 'Ancienne');
        $this->pdo->prepare('UPDATE sections SET is_active = 0 WHERE id = ?')->execute([$inactive]);

        $this->assertSame(1, $this->sync()->sync($this->currentYearId));
        $this->assertNull($this->groupRepo->findSectionGroup($inactive, $this->currentYearId));
    }

    /**
     * Hiding a section stops NEW groups being made for it. It never
     * deletes the conversations that already happened — that is the
     * retention purge's job and nobody else's.
     */
    public function testHidingASectionAfterwardsLeavesItsExistingGroupAlone(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $this->sync()->sync($this->currentYearId);
        $groupId = $this->groupRepo->findSectionGroup($sectionId, $this->currentYearId)->id;

        $this->pdo->prepare('UPDATE sections SET is_visible = 0 WHERE id = ?')->execute([$sectionId]);
        $this->sync()->sync($this->currentYearId);

        $this->assertNotNull($this->groupRepo->findById($groupId));
    }

    /**
     * Staff d'U is a real section with real members — unlike
     * Core\Badge\BadgeService, which skips it because a "Référent Staff
     * d'U" badge would be meaningless, a Staff d'U discussion group is
     * exactly as useful as any other.
     */
    public function testItIncludesTheStaffDuSection(): void
    {
        $staffId = GroupsTestHelper::createSection($this->pdo, UnitStaffSectionService::DESK_CODE, "Staff d'U");

        $this->sync()->sync($this->currentYearId);

        $this->assertNotNull($this->groupRepo->findSectionGroup($staffId, $this->currentYearId));
    }

    /**
     * Per section AND per year: next year's group is a different group,
     * so a chief can prepare it while this year's conversation continues.
     */
    public function testEachScoutYearGetsItsOwnGroup(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $nextYearId = GroupsTestHelper::createScoutYear($this->pdo, '2026-2027', false);

        $this->sync()->sync($this->currentYearId);
        $this->sync()->sync($nextYearId);

        $thisYear = $this->groupRepo->findSectionGroup($sectionId, $this->currentYearId);
        $nextYear = $this->groupRepo->findSectionGroup($sectionId, $nextYearId);
        $this->assertNotNull($thisYear);
        $this->assertNotNull($nextYear);
        $this->assertNotSame($thisYear->id, $nextYear->id);
    }

    /**
     * An automatic creation names no creator — and therefore adds no
     * moderator row, since GroupService::createSectionGroup() would make
     * the named creator one. Site admins moderate it until a chief grants
     * the flag.
     */
    public function testAnAutomaticallyCreatedGroupHasNoCreatorAndNoModerator(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->sync()->sync($this->currentYearId);
        $group = $this->groupRepo->findSectionGroup($sectionId, $this->currentYearId);

        $this->assertNull($group->createdByMemberId);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_members')->fetchColumn());
    }

    /**
     * The section row is what derived membership resolves through — a
     * group with no discussion_group_sections row would be a group nobody
     * is in.
     */
    public function testTheGroupIsLinkedToItsSectionSoMembershipResolves(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->sync()->sync($this->currentYearId);
        $groupId = $this->groupRepo->findSectionGroup($sectionId, $this->currentYearId)->id;

        $this->assertSame([$sectionId], $this->sectionRepo->findSectionIds($groupId));
    }

    /**
     * An invitation group that merely invited the same section must never
     * be mistaken for that section's own group — otherwise the sync would
     * see one and never create the real one.
     */
    public function testAnInvitationGroupThatInvitedTheSectionIsNotMistakenForItsGroup(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $invitationGroupId = $this->groupRepo->create('Groupe de travail', $this->currentYearId, null, 1);
        $this->sectionRepo->add($invitationGroupId, $sectionId);

        $this->assertSame(1, $this->sync()->sync($this->currentYearId));
        $this->assertNotSame($invitationGroupId, $this->groupRepo->findSectionGroup($sectionId, $this->currentYearId)->id);
    }

    public function testWithNoSectionsAtAllNothingIsCreated(): void
    {
        $this->assertSame(0, $this->sync()->sync($this->currentYearId));
    }

    private function sync(): SectionGroupSyncService
    {
        $connection = Connection::withPdo($this->pdo);

        return new SectionGroupSyncService(
            new SectionService(
                $connection,
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                new MemberBadgeRepository($this->pdo)
            ),
            $this->groupRepo,
            $this->sectionRepo
        );
    }

    private function countGroups(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_groups')->fetchColumn();
    }
}
