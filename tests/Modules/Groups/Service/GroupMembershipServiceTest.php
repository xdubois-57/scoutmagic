<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\GroupMembershipService;
use Modules\Groups\Service\LeaveOutcome;
use Modules\Groups\Service\ReopenOutcome;
use Modules\Groups\Support\Timestamps;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Leaving, reopening and the creation quota.
 *
 * @group database
 */
#[Group('database')]
class GroupMembershipServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private GroupMemberRepository $memberRepo;
    private int $currentYearId;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->memberRepo = new GroupMemberRepository($this->pdo);
        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->groupId = $this->groupRepo->create('Projet camp', $this->currentYearId, null, 1);
    }

    // ---- leaving ----

    public function testAnInvitedMemberLeavesAndTheirRowIsGone(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);
        $this->memberRepo->add($this->groupId, 4, false);

        $this->assertSame(LeaveOutcome::LEFT, $this->service()->leave($this->group(), 4, 70));
        $this->assertNull($this->memberRepo->find($this->groupId, 4));
    }

    /**
     * Derived membership follows the Desk import: there is no row to
     * delete, and deleting nothing would look like success while leaving
     * them exactly as much a member as before.
     */
    public function testADerivedSectionMemberIsRefusedRatherThanSilentlyDoingNothing(): void
    {
        $outcome = $this->service()->leave($this->group(), 9, 70);

        $this->assertSame(LeaveOutcome::DERIVED_MEMBERSHIP, $outcome);
        $this->assertStringContainsString('suit', $outcome->message());
    }

    public function testTheLastModeratorMayNotLeave(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);
        $this->memberRepo->add($this->groupId, 4, false);

        $outcome = $this->service()->leave($this->group(), 3, 70);

        $this->assertSame(LeaveOutcome::LAST_MODERATOR, $outcome);
        $this->assertNotNull($this->memberRepo->find($this->groupId, 3));
        $this->assertStringContainsString('dernier modérateur', $outcome->message());
    }

    public function testASecondModeratorMakesLeavingSucceed(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);
        $this->memberRepo->add($this->groupId, 4, true, null, 400);

        $this->assertSame(LeaveOutcome::LEFT, $this->service()->leave($this->group(), 3, 70));
        $this->assertNull($this->memberRepo->find($this->groupId, 3));
        $this->assertSame(1, $this->memberRepo->countModerators($this->groupId));
    }

    /**
     * A site admin moderates every group without holding a row, and must
     * not count: a group has to keep a moderator of its own.
     */
    public function testSiteAdminsDoNotCountTowardsTheLastModeratorCheck(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);

        // countModerators() sees explicit rows only, whoever else may be
        // an admin elsewhere on the site.
        $this->assertSame(1, $this->memberRepo->countModerators($this->groupId));
        $this->assertSame(LeaveOutcome::LAST_MODERATOR, $this->service()->leave($this->group(), 3, 70));
    }

    /**
     * Anonymising an author is a project-wide concern, explicitly not
     * this module's — a conversation with holes in it would be worse for
     * everyone still in the group.
     */
    public function testLeavingLeavesTheirPostsAndRepliesUntouched(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);
        $this->memberRepo->add($this->groupId, 4, false);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'mon message', '2026-01-01 10:00:00', 7, 4);
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'ma réponse', '2026-01-01 10:05:00', 7, 4);

        $this->service()->leave($this->group(), 4, 70);

        $this->assertSame(1, $this->rowCount('discussion_group_posts'));
        $this->assertSame(1, $this->rowCount('discussion_group_replies'));
    }

    public function testLeavingIsJournalledWithIdsOnly(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);
        $this->memberRepo->add($this->groupId, 4, false);

        $this->service()->leave($this->group(), 4, 70);

        $rows = $this->journalRows();
        $this->assertSame(['group_member_left'], array_column($rows, 'event_type'));
        $this->assertSame('info', $rows[0]['level']);
        $this->assertStringNotContainsString('Projet camp', (string) $rows[0]['context']);
    }

    public function testARefusedLeaveIsNotJournalled(): void
    {
        $this->memberRepo->add($this->groupId, 3, true, null, 300);

        $this->service()->leave($this->group(), 3, 70);

        $this->assertSame([], $this->journalRows());
    }

    // ---- reopening ----

    public function testAModeratorReopensAClosedGroup(): void
    {
        $this->groupRepo->setClosed($this->groupId, '2025-01-01 00:00:00');

        $outcome = $this->service()->reopen($this->group(), $this->currentYearId, 70);

        $this->assertSame(ReopenOutcome::REOPENED, $outcome);
        $this->assertFalse($this->group()->isClosed());
    }

    /**
     * The point of the reset: the group was almost certainly closed BY
     * the inactivity task, so reopening without moving last_activity_at
     * would have that same task close it again on its next run.
     */
    public function testReopeningResetsLastActivitySoTheInactivityTaskDoesNotReclose(): void
    {
        $this->groupRepo->touchActivity($this->groupId, '2020-01-01 00:00:00');
        $this->groupRepo->setClosed($this->groupId, '2025-01-01 00:00:00');

        $this->service()->reopen($this->group(), $this->currentYearId, 70);

        $lastActivity = $this->group()->lastActivityAt;
        $this->assertGreaterThan(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s'),
            $lastActivity
        );
    }

    public function testAPastYearGroupCannotBeReopened(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->groupRepo->create('Ancien', $pastYearId, null, 1);
        $this->groupRepo->setClosed($groupId, '2025-01-01 00:00:00');
        $group = $this->groupRepo->findById($groupId);
        \assert($group !== null);

        $outcome = $this->service()->reopen($group, $this->currentYearId, 70);

        $this->assertSame(ReopenOutcome::PAST_YEAR, $outcome);
        $this->assertTrue($this->groupRepo->findById($groupId)->isClosed());
    }

    /**
     * A year-less invitation group belongs to no year, so the past-year
     * rule cannot apply to it.
     */
    public function testAYearLessGroupIsAlwaysReopenable(): void
    {
        $groupId = $this->groupRepo->create('Groupe de travail', null, null, 1);
        $this->groupRepo->setClosed($groupId, '2025-01-01 00:00:00');
        $group = $this->groupRepo->findById($groupId);
        \assert($group !== null);

        $this->assertSame(ReopenOutcome::REOPENED, $this->service()->reopen($group, $this->currentYearId, 70));
    }

    public function testReopeningAnOpenGroupSaysSoRatherThanPretending(): void
    {
        $this->assertSame(ReopenOutcome::NOT_CLOSED, $this->service()->reopen($this->group(), $this->currentYearId, 70));
    }

    public function testReopeningIsJournalled(): void
    {
        $this->groupRepo->setClosed($this->groupId, '2025-01-01 00:00:00');

        $this->service()->reopen($this->group(), $this->currentYearId, 70);

        $rows = $this->journalRows();
        $this->assertSame(['group_reopened'], array_column($rows, 'event_type'));
        $this->assertSame('info', $rows[0]['level']);
    }

    public function testClosingByHandIsJournalledAndIdempotent(): void
    {
        $service = $this->service();
        $service->close($this->group(), 70);
        $closedAt = $this->group()->closedAt;
        $this->assertNotNull($closedAt);

        // A second close must not re-stamp the date the purge counts from.
        $service->close($this->group(), 70);
        $this->assertSame($closedAt, $this->group()->closedAt);
        $this->assertCount(1, $this->journalRows());
    }

    // ---- creation quota ----

    public function testTheDefaultQuotaIsFive(): void
    {
        $this->assertSame(5, $this->service()->creationQuota());
    }

    public function testAMemberBelowTheQuotaMayCreateAnother(): void
    {
        $this->createInvitationGroups(4, creator: 3);

        $this->assertTrue($this->service()->canCreateAnotherGroup(3));
    }

    public function testAMemberAtTheQuotaMayNot(): void
    {
        $this->createInvitationGroups(5, creator: 3);

        $this->assertFalse($this->service()->canCreateAnotherGroup(3));
    }

    /**
     * A section group is created by the scheduled task and by nobody
     * else, so counting it against a person would count something they
     * did not do.
     */
    public function testSectionGroupsDoNotCountTowardsTheQuota(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        for ($i = 0; $i < 8; $i++) {
            $this->groupRepo->create("Section {$i}", $this->currentYearId, $sectionId, 3);
        }

        $this->assertTrue($this->service()->canCreateAnotherGroup(3));
    }

    /**
     * The quota is about live clutter, not about how much somebody ever
     * created — a closed group is read-only and on its way to the purge.
     */
    public function testClosedGroupsDoNotCountTowardsTheQuota(): void
    {
        $ids = $this->createInvitationGroups(5, creator: 3);
        $this->assertFalse($this->service()->canCreateAnotherGroup(3));

        $this->groupRepo->setClosed($ids[0], Timestamps::now());

        $this->assertTrue($this->service()->canCreateAnotherGroup(3));
    }

    public function testTheQuotaIsPerMember(): void
    {
        $this->createInvitationGroups(5, creator: 3);

        $this->assertFalse($this->service()->canCreateAnotherGroup(3));
        $this->assertTrue($this->service()->canCreateAnotherGroup(4));
    }

    public function testAConfiguredQuotaIsHonoured(): void
    {
        $this->setQuota('2');
        $this->createInvitationGroups(2, creator: 3);

        $this->assertSame(2, $this->service()->creationQuota());
        $this->assertFalse($this->service()->canCreateAnotherGroup(3));
    }

    /**
     * A 0 would disable half the module by typo, so it floors at 1 —
     * unlike the reaction debounce, where 0 legitimately means "no
     * debouncing".
     */
    public function testAZeroQuotaIsFlooredAtOneRatherThanForbiddingEverything(): void
    {
        $this->setQuota('0');

        $this->assertSame(1, $this->service()->creationQuota());
        $this->assertTrue($this->service()->canCreateAnotherGroup(3));
    }

    public function testANonNumericQuotaFallsBackToTheDefault(): void
    {
        $this->setQuota('beaucoup');

        $this->assertSame(5, $this->service()->creationQuota());
    }

    // ---- fixtures ----

    private function service(): GroupMembershipService
    {
        return new GroupMembershipService(
            $this->groupRepo,
            $this->memberRepo,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function group(): DiscussionGroup
    {
        $group = $this->groupRepo->findById($this->groupId);
        \assert($group !== null);

        return $group;
    }

    /**
     * @return int[]
     */
    private function createInvitationGroups(int $count, int $creator): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->groupRepo->create("Groupe {$i}", null, null, $creator);
        }

        return $ids;
    }

    private function setQuota(string $value): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM settings WHERE module_id = ? AND setting_key = ?');
        $stmt->execute(['groups', GroupMembershipService::SETTING_CREATION_QUOTA]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['groups', GroupMembershipService::SETTING_CREATION_QUOTA, $value, 'number', 'Quota', 'Quota']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalRows(): array
    {
        return $this->pdo
            ->query("SELECT * FROM event_log WHERE category = 'groups' ORDER BY id")
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
